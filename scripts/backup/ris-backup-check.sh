#!/usr/bin/env bash
# Dead-man check: alerts if a backup type has not succeeded recently.
# Catches the failure mode where cron silently stops running (a failing script
# alerts by itself; a script that never starts does not).
# Exit code 0 = all fresh, 1 = at least one stale/missing. Also prints a table
# suitable for an evidence screenshot.
source "$(dirname "$(readlink -f "$0")")/lib.sh"
TYPE=check
trap - EXIT

declare -A max_age_hours=( [db]="${MAX_AGE_DB_H:-26}" [media]="${MAX_AGE_MEDIA_H:-26}" [config]="${MAX_AGE_CONFIG_H:-26}" )
now="$(date -u +%s)"; stale=0

printf '%-8s %-26s %-10s %s\n' TYPE "LAST SUCCESS (UTC)" AGE_H STATUS
for t in db media config; do
  f="$STATE_DIR/last_success_$t"
  if [[ -r "$f" ]]; then
    last="$(cat "$f")"; age_h=$(( (now - last) / 3600 ))
    when="$(date -u -d "@$last" +%Y-%m-%dT%H:%M:%SZ)"
    if (( age_h > max_age_hours[$t] )); then status="STALE (> ${max_age_hours[$t]}h)"; stale=1; else status=OK; fi
  else
    when="never"; age_h="-"; status="MISSING"; stale=1
  fi
  printf '%-8s %-26s %-10s %s\n' "$t" "$when" "$age_h" "$status"
done

if (( stale )); then
  alert "backup freshness check FAILED on $(hostname); run ris-backup-check.sh for details"
  exit 1
fi
log INFO "freshness check ok"
