# Maintenance mode (static 503 page)

Implements "re-route ingress to the static maintenance splash page" from the DR policy
(3.5 phase 1) and BCDR 3.8 stage 1, on the current AWS host. It is a flag file read by
nginx, so switching is instant, needs no reload and works even when every container is down.

| File | Goes to |
|---|---|
| `maintenance.html` | `/var/www/ris-maintenance/` (by `install`) |
| `ris-maintenance.http.conf` | `/etc/nginx/conf.d/ris-maintenance.conf` (by `install`) |
| `ris-maintenance.server.conf` | `/etc/nginx/snippets/ris-maintenance.conf` (by `install`) |
| `ris-maintenance.sh` | run in place, or copy to `/usr/local/sbin/` |

## Install (once, on production)

```
sudo ./ris-maintenance.sh install
```

Then add `include snippets/ris-maintenance.conf;` at the top of the `ris-api` and `ris-backend`
server blocks in `/etc/nginx/sites-available/` (before any `location`), run
`sudo nginx -t && sudo systemctl reload nginx`, and record both edits in the Deployment
Documentation (host nginx is not under version control).

## Use

```
sudo ris-maintenance.sh on "Database restore drill"   # everything answers 503 + the splash page
ris-maintenance.sh status
sudo ris-maintenance.sh off
```

`on` refuses to run if the snippet is not included by any active server block, so it can never
report success while doing nothing.

## Behaviour to know about

* Every path is answered with HTTP 503 and `Retry-After: 900`, including `/api/`, `/app/`
  (WebSocket) and `/api/health`. A 503 from `/api/health` is correct: the site is intentionally
  unavailable.
* **Bypass for the recovery team:** list addresses in `/etc/ris-maintenance/allow.conf`
  (`203.0.113.10 1;`). `127.0.0.1` is allowed by default so on-box checks work. Do **not**
  allowlist CloudFront ranges.
* **CloudFront and the front end.** The `pupt-ris` hostname reaches nginx through CloudFront,
  so the allowlist cannot identify individual users there, and the React app itself is served
  from S3, so the splash page appears for direct hits on the API host and for API calls, while
  the SPA shell still loads and shows its own error state. A full-site splash on `pupt-ris`
  needs a change in CloudFront (or a frontend maintenance screen that reacts to a 503); that is
  not covered here.
* The page has no scripts, no external assets and a locked-down CSP.

## Evidence (EV-BCDR-08)

A screenshot of the page (with the browser address bar and date visible) plus the matching lines
from `/var/log/ris/maintenance.log` (UTC and PHT timestamps, operator, reason).
