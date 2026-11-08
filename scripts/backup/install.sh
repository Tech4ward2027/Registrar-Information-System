#!/usr/bin/env bash
# Installs the RIS backup scripts on the production host. Run with sudo.
# Idempotent. Does NOT enable cron until you have filled in backup.env and
# run each script once by hand (README steps 6-7).
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo "run with sudo" >&2; exit 1; }

BACKUP_USER="${BACKUP_USER:-ubuntu}"
SRC="$(dirname "$(readlink -f "$0")")"

apt-get install -y --no-install-recommends gnupg rsync curl mysql-client-core-8.0 >/dev/null
command -v aws >/dev/null || echo "WARNING: aws CLI not found; install AWS CLI v2 before running backups" >&2

install -d -m 0755 /opt/ris-backup
install -m 0755 "$SRC"/lib.sh "$SRC"/ris-backup-db.sh "$SRC"/ris-backup-media.sh \
                "$SRC"/ris-backup-config.sh "$SRC"/ris-backup-check.sh /opt/ris-backup/
install -d -m 0750 -o "$BACKUP_USER" -g "$BACKUP_USER" /var/backups/ris /var/lib/ris-backup /var/log/ris
install -d -m 0750 -o "$BACKUP_USER" -g "$BACKUP_USER" /etc/ris-backup
install -d -m 0700 -o "$BACKUP_USER" -g "$BACKUP_USER" /etc/ris-backup/gnupg   # gpg requires 0700

if [[ ! -e /etc/ris-backup/backup.env ]]; then
  install -m 0600 -o "$BACKUP_USER" -g "$BACKUP_USER" "$SRC/backup.env.example" /etc/ris-backup/backup.env
  echo "Created /etc/ris-backup/backup.env - edit it before the first run."
fi

# The config backup runs as root but reads backup.env owned by $BACKUP_USER; that is fine.
install -m 0644 "$SRC/ris-backup.cron" /etc/ris-backup/ris-backup.cron.disabled
echo "Cron is NOT active yet. After a successful manual run of each script:"
echo "  sudo cp /etc/ris-backup/ris-backup.cron.disabled /etc/cron.d/ris-backup"