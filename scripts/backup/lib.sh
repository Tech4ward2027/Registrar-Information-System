#!/usr/bin/env bash
# RIS backup helpers. Source this file; do not execute it.
#
# Conventions
#   * All scripts log to $LOG_FILE (default /var/log/ris/backup.log) in UTC with
#     the Philippine time (PHT, UTC+8) beside it, because the host runs in UTC
#     but the policy schedules are written in PHT.
#   * Every successful backup appends one tamper-evident line to the ledger
#     (see ledger_append). The ledger is what the SHA-256 safeguard in the DR
#     policy (3.4) is checked against at restore time.
#   * Backups are encrypted on the host before they leave it. With
#     GPG_RECIPIENT set (recommended) the host holds only the PUBLIC key, so a
#     compromised server cannot decrypt its own backups.

set -Eeuo pipefail
umask 077

RIS_BACKUP_CONF="${RIS_BACKUP_CONF:-/etc/ris-backup/backup.env}"
if [[ -r "$RIS_BACKUP_CONF" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$RIS_BACKUP_CONF"
  set +a
fi

: "${BACKUP_ROOT:=/var/backups/ris}"
: "${STATE_DIR:=/var/lib/ris-backup}"
: "${LOG_FILE:=/var/log/ris/backup.log}"
: "${LEDGER_FILE:=$STATE_DIR/ledger.log}"
: "${LOCAL_RETENTION_DAYS:=3}"
: "${S3_PREFIX:=ris}"
: "${AWS_REGION:=ap-southeast-1}"
: "${APP_ENV_FILE:=/home/ubuntu/Registrar-Information-System/.env}"

TYPE="${TYPE:-unknown}"
WORKDIR=""
LOCK_FD=""
FAILURES=0

# ---------------------------------------------------------------- logging
ts() { printf '%s (%s PHT)' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(TZ=Asia/Manila date +'%Y-%m-%d %H:%M:%S')"; }

log() {
  local level="$1"; shift
  local line
  line="$(ts) [$TYPE] $level $*"
  echo "$line" >&2
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  echo "$line" >> "$LOG_FILE" 2>/dev/null || true
}

die() { log ERROR "$*"; exit 1; }

alert() {
  local msg
  msg="[RIS backup] $(hostname): $*"
  log ALERT "$*"
  if [[ -n "${ALERT_WEBHOOK_URL:-}" ]]; then
    # Works with Discord ("content") and Slack/Teams-style ("text") webhooks.
    local body
    body=$(printf '{"content":"%s","text":"%s"}' "${msg//\"/\'}" "${msg//\"/\'}")
    curl -fsS -m 15 -H 'Content-Type: application/json' -d "$body" "$ALERT_WEBHOOK_URL" >/dev/null 2>&1 \
      || log WARN "alert webhook delivery failed"
  fi
}

# ---------------------------------------------------------------- lifecycle
on_exit() {
  local rc=$?
  [[ -n "$WORKDIR" && -d "$WORKDIR" ]] && rm -rf "$WORKDIR"
  if (( rc != 0 )); then
    alert "$TYPE backup FAILED (exit $rc). See $LOG_FILE"
  fi
  exit "$rc"
}

# init <type>: sets TYPE, creates dirs, takes an exclusive lock, creates WORKDIR.
init() {
  TYPE="$1"
  mkdir -p "$BACKUP_ROOT/$TYPE" "$STATE_DIR" "$(dirname "$LOG_FILE")"
  exec {LOCK_FD}>"$STATE_DIR/$TYPE.lock"
  flock -n "$LOCK_FD" || { log WARN "previous $TYPE backup still running; skipping"; exit 0; }
  WORKDIR="$(mktemp -d "$BACKUP_ROOT/.work-$TYPE.XXXXXX")"
  trap on_exit EXIT
  log INFO "start"
}

require_cmd() { command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"; }

require_var() {
  local v
  for v in "$@"; do [[ -n "${!v:-}" ]] || die "required setting $v is empty (see $RIS_BACKUP_CONF)"; done
}

# env_get <KEY> <file>: read a KEY=value line from a dotenv file without sourcing it.
env_get() {
  local key="$1" file="$2" val
  [[ -r "$file" ]] || return 1
  val="$(grep -E "^${key}=" "$file" | tail -n1 | cut -d= -f2- || true)"
  val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
  printf '%s' "$val"
}

cnf_escape() { local s="${1//\\/\\\\}"; printf '%s' "${s//\"/\\\"}"; }

# ---------------------------------------------------------------- crypto
sha256_of() { sha256sum "$1" | cut -d' ' -f1; }

# encrypt_file <in> <out>
encrypt_file() {
  local in="$1" out="$2"
  require_cmd gpg
  [[ -n "${GNUPGHOME:-}" ]] && export GNUPGHOME
  if [[ -n "${GPG_RECIPIENT:-}" ]]; then
    gpg --batch --yes --trust-model always --recipient "$GPG_RECIPIENT" \
        --cipher-algo AES256 --compress-algo none --output "$out" --encrypt "$in"
  elif [[ -n "${GPG_PASSPHRASE_FILE:-}" && -r "${GPG_PASSPHRASE_FILE:-}" ]]; then
    gpg --batch --yes --pinentry-mode loopback --passphrase-file "$GPG_PASSPHRASE_FILE" \
        --symmetric --cipher-algo AES256 --s2k-mode 3 --s2k-digest-algo SHA512 \
        --s2k-count 65011712 --compress-algo none --output "$out" "$in"
  else
    die "no encryption configured: set GPG_RECIPIENT (preferred) or GPG_PASSPHRASE_FILE"
  fi
  [[ -s "$out" ]] || die "encryption produced an empty file: $out"
}

# ---------------------------------------------------------------- ledger
# One tab-separated line per backup:
#   utc_time  type  filename  payload_sha256  encrypted_sha256  bytes  prev_line_sha256
# payload_sha256   = SHA-256 of the data BEFORE encryption (what restore must reproduce)
# prev_line_sha256 = SHA-256 of the previous ledger line, so edits/deletions are detectable
ledger_append() {
  local type="$1" file="$2" payload_sha="$3" enc_sha="$4" bytes="$5" prev
  mkdir -p "$(dirname "$LEDGER_FILE")"
  touch "$LEDGER_FILE"
  prev="$(tail -n1 "$LEDGER_FILE" | sha256sum | cut -d' ' -f1)"
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$type" "$(basename "$file")" \
    "$payload_sha" "$enc_sha" "$bytes" "$prev" >> "$LEDGER_FILE"
}

# ---------------------------------------------------------------- offsite
s3_key_for() { printf '%s/%s/%s/%s' "$S3_PREFIX" "$1" "$(date -u +%Y/%m)" "$(basename "$2")"; }

ship_s3() {
  local type="$1" file="$2" key size remote
  require_cmd aws
  require_var S3_BUCKET
  key="$(s3_key_for "$type" "$file")"
  aws s3 cp "$file" "s3://$S3_BUCKET/$key" --region "$AWS_REGION" --only-show-errors
  size="$(stat -c %s "$file")"
  remote="$(aws s3api head-object --bucket "$S3_BUCKET" --key "$key" --region "$AWS_REGION" \
            --query ContentLength --output text)"
  [[ "$remote" == "$size" ]] || { log ERROR "S3 size mismatch for $key (local $size, remote $remote)"; return 1; }
  log INFO "offsite S3 ok: s3://$S3_BUCKET/$key ($size bytes)"
}

ship_hostinger() {
  local type="$1" file="$2" remote_dir remote_file local_sha remote_sha
  [[ -n "${HOSTINGER_HOST:-}" ]] || return 0   # optional second-provider copy
  require_var HOSTINGER_USER HOSTINGER_DIR HOSTINGER_SSH_KEY
  require_cmd ssh; require_cmd rsync
  local ssh_cmd="ssh -i $HOSTINGER_SSH_KEY -p ${HOSTINGER_PORT:-22} -o BatchMode=yes -o StrictHostKeyChecking=yes"
  remote_dir="$HOSTINGER_DIR/$type"
  remote_file="$remote_dir/$(basename "$file")"
  $ssh_cmd "$HOSTINGER_USER@$HOSTINGER_HOST" "mkdir -p '$remote_dir'"
  rsync -a -e "$ssh_cmd" "$file" "$HOSTINGER_USER@$HOSTINGER_HOST:$remote_dir/"
  local_sha="$(sha256_of "$file")"
  remote_sha="$($ssh_cmd "$HOSTINGER_USER@$HOSTINGER_HOST" "sha256sum '$remote_file'" | cut -d' ' -f1)"
  [[ "$local_sha" == "$remote_sha" ]] || { log ERROR "Hostinger checksum mismatch for $remote_file"; return 1; }
  log INFO "offsite Hostinger ok: $HOSTINGER_HOST:$remote_file"
}

# ship_offsite <type> <encrypted_file>: S3 vault + optional Hostinger copy + ledger.
# A failed destination is counted and reported, but the others are still attempted.
ship_offsite() {
  local type="$1" file="$2"
  ship_s3 "$type" "$file"        || FAILURES=$((FAILURES + 1))
  ship_hostinger "$type" "$file" || FAILURES=$((FAILURES + 1))
}

ship_ledger() {
  [[ -s "$LEDGER_FILE" ]] || return 0
  if [[ -n "${S3_BUCKET:-}" ]]; then
    aws s3 cp "$LEDGER_FILE" "s3://$S3_BUCKET/$S3_PREFIX/ledger/ledger-$(date -u +%Y%m%dT%H%M%SZ).log" \
      --region "$AWS_REGION" --only-show-errors || FAILURES=$((FAILURES + 1))
  fi
}

# ---------------------------------------------------------------- finish
prune_local() {
  local type="$1"
  find "$BACKUP_ROOT/$type" -maxdepth 1 -type f -name 'ris-*' -mtime "+$LOCAL_RETENTION_DAYS" -print -delete \
    | sed "s|^|$(ts) [$type] INFO pruned local |" >> "$LOG_FILE" || true
}

# finish_backup <type> <encrypted_file> <payload_sha> <enc_sha>
finish_backup() {
  local type="$1" file="$2" payload_sha="$3" enc_sha="$4" bytes
  bytes="$(stat -c %s "$file")"
  ledger_append "$type" "$file" "$payload_sha" "$enc_sha" "$bytes"
  ship_offsite "$type" "$file"
  ship_ledger
  prune_local "$type"
  if (( FAILURES > 0 )); then
    log ERROR "$type backup stored locally but $FAILURES offsite step(s) failed"
    exit 2
  fi
  date -u +%s > "$STATE_DIR/last_success_$type"
  log INFO "done: $(basename "$file") payload_sha256=$payload_sha bytes=$bytes"
}
