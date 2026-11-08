#!/usr/bin/env bash
# Daily full logical dump of the production database (policy: DR 3.3, BCDR 3.4).
# Schedule: 01:00 PHT = 17:00 UTC. Encrypted (AES-256 GPG), checksummed, shipped offsite.
#
# Pipeline: mysqldump --single-transaction | gzip -> sha256 -> gpg -> ledger -> S3 (+Hostinger)
# Safe to run on a live system: --single-transaction takes a consistent InnoDB
# snapshot without locking application writes.
source "$(dirname "$(readlink -f "$0")")/lib.sh"
init db

require_cmd mysqldump; require_cmd gzip
DB_HOST_="${BACKUP_DB_HOST:-$(env_get DB_HOST "$APP_ENV_FILE" || true)}"
DB_PORT_="${BACKUP_DB_PORT:-$(env_get DB_PORT "$APP_ENV_FILE" || true)}"; DB_PORT_="${DB_PORT_:-3306}"
DB_NAME_="${BACKUP_DB_NAME:-$(env_get DB_DATABASE "$APP_ENV_FILE" || true)}"
DB_USER_="${BACKUP_DB_USER:-$(env_get DB_USERNAME "$APP_ENV_FILE" || true)}"
DB_PASS_="${BACKUP_DB_PASSWORD:-$(env_get DB_PASSWORD "$APP_ENV_FILE" || true)}"
for v in DB_HOST_ DB_NAME_ DB_USER_ DB_PASS_; do [[ -n "${!v}" ]] || die "database setting $v could not be resolved"; done

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
payload="$WORKDIR/ris-db-$stamp.sql.gz"
final="$BACKUP_ROOT/db/ris-db-$stamp.sql.gz.gpg"

# Credentials go in a 0600 option file, never on the command line (visible in `ps`).
cnf="$WORKDIR/client.cnf"
printf '[client]\nhost="%s"\nport=%s\nuser="%s"\npassword="%s"\n' \
  "$DB_HOST_" "$DB_PORT_" "$DB_USER_" "$(cnf_escape "$DB_PASS_")" > "$cnf"

log INFO "dumping $DB_NAME_ from $DB_HOST_"
mysqldump --defaults-extra-file="$cnf" \
  --single-transaction --quick --routines --triggers --events --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 \
  --ssl-mode="${DB_SSL_MODE:-REQUIRED}" \
  "$DB_NAME_" | gzip -9 > "$payload"

# Integrity gates before the dump is trusted.
gzip -t "$payload" || die "gzip integrity test failed"
size="$(stat -c %s "$payload")"
(( size >= ${MIN_DB_DUMP_BYTES:-10240} )) || die "dump suspiciously small ($size bytes)"
gzip -dc "$payload" | tail -n 5 | grep -q -- '-- Dump completed' || die "dump has no completion marker (truncated?)"

payload_sha="$(sha256_of "$payload")"
encrypt_file "$payload" "$final"
enc_sha="$(sha256_of "$final")"
rm -f "$payload" "$cnf"

finish_backup db "$final" "$payload_sha" "$enc_sha"
