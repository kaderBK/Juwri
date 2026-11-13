---
title: "Abuse & Load Runbook (INTERNAL)"
audience: internal
group: Internal
updated: 2026-07-10
---

# Abuse & Load Runbook (INTERNAL)

> **INTERNAL — NOT CUSTOMER FACING.** This document is for platform operators and
> infrastructure engineers. Do not link it from customer docs, the help center, or
> any public page. It describes the operational hardening that sits *around* the
> in-app Abuse Guard (WARN-by-default) and covers edge, host, database, and worker
> layers.

The in-app Abuse Guard handles per-tenant/per-actor rate and quota decisions. The
controls below are the outer layers of defense: they absorb volumetric and
protocol-level abuse before requests ever reach PHP, and keep the host healthy
under load. Treat this as a checklist when standing up a new environment and as a
reference during an active incident.

---

## 1. Cloudflare (edge)

Cloudflare is the first line of defense. Everything here runs before traffic hits
origin, so it protects PHP-FPM, the database, and the queue from ever seeing the
load.

### 1.1 WAF Managed Rules

Enable the Cloudflare **Managed Ruleset** and the **OWASP Core Ruleset** on the
zone.

- Managed Ruleset: **Action = Block**, sensitivity **Medium** to start; raise to
  High only after reviewing false positives in the WAF events log.
- OWASP Core Ruleset: start with **Paranoia Level 1** and an anomaly score
  threshold of ~40 (Block). Higher paranoia levels are noisy on rich admin forms.
- Keep an allow/skip rule for known-good automation (uptime monitors, payment
  webhook source IPs) so managed rules never block them.

Review `Security → Events` weekly. Any rule generating repeated false positives on
legitimate tenant traffic should be moved to **Log** and tuned before re-blocking.

### 1.2 Rate-Limiting Rules (per path)

Configure Cloudflare Rate Limiting rules scoped to the sensitive paths. These are
volumetric guards — deliberately looser than the in-app Abuse Guard, which does
the fine-grained per-tenant work.

| Path pattern | Suggested limit | Window | Action | Notes |
|---|---|---|---|---|
| `/login`, `/admin/login`, `/reseller/*/login` | 10 req | 1 min per IP | Block 15 min | Credential stuffing |
| `/register`, `/password/*` | 5 req | 1 min per IP | Managed Challenge | Signup / reset abuse |
| `/api/*` | 300 req | 1 min per IP | Block 1 min | Backstop; real limit is per-token in app |
| `/*/scan`, `/*/import`, `/*/export` | 20 req | 5 min per IP | Managed Challenge | Heavy endpoints |
| `/webhooks/*` | (allowlist source IPs; do not rate-limit legit providers) | — | Skip | |

Example (Cloudflare rules expression for the login group):

```
(http.request.uri.path in {"/login" "/admin/login"})
```
Then: **When rate exceeds 10 requests per 1 minute per IP → Block for 900 seconds.**

Tune numbers per environment. The goal is to stop obvious floods, not to replace
the in-app guard. Keep edge limits **above** the app limits so the app’s WARN/enforce
logic is what normally trips first for a single misbehaving tenant.

### 1.3 Bot Fight Mode

- Enable **Bot Fight Mode** (or **Super Bot Fight Mode** on paid plans).
- On Super Bot Fight Mode: **Definitely automated → Block**, **Likely automated →
  Managed Challenge**, and **Verified bots → Allow** (so Googlebot / payment
  providers / uptime checks pass).
- Confirm the JS-based detections do not interfere with the customer PWA or the
  MCP/API endpoints, which are legitimately non-browser. Add skip rules for
  `/api/*` and `/mcp/*` if challenges leak onto them.

### 1.4 Turnstile (CAPTCHA)

Turnstile fronts the auth and signup forms. The keys are entered by an operator in
the **Abuse Guard admin tab** (not in `.env`, not in code).

Setup:

1. In the Cloudflare dashboard: **Turnstile → Add Site**. Add the production
   hostname(s). Choose the **Managed** widget mode.
2. Cloudflare issues two values:
   - **Site Key** (public, rendered in the page).
   - **Secret Key** (private, used server-side to verify the token).
3. In the app: **Superadmin → Abuse Guard tab → Turnstile** section. Paste the
   **Site Key** and **Secret Key** into their fields and save.
   - These persist as options `abuse_turnstile_site_key` and
     `abuse_turnstile_secret_key` (read at
     `config('settings.abuse_turnstile_site_key')` /
     `config('settings.abuse_turnstile_secret_key')`).
   - Leave the guard in **WARN** until verification is confirmed working
     end-to-end, then flip to **enforce** to actually block on failed challenges.
4. Verify: load `/login` in an incognito window; the widget should render, and a
   deliberately tampered token should fail server-side verification.

> Rotate the Secret Key from the Cloudflare dashboard if it is ever exposed, then
> update the Abuse Guard tab. Never commit either key to the repo.

---

## 2. PHP-FPM tuning (host)

Right-size FPM so a burst of heavy requests cannot exhaust memory and OOM the box.
These go in the pool config (`/etc/php/8.2/fpm/pool.d/www.conf`) and
`php.ini`.

```ini
; php.ini
max_execution_time = 30        ; web requests should be short; heavy work goes to the queue
memory_limit = 256M            ; per-request cap; raise only for specific CLI/import paths
post_max_size = 32M
upload_max_filesize = 32M
```

```ini
; pool.d/www.conf
pm = dynamic
pm.max_children = 20           ; see sizing below
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500          ; recycle workers to bound memory leaks
request_terminate_timeout = 60 ; hard kill runaway requests (> max_execution_time)
```

### Sizing `pm.max_children`

Rule of thumb:

```
pm.max_children = (Total RAM - OS/other overhead) / avg PHP process size
```

- Measure average process size under real load:
  `ps --no-headers -o rss -C php-fpm8.2 | awk '{s+=$1; n++} END {print s/n/1024 " MB avg"}'`.
- Example: a 4 GB host, ~1 GB reserved for OS + MySQL sidecar + Redis, PHP
  processes averaging ~80 MB → `(4096 - 1024) / 80 ≈ 38`. Set `pm.max_children`
  around **32–38** and leave headroom.
- Do **not** set it so high that `max_children * memory_limit` exceeds RAM — that
  is how a traffic spike turns into an OOM kill. It is better to queue/503 than to
  swap.

Watch the FPM slow log and the `max_children reached` warnings; if you hit the
ceiling regularly, add capacity rather than blindly raising the number past what
RAM allows.

---

## 3. MySQL (database)

Prevent a single pathological query from pinning a core and stalling the pool.

```ini
# my.cnf  [mysqld]
max_execution_time = 30000     # milliseconds — abort SELECTs running > 30s (MySQL 5.7.8+/8.0)
wait_timeout = 300
interactive_timeout = 300
max_connections = 150          # keep >= expected FPM children + workers + headroom
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2            # log anything slower than 2s for review
```

Notes:

- `max_execution_time` is server-side and only bounds **SELECT** statements; it is
  the long-query kill switch. For per-session control you can also
  `SET SESSION max_execution_time = ...` around known-heavy read paths.
- Keep `max_connections` comfortably above `pm.max_children` + queue-worker
  concurrency, or web requests will fail with “Too many connections” under load.
- Review `slow.log` regularly; anything recurring above `long_query_time` is a
  candidate for an index or a query rewrite. Feed findings back to the app team.

---

## 4. fail2ban (auth/login brute-force)

fail2ban bans IPs that repeatedly fail auth at the host firewall level, backing up
Cloudflare and the app guard. Point a filter at the web access log and match failed
login POSTs (HTTP 401/403/422 on the auth paths).

`/etc/fail2ban/filter.d/juwri-auth.conf`:

```ini
[Definition]
# Match failed auth attempts in the nginx/access log
failregex = ^<HOST> .* "POST /(admin/)?login HTTP/[0-9.]+" (401|403|422) 
            ^<HOST> .* "POST /(register|password/(email|reset)) HTTP/[0-9.]+" (401|403|422) 
ignoreregex =
```

`/etc/fail2ban/jail.d/juwri.conf`:

```ini
[juwri-auth]
enabled  = true
port     = http,https
filter   = juwri-auth
logpath  = /var/log/nginx/access.log
findtime = 300      # window: 5 minutes
maxretry = 8        # bans after 8 failures in the window
bantime  = 3600     # 1 hour ban
```

- If origin is behind Cloudflare, ensure the access log records the **real client
  IP** (restore visitor IP via Cloudflare’s `mod_remoteip` / `real_ip` config),
  otherwise fail2ban will ban Cloudflare edge IPs. Alternatively use the Cloudflare
  fail2ban action to push bans back to the edge via API.
- Reload with `fail2ban-client reload`; inspect with
  `fail2ban-client status juwri-auth`.

---

## 5. Log rotation & temp-dir cleanup

Unbounded logs and orphaned temp files are a slow-motion outage. Keep them capped.

### Log rotation

`/etc/logrotate.d/juwri`:

```
/var/www/juwri/core/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

- `copytruncate` avoids needing to signal PHP-FPM to reopen the file.
- Also rotate `/var/log/nginx/*.log` and `/var/log/mysql/slow.log` (the nginx and
  mysql packages ship their own logrotate configs — verify they are active).
- Watch Laravel’s `storage/logs/laravel.log`; if the app is in daily-log mode
  (`LOG_CHANNEL=daily`), Laravel already caps files by `LOG_DAILY_DAYS` — align the
  logrotate `rotate` count so the two don’t fight.

### Temp-dir cleanup

Import/export/scan jobs stage files under the system temp dir and
`storage/app/tmp`. Sweep stale files nightly so a failed job never leaks disk.

`/etc/cron.d/juwri-tmp-clean`:

```
# Delete temp/staged files older than 24h (runs 03:15 daily)
15 3 * * * www-data find /var/www/juwri/core/storage/app/tmp -type f -mmin +1440 -delete
20 3 * * * www-data find /tmp -maxdepth 1 -type f -name 'juwri-*' -mmin +1440 -delete
```

- Only sweep directories you own. Never blanket-delete `/tmp` — scope by prefix
  (`juwri-*`) and age.
- Alert on disk usage (>80%) so a runaway import surfaces before it fills the
  volume.

---

## 6. Heavy-queue worker deployment

The app defines a dedicated **`heavy`** queue for slow/expensive jobs — AI
generation, imports, exports, and menu scans. These must not share a worker with
latency-sensitive default jobs (mail, notifications, webhooks), or a single 90-second
AI call will stall the whole pipeline.

**Deployment model:** run (at least) two worker processes.

1. A **dedicated heavy worker** that pulls only `heavy`.
2. The **default worker** that pulls `default` first, then `heavy` as a fallback so
   the heavy queue is never fully starved if the dedicated worker is down. Give it a
   larger timeout because it may pick up heavy jobs.

Example supervisor config (`/etc/supervisor/conf.d/juwri-workers.conf`):

```ini
[program:juwri-heavy]
command=php /var/www/juwri/core/artisan queue:work --queue=heavy --timeout=600 --tries=3 --sleep=3 --max-time=3600
directory=/var/www/juwri/core
autostart=true
autorestart=true
user=www-data
numprocs=2                 ; scale heavy concurrency here
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=630           ; > --timeout so a running job finishes on graceful stop
stdout_logfile=/var/www/juwri/core/storage/logs/worker-heavy.log

[program:juwri-default]
command=php /var/www/juwri/core/artisan queue:work --queue=default,heavy --timeout=120 --tries=3 --sleep=3 --max-time=3600
directory=/var/www/juwri/core
autostart=true
autorestart=true
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=150
stdout_logfile=/var/www/juwri/core/storage/logs/worker-default.log
```

Key flags:

- `--queue=heavy` (dedicated) vs `--queue=default,heavy` (default worker, heavy as
  fallback — order matters, leftmost is highest priority).
- `--timeout` must be **shorter** than PHP CLI `max_execution_time` (or 0/unlimited
  for CLI) and shorter than `stopwaitsecs`, so supervisor lets a job finish on
  restart instead of killing it mid-flight. Heavy jobs get a generous timeout
  (e.g. 600s); default jobs stay short (120s).
- `--tries=3` bounds retries; ensure failed jobs land in `failed_jobs` for review
  rather than looping forever.
- After every deploy, run `php artisan queue:restart` so workers pick up new code.
  Supervisor will respawn them.

**Scaling:** increase `numprocs` on `juwri-heavy` to add heavy concurrency, but
remember each worker is another DB connection and ~one PHP process worth of RAM —
count them in the MySQL `max_connections` and host-RAM budgets from sections 2–3.

---

## Quick incident checklist

1. **Under flood?** Check Cloudflare `Security → Events`. Tighten the relevant rate
   limit or enable “Under Attack” mode temporarily.
2. **FPM saturated?** Look for `max_children reached` in the FPM log. Confirm RAM
   headroom before raising the number.
3. **DB pinned?** Check `SHOW PROCESSLIST` and the slow log; `max_execution_time`
   should be killing long SELECTs — verify it’s set.
4. **Auth brute-force?** `fail2ban-client status juwri-auth`.
5. **Queue backed up?** Confirm both worker programs are running under supervisor;
   check `worker-heavy.log` for a stuck job; `queue:restart` if code is stale.
6. **Disk filling?** Check `storage/app/tmp` and log sizes; confirm the cleanup cron
   and logrotate ran.
