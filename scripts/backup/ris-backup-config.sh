#!/usr/bin/env bash
# Daily encrypted copy of configuration and secrets that cannot be rebuilt from
# git or ECR (policy: DR scope item 3 - .env.production, SSL/TLS keys).
# Schedule: 02:30 PHT = 18:30 UTC. Run as root: some paths are root-only.
#
# Redis state is deliberately NOT included: sessions and cache are
# reconstructible (Tier 3).
source "$(dirname "$(readlink -f "$0")")/lib.sh"
init config

require_cmd tar; require_cmd gzip

# Override with CONFIG_PATHS="path1 path2" in backup.env.
default_paths=(
  "$APP_ENV_FILE"
  /etc/nginx/sites-available
  /etc/letsencrypt
  /etc/ssh/sshd_config.d
  /etc/fail2ban/jail.local
  /etc/cron.d/ris-backup
  /etc/ris-backup/backup.env
)
if [[ -n "${CONFIG_PATHS:-}" ]]; then read -r -a paths <<< "$CONFIG_PATHS"; else paths=("${default_paths[@]}"); fi

existing=()
for p in "${paths[@]}"; do
  if [[ -e "$p" ]]; then existing+=("$p"); else log WARN "config path not found, skipping: $p"; fi
done
(( ${#existing[@]} > 0 )) || die "none of the configured paths exist"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
payload="$WORKDIR/ris-config-$stamp.tar.gz"
final="$BACKUP_ROOT/config/ris-config-$stamp.tar.gz.gpg"

# Record the ownership/mode listing and installed package set next to the files.
manifest="$WORKDIR/MANIFEST.txt"
{
  echo "host: $(hostname)  created: $(ts)"
  echo "docker images:"; docker images --format '  {{.Repository}}:{{.Tag}} {{.ID}}' 2>/dev/null || true
  echo "paths:"; printf '  %s\n' "${existing[@]}"
} > "$manifest"

tar -czf "$payload" -P --warning=no-file-changed "${existing[@]}" -C "$WORKDIR" MANIFEST.txt
gzip -t "$payload" || die "gzip integrity test failed"

payload_sha="$(sha256_of "$payload")"
encrypt_file "$payload" "$final"
enc_sha="$(sha256_of "$final")"
rm -f "$payload"

finish_backup config "$final" "$payload_sha" "$enc_sha"
