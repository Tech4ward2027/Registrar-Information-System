#!/usr/bin/env bash
#
# Backend action item 9.11 — can a caller evade per-IP rate limits by forging
# X-Forwarded-For?
#
# Sends N requests to a public, throttled GET endpoint (60 requests/minute per
# IP), each with a DIFFERENT forged X-Forwarded-For value, from this one machine.
#
#   * If the limiter keys on the real client IP, the forged values are ignored,
#     the shared bucket fills up and you see HTTP 429 after ~60 requests.
#   * If the app trusts the forged header, every request looks like a new
#     client, no bucket ever fills, and you see zero 429s: limits can be bypassed.
#
# Run it against STAGING and through the same hostname real users use (so any
# CloudFront / host-nginx hops are included). It is read-only (GET) but it does
# exhaust that endpoint's rate limit for your IP for about a minute.
#
# Usage:  scripts/verify-xff-trust.sh https://staging.example.com [requests]
#         CURL_AUTH='user:pass' scripts/verify-xff-trust.sh https://...   # Basic Auth
set -euo pipefail

BASE_URL="${1:?Usage: $0 https://host [requests=80]}"
N="${2:-80}"
URL="${BASE_URL%/}/api/v1/business-hours/status"

auth=()
[[ -n "${CURL_AUTH:-}" ]] && auth=(-u "$CURL_AUTH")

ok=0; throttled=0; other=0
for ((i = 1; i <= N; i++)); do
  # 198.51.100.0/24 and 203.0.113.0/24 are documentation ranges (never real clients).
  forged="203.0.113.$(( (i % 250) + 1 ))"
  code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" \
         -H "X-Forwarded-For: ${forged}" "$URL" || echo 000)
  case "$code" in
    200)     ok=$((ok + 1)) ;;
    429)     throttled=$((throttled + 1)) ;;
    *)       other=$((other + 1)) ;;
  esac
done

echo "Requests: $N   200: $ok   429: $throttled   other: $other"

if (( other > N / 2 )); then
  echo "INCONCLUSIVE: most responses were neither 200 nor 429 (wrong URL, Basic Auth, or an error)."
  exit 2
elif (( throttled > 0 )); then
  echo "OK: the limiter counted the forged requests together (forged X-Forwarded-For is not honoured)."
else
  echo "VULNERABLE: $N requests with different forged X-Forwarded-For values were never throttled."
  echo "Set TRUSTED_PROXIES to the real proxy addresses (see .env.example) and re-run."
  exit 1
fi
