#!/usr/bin/env bash
# RIS maintenance mode switch for the host nginx (DR policy 3.5 phase 1,
# BCDR 3.8 stage 1: "DNS / ingress shifted to static maintenance page").
#
#   sudo ris-maintenance.sh install        one-time: copy page + nginx files, run nginx -t
#   sudo ris-maintenance.sh on  "reason"   serve the 503 maintenance page
#   sudo ris-maintenance.sh off            reopen
#   ris-maintenance.sh status              show state (no root needed)
#
# Every change is appended to /var/log/ris/maintenance.log (UTC and PHT), which
# is evidence for EV-BCDR-08 together with a screenshot of the page.
set -Eeuo pipefail
umask 022

CONF_DIR=/etc/ris-maintenance
FLAG="$CONF_DIR/ENABLED"
WEB_DIR=/var/www/ris-maintenance
LOG=/var/log/ris/maintenance.log
SRC="$(dirname "$(readlink -f "$0")")"

ts() { printf '%s (%s PHT)' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(TZ=Asia/Manila date +'%Y-%m-%d %H:%M:%S')"; }
log() { mkdir -p "$(dirname "$LOG")"; printf '%s %s\n' "$(ts)" "$*" >> "$LOG"; }
die() { echo "error: $*" >&2; exit 1; }
need_root() { [[ $EUID -eq 0 ]] || die "run with sudo"; }

snippet_active() {
  # Is the snippet actually included by the running nginx configuration?
  nginx -T 2>/dev/null | grep -q 'ris-maintenance.conf'
}

cmd_install() {
  need_root
  command -v nginx >/dev/null || die "nginx not found"
  install -d -m 0755 "$CONF_DIR" "$WEB_DIR" /etc/nginx/snippets
  install -m 0644 "$SRC/maintenance.html" "$WEB_DIR/maintenance.html"
  install -m 0644 "$SRC/ris-maintenance.http.conf" /etc/nginx/conf.d/ris-maintenance.conf
  install -m 0644 "$SRC/ris-maintenance.server.conf" /etc/nginx/snippets/ris-maintenance.conf
  # Local checks must still work while maintenance is on.
  [[ -e "$CONF_DIR/allow.conf" ]] || printf '127.0.0.1 1;\n' > "$CONF_DIR/allow.conf"
  nginx -t
  cat <<EOF

Installed. Two manual steps remain (host nginx is not under version control, so
record both in the Deployment Documentation):
  1. Add this line at the top of each public server block, before any location
     (production: /etc/nginx/sites-available/ris-api and ris-backend):
         include snippets/ris-maintenance.conf;
  2. sudo nginx -t && sudo systemctl reload nginx

Then test: '$0 on "test"', check from an address that is NOT in
$CONF_DIR/allow.conf (expect HTTP 503), and '$0 off'.
EOF
  log "install by ${SUDO_USER:-root}"
}

cmd_on() {
  need_root
  [[ -r "$WEB_DIR/maintenance.html" ]] || die "not installed; run '$0 install' first"
  snippet_active || die "snippets/ris-maintenance.conf is not included by any nginx server block; turning it on would do nothing"
  local reason="${1:-unspecified}"
  reason="${reason//[$'\n\r\t']/ }"
  printf 'enabled_at=%s\nenabled_by=%s\nreason=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${SUDO_USER:-root}" "$reason" > "$FLAG"
  log "ON  by ${SUDO_USER:-root}: $reason"
  echo "Maintenance mode ON. Confirm from an address that is not allowlisted:  curl -sI https://<your-host>/ | head -1"
}

cmd_off() {
  need_root
  if [[ -e "$FLAG" ]]; then
    rm -f "$FLAG"
    log "OFF by ${SUDO_USER:-root}"
    echo "Maintenance mode OFF."
  else
    echo "Maintenance mode was already off."
  fi
}

cmd_status() {
  if [[ -e "$FLAG" ]]; then
    echo "Maintenance mode: ON"; cat "$FLAG"
  else
    echo "Maintenance mode: off"
  fi
}

case "${1:-}" in
  install) cmd_install ;;
  on)      shift; cmd_on "${1:-}" ;;
  off)     cmd_off ;;
  status)  cmd_status ;;
  *) echo "usage: $0 {install|on [reason]|off|status}" >&2; exit 64 ;;
esac
