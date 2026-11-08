#!/usr/bin/env bash
# Restore drill: proves a backup can be decrypted, verified and loaded, and times
# it against the 90-minute RTO target (DR policy 3.1/3.4/3.5; evidence EV-BCDR-03).
#
# Run this on the DRILL machine (Hostinger staging or a scratch server), NOT on
# production. It needs: gpg with the PRIVATE key imported, docker, mysql client,
# and (optionally) aws CLI with read access to the vault bucket.
#
# What it does, with a timestamp per step:
#   1 fetch backup        (local file, or newest db object from the S3 vault)
#   2 verify checksums    encrypted SHA-256 AND decrypted payload SHA-256 vs ledger
#   3 decrypt + gzip test
#   4 start throwaway MySQL 8.0 container (bound to 127.0.0.1 only)
#   5 load dump
#   6 sanity counts       (tables, audit_logs rows)
#   7 app checks          php artisan audit:verify + break-glass:test (if RESTORE_APP_IMAGE set)
#   8 cleanup             container removed, decrypted files wiped
# Writes drill-reports/drill-<utc>.txt, the artifact to attach as EV-BCDR-03.
#
# THIS LOADS REAL STUDENT DATA. The machine must be locked down (SSH keys only,
# no public app access) and wiped afterwards. The guard flag below makes that a
# deliberate act.
set -Eeuo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
usage: ris-restore-drill.sh --ledger FILE (--file BACKUP.gpg | --from-s3) --i-understand-this-loads-real-student-data [--keep]
  --ledger FILE   copy of ledger.log (from the vault: <prefix>/ledger/)
  --file PATH     local encrypted dump (ris-db-*.sql.gz.gpg)
  --from-s3       fetch the newest db object from s3://$S3_BUCKET/$S3_PREFIX/db/ (needs aws CLI)
  --keep          keep the throwaway container running afterwards for inspection
env: RESTORE_APP_IMAGE (ECR image ref to run artisan checks), RESTORE_APP_KEY,
     GNUPGHOME, S3_BUCKET, S3_PREFIX, AWS_REGION, REPORT_DIR, TARGET_SECONDS (default 5400)
EOF
  exit 64
}

LEDGER=""; FILE=""; FROM_S3=0; KEEP=0; ACK=0
while (( $# )); do
  case "$1" in
    --ledger) LEDGER="$2"; shift 2;;
    --file) FILE="$2"; shift 2;;
    --from-s3) FROM_S3=1; shift;;
    --keep) KEEP=1; shift;;
    --i-understand-this-loads-real-student-data) ACK=1; shift;;
    *) usage;;
  esac
done
[[ -n "$LEDGER" && -r "$LEDGER" ]] || usage
(( ACK )) || { echo "refusing to run without --i-understand-this-loads-real-student-data" >&2; usage; }
[[ -n "$FILE" ]] || (( FROM_S3 )) || usage

: "${S3_PREFIX:=ris}"; : "${AWS_REGION:=ap-southeast-1}"
: "${TARGET_SECONDS:=5400}"; : "${REPORT_DIR:=./drill-reports}"
[[ -n "${GNUPGHOME:-}" ]] && export GNUPGHOME
for c in gpg gzip docker sha256sum; do command -v "$c" >/dev/null || { echo "missing command: $c" >&2; exit 1; }; done

WORK="$(mktemp -d)"
CONTAINER="ris-restore-drill-$$"
mkdir -p "$REPORT_DIR"
REPORT="$REPORT_DIR/drill-$(date -u +%Y%m%dT%H%M%SZ).txt"
START=$(date +%s); LAST=$START; RESULT=FAIL

pht() { TZ=Asia/Manila date -d "@$1" +'%Y-%m-%d %H:%M:%S PHT'; }
say() { echo "$*" | tee -a "$REPORT"; }
step() {  # step <name>: record elapsed since previous step
  local now; now=$(date +%s)
  say "$(printf '%-34s +%4ss  (total %5ss)  %s' "$1" "$((now - LAST))" "$((now - START))" "$(date -u +%H:%M:%SZ)")"
  LAST=$now
}
cleanup() {
  local rc=$?
  # -v also removes the anonymous /var/lib/mysql volume the mysql image creates;
  # without it the restored student data would stay on disk after the drill.
  if (( KEEP == 0 )); then docker rm -f -v "$CONTAINER" >/dev/null 2>&1 || true; fi
  command -v shred >/dev/null && find "$WORK" -type f -exec shred -u {} + 2>/dev/null || true
  rm -rf "$WORK"
  local total within="NO" removed="no (--keep)"
  total=$(( $(date +%s) - START ))
  if [[ "$RESULT" == PASS ]] && (( total <= TARGET_SECONDS )); then within="YES"; fi
  (( KEEP == 0 )) && removed="yes"
  say ""; say "RESULT: $RESULT   total ${total}s   target ${TARGET_SECONDS}s   within target: $within"
  [[ "$RESULT" == PASS ]] || say "(exit code $rc; drill did not complete)"
  say "Decrypted data wiped from $WORK. Container removed: $removed."
  say "Report: $REPORT"
  exit "$rc"
}
trap cleanup EXIT

{
  echo "RIS RESTORE DRILL REPORT"
  echo "Host:        $(hostname)"
  echo "Started:     $(date -u +%Y-%m-%dT%H:%M:%SZ) UTC / $(pht "$START")"
  echo "Environment: ${DRILL_ENV_LABEL:-UNLABELLED - set DRILL_ENV_LABEL (e.g. 'Hostinger staging, 4 GB RAM')}"
  echo "Target RTO:  ${TARGET_SECONDS}s (policy: 90 minutes)"
  echo "----------------------------------------------------------------"
} | tee "$REPORT"

# 1 ---- fetch
if (( FROM_S3 )); then
  command -v aws >/dev/null || { echo "aws CLI needed for --from-s3" >&2; exit 1; }
  : "${S3_BUCKET:?S3_BUCKET required}"
  key="$(aws s3api list-objects-v2 --bucket "$S3_BUCKET" --prefix "$S3_PREFIX/db/" --region "$AWS_REGION" \
         --query 'sort_by(Contents,&LastModified)[-1].Key' --output text)"
  [[ -n "$key" && "$key" != None ]] || { echo "no db backups found in vault" >&2; exit 1; }
  FILE="$WORK/$(basename "$key")"
  aws s3 cp "s3://$S3_BUCKET/$key" "$FILE" --region "$AWS_REGION" --only-show-errors
fi
[[ -r "$FILE" ]] || { echo "backup file not readable: $FILE" >&2; exit 1; }
name="$(basename "$FILE")"
say "backup: $name ($(stat -c %s "$FILE") bytes)"
step "1 fetch backup"

# 2 ---- ledger + encrypted checksum
line="$(awk -F'\t' -v n="$name" '$3==n {l=$0} END{print l}' "$LEDGER")"
[[ -n "$line" ]] || { say "FAIL: $name has no ledger entry"; exit 1; }
want_payload="$(cut -f4 <<<"$line")"; want_enc="$(cut -f5 <<<"$line")"
got_enc="$(sha256sum "$FILE" | cut -d' ' -f1)"
[[ "$got_enc" == "$want_enc" ]] || { say "FAIL: encrypted file SHA-256 does not match ledger (corrupt or tampered)"; exit 1; }
say "encrypted SHA-256 matches ledger: $got_enc"
step "2a ledger checksum (encrypted)"

# 3 ---- decrypt + payload checksum + gzip test
gpg --batch --yes --decrypt --output "$WORK/dump.sql.gz" "$FILE" 2>>"$REPORT" \
  || { say "FAIL: GPG decryption failed (wrong/missing private key, or byte-rot)"; exit 1; }
got_payload="$(sha256sum "$WORK/dump.sql.gz" | cut -d' ' -f1)"
[[ "$got_payload" == "$want_payload" ]] || { say "FAIL: decrypted payload SHA-256 does not match ledger"; exit 1; }
gzip -t "$WORK/dump.sql.gz" || { say "FAIL: gzip test"; exit 1; }
say "decrypted payload SHA-256 matches ledger: $got_payload"
step "2b/3 decrypt + payload checksum"

# 4 ---- throwaway MySQL
ROOTPW="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
docker run -d --name "$CONTAINER" -e MYSQL_ROOT_PASSWORD="$ROOTPW" -e MYSQL_DATABASE=ris_restore \
  -p 127.0.0.1::3306 mysql:8.0 --character-set-server=utf8mb4 --max-allowed-packet=256M >/dev/null
PORT="$(docker port "$CONTAINER" 3306/tcp | head -n1 | sed 's/.*://')"
for i in $(seq 1 90); do
  docker exec "$CONTAINER" mysqladmin ping -uroot -p"$ROOTPW" --silent >/dev/null 2>&1 && break
  sleep 2; [[ $i -eq 90 ]] && { say "FAIL: MySQL did not become ready"; exit 1; }
done
step "4 start MySQL 8.0 (127.0.0.1:$PORT)"

# 5 ---- load
SQLERR="$WORK/sql.err"
gzip -dc "$WORK/dump.sql.gz" | docker exec -i "$CONTAINER" mysql -uroot -p"$ROOTPW" ris_restore 2>"$SQLERR" || true
unhandled="$(grep -v -i 'Using a password on the command line' "$SQLERR" | grep -c . || true)"
say "SQL errors during load: $unhandled (policy requires 0)"
[[ "$unhandled" == 0 ]] || { say "FAIL: SQL errors:"; head -n 20 "$SQLERR" | tee -a "$REPORT"; exit 1; }
step "5 load dump"

# 6 ---- sanity
q() { docker exec "$CONTAINER" mysql -uroot -p"$ROOTPW" -N -B ris_restore -e "$1" 2>/dev/null; }
tables="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ris_restore'")"
audit_rows="$(q "SELECT COUNT(*) FROM audit_logs")"
say "tables restored: $tables   audit_logs rows: $audit_rows"
[[ "$tables" -gt 0 && "$audit_rows" -gt 0 ]] || { say "FAIL: restored database looks empty"; exit 1; }
step "6 sanity counts"

# 7 ---- application checks
if [[ -n "${RESTORE_APP_IMAGE:-}" ]]; then
  : "${RESTORE_APP_KEY:?RESTORE_APP_KEY required to run artisan}"
  run_artisan() {
    docker run --rm --network host \
      -e APP_ENV=production -e APP_KEY="$RESTORE_APP_KEY" -e DB_CONNECTION=mysql \
      -e DB_HOST=127.0.0.1 -e DB_PORT="$PORT" -e DB_DATABASE=ris_restore \
      -e DB_USERNAME=root -e DB_PASSWORD="$ROOTPW" \
      -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=log \
      "$RESTORE_APP_IMAGE" php artisan "$@" 2>&1 | tee -a "$REPORT"
  }
  run_artisan audit:verify;     [[ ${PIPESTATUS[0]} -eq 0 ]] || { say "FAIL: audit:verify"; exit 1; }
  step "7a audit:verify"
  run_artisan break-glass:test; [[ ${PIPESTATUS[0]} -eq 0 ]] || { say "FAIL: break-glass:test"; exit 1; }
  step "7b break-glass:test"
else
  say "SKIPPED: audit:verify and break-glass:test (set RESTORE_APP_IMAGE and RESTORE_APP_KEY)"
  say "         The DR policy requires both after every restore; the drill is NOT complete without them."
fi

RESULT=PASS