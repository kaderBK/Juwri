# Production hardening checklist (shared / cPanel-Apache)

> Hosting profiles and install guides: [`HOSTING.md`](HOSTING.md).

Run through this before every production deploy. It mirrors the invariants the
automated tests enforce locally (`ConfigHardeningTest`, `DocRootExposureTest`,
`CspTest`/`CspEnforceTest`) and adds the host-level steps the repo can't assert.
Owner-owned — the code ships the safe defaults; these are the env + server
values you set on the box.

**Automated check:** run `php artisan ops:preflight` (add `--cloud` on juwri Cloud,
`--json` for machine output) on the box. It exits non-zero and lists each failed
rule for APP_ENV/APP_DEBUG/APP_KEY, TOKEN_QUERY_FALLBACK, PASSWORD_POLICY_STRICT,
HS_VERIFY_SIGNATURES, SMS_INBOUND_ALLOW_UNSIGNED, CORS `*`, session cookie
secure/http_only/same_site, ABUSE_MODE (`abuse_mode_enforce`), TRUSTED_PROXIES,
and in production: QUEUE_CONNECTION=sync (`queue_not_sync`), CACHE_DRIVER or
SESSION_DRIVER `array`/`null` (`cache_driver`, `session_driver`), MAIL_MAILER
`log`/`array` (`mail_mailer`); with `--cloud` also AI_REQUIRE_NO_TRAINING,
FORCE_2FA_ADMIN and BREACH_NOTICE_BCC unset (`breach_notice_bcc`).
Warnings (printed, exit code unchanged): LOG_LEVEL debug in production
(`log_level_debug`); with `--cloud`, PHARMACY_ENABLED_COUNTRIES empty
(`pharmacy_countries`).
Manual (no config key to check): backup and restore-check; a worker for the
`heavy` queue (`queue:work heavy`, the `queue-heavy` service in
docker/compose.prod.yml) whenever QUEUE_CONNECTION is not sync; FILESYSTEM_DISK
must not be `local` when Cloud runs more than one app node (use S3/shared disk).

## 1. App environment (`core/.env`)

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` — the `app_debug_in_production()` guard forces debug off
      in production regardless, but set it correctly anyway (ConfigHardeningTest).
- [ ] `APP_KEY` set (a real `base64:` key; never the repo default).
- [ ] `APP_URL=https://PROD` (correct scheme + host — signed URLs / assets rely on it).
- [ ] `TOKEN_QUERY_FALLBACK=false` so owner, customer, staff, driver, and
      storefront tokens are accepted from headers only.
- [ ] `PASSWORD_POLICY_STRICT=true` so owner, staff, reset, and customer
      registrations share the 10-character mixed-case/number/breach policy.
- [ ] `HS_VERIFY_SIGNATURES=true`; every active HungerStation vendor has a
      `webhook_secret`. Missing vendor secrets now reject callbacks.
- [ ] `SMS_INBOUND_ALLOW_UNSIGNED=false`; each active SMS inbound credential
      has the provider verification secret. Vonage/non-Twilio inbound must not
      be enabled until its provider-specific signature adapter is configured.
- [ ] `MCP_ALLOWED_ORIGINS` is blank unless a browser-based cross-origin MCP
      client is required; when set, it contains exact trusted origins only.
- [ ] `HEALTHZ_TOKEN` is a strong random value. Production hides readiness
      details when it is blank, but the deploy smoke URL should authenticate so
      operators can validate the detailed probe deliberately.

## 2. Transport + cookies

- [ ] HTTPS enforced (valid cert; redirect http→https at the host/CDN).
- [ ] `SESSION_SECURE_COOKIE=true` — defaults to true when `APP_ENV=production`
      (`config/session.php`), keep it true.
- [ ] Session cookies stay `http_only=true`, `same_site=lax` (code defaults; do
      not loosen).
- [ ] If behind a CDN / load balancer: set `TRUSTED_PROXIES` to the exact
      comma-separated proxy IP/CIDR list so the real client IP + https scheme
      are honoured. Docker image: unset = private ranges in proxy mode
      (`SERVER_NAME=:port`), nothing when Caddy serves the domain itself; a
      public proxy (Cloudflare, ALB in another subnet) must be listed
      explicitly (docker/README.md "Behind a proxy"). Never `*` — the origin
      port must not be reachable from the internet except via the proxy.
- [ ] Check: two clients through the proxy are throttled separately and
      `request()->isSecure()` is true (secure cookies, https redirects).

## 3. CORS (token API)

- [ ] `config('cors.allowed_origins')` is an explicit list — **never `*`**
      (ConfigHardeningTest).
- [ ] `supports_credentials=false` (token API must not send credentials
      cross-origin).
- [ ] CORS applies to `api/*` only, not every path (`cors.paths`; preflight
      warns on `*` origins plus non-api paths).
- [ ] Preflight fails when `*` origins are combined with
      `supports_credentials=true` (`cors_credentials_wildcard`).

### 3a. Cookies vs reseller custom domains (P6-104)

- [ ] `SESSION_DOMAIN` empty (host-only). Preflight fails if it starts with
      `.` (`session_domain_shared`), warns on any value, so store subdomains
      and reseller custom domains never share a cookie.
- [ ] `SESSION_SECURE_COOKIE=true`, `same_site` lax/strict, `http_only=true`
      (existing rules). XSRF-TOKEN and remember-me cookies use these same
      session settings.
- [ ] Prefer a `__Host-` session cookie name (`SESSION_COOKIE=__Host-...`;
      split mode already applies it per origin). Preflight warns otherwise.

## 4. Content-Security-Policy (storefront + dashboards)

CSP is **on and enforcing by default** (`core/config/csp.php`: `CSP_ENABLED`,
`CSP_ENFORCE` and `CSP_ENFORCE_DASHBOARD` all default to `true`). Leave them
unset or `CSP_ENFORCE=true`. Every inline
`<script>`/`<style>` in the store_templates is nonced (CspEnforceTest ratchet).

- [ ] Do **not** set `CSP_ENABLED`, `CSP_ENFORCE` or `CSP_ENFORCE_DASHBOARD` to
      `false` in production (the default is `CSP_ENFORCE=true`; leave it unset). If a 3rd-party embed (payment SDK / maps) is
      blocked, widen `StorefrontPolicy` (read `/csp-report`) rather than
      dropping to report-only. Report-only is a temporary per-theme diagnosis
      step only, never a resting state.

### Abuse controls

Every abuse guard (`webhook_in`, `upload`, `public_write`, `signup`, `mcp`,
`ai`) defaults to **`enforce` when `APP_ENV=production`** and `warn` elsewhere
(`core/config/abuse.php`). Precedence: admin DB setting → `ABUSE_MODE_<GUARD>`
→ `ABUSE_MODE` → the environment default.

- [ ] Leave `ABUSE_MODE` and every `ABUSE_MODE_<GUARD>` unset (or `enforce`).
      `warn` records abuse but does not block it; `off` does neither. Use
      `warn` for one guard only as a short, logged exception.
- [ ] Admin → abuse settings show no guard overridden to `warn`/`off`.
- [ ] `php artisan ops:preflight` passes `abuse_mode_enforce` (it resolves
      every guard, admin overrides included).

### Trusted proxies (docker image)

- [ ] Never `TRUSTED_PROXIES=*` (anyone could forge their IP).
      `ops:preflight` rule `trusted_proxies` fails on it.
- [ ] Proxy mode (`SERVER_NAME=:8080`, own TLS proxy in front): the image
      trusts private/loopback ranges by default; behind Cloudflare / ALB / a
      proxy on another public IP set `TRUSTED_PROXIES` to its ranges.
      `ops:preflight` fails if proxy mode has nothing trusted
      (`TRUSTED_PROXIES=none`).
- [ ] Port 8080 is **not reachable from the internet** except through the
      proxy: publish it as `127.0.0.1:8080:8080` in compose, or firewall it.
      The app cannot see how the port is published, so preflight cannot check
      this; verify from outside (`curl http://<server-ip>:8080/` must fail).

## 5. Doc-root / source exposure (Apache/cPanel)

- [ ] Follow [`cpanel/README-doc-root.md`](cpanel/README-doc-root.md): Document
      Root at the repo root (front controller + `.htaccess`), `AllowOverride All`.
- [ ] `mod_headers` + `mod_rewrite` enabled.
- [ ] Verified live: `/core/.env`, `/core/app/...`, `/core/storage/logs/...` all
      return 403/404; `/` returns 200. (Owner-at-deploy.)
- [ ] `core/.env` is chmod **600**; directory listing off (`Options -Indexes`,
      shipped in `.htaccess`).

## 6. Build + caches

- [ ] `composer install --no-dev --optimize-autoloader` (no dev deps in prod).
- [ ] `composer audit --locked` returns zero advisories and no abandoned-package
      failures for the exact release lock.
- [x] `php artisan config:cache` — **safe** (P6/O9/S1). Every runtime `env()` stray
      on a hot path (`THEME_NAME`, `APP_VERSION`, `APP_INSTALLED`, `APP_ADMIN`,
      `DEMO_MODE`, `DEFAULT_LANGUAGE`, the SMS-provider fallback) now resolves via
      compiled `config()`, so the storefront, admin prefix, demo gate, default locale
      and platform SMS fallback keep working once `.env` stops being parsed.
- [ ] `php artisan view:cache`.
- [x] `php artisan route:cache` — supported and verified against the current
      route table. Run it on every deploy; treat any future serialization
      error as a release blocker, then run `php artisan route:clear` while fixing.
- [ ] `php artisan migrate --force` run against the prod DB.
- [ ] `php artisan storage:link` if public storage is used.

### Cache + session driver (P7)

The shipped defaults are `CACHE_DRIVER=file` / `SESSION_DRIVER=file` so a fresh
dev/test install boots with **no** Redis dependency. In production, move both onto
Redis — every rate-limit check reads the cache store and every anonymous QR visitor
writes a session file, so `file` pays locked disk I/O per request at scale.

- [ ] `predis/predis` (or the `phpredis` PHP extension) available.
- [ ] `redis-cli -h $REDIS_HOST -p $REDIS_PORT ping` returns `PONG` (connectivity check).
- [ ] In prod `.env`: `CACHE_DRIVER=redis` and `SESSION_DRIVER=redis` (`REDIS_HOST` /
      `REDIS_PORT` / `REDIS_PASSWORD` already set). Optionally `QUEUE_CONNECTION=redis`.
- [ ] `php artisan cache:clear` then `php artisan config:cache`.
- [ ] **Rollback:** set both back to `file` and run `php artisan cache:clear`.

## 7. Database + operations

- [ ] DB user is least-privilege (no `GRANT ALL` to a superuser from the app).
- [ ] Automated backups configured (DB + `<APP_ROOT>/storage/` — the repo-root one holding
      `menu/`, `restaurant/` …, NOT `core/storage/`); restore tested.
- [ ] **Dedicated queue worker (P10)** — install the shipped supervisor program
      [`deploy/supervisor/juwri-worker.conf`](supervisor/juwri-worker.conf)
      (`queue:work --queue=default …` plus, P6-64b, a `queue:work heavy --queue=heavy …` program for long jobs — the `heavy` connection has retry_after 1860 s, the default 150 s):
      `cp` it to `/etc/supervisor/conf.d/`, `supervisorctl reread && update && start
      juwri-worker:*`. While it runs, the `queue_worker_alive` heartbeat is fresh
      so the scheduler-cron fallback drains in `app/Console/Kernel.php` auto-disable
      (no double-consumer race). Restart it after every deploy
      (`supervisorctl restart juwri-worker:*`). Prod recommendation:
      `QUEUE_CONNECTION=redis` (pairs with the P7 redis switch).
- [ ] Scheduler cron (`* * * * * php artisan schedule:run`) installed (also drains
      the queue as a fallback on hosts without the supervisor worker).
- [x] Log rotation — satisfied by default (O12a): the `stack` default now uses the
      `daily` channel (14-day retention), and the level defaults to `warning` in
      production. Override with `LOG_LEVEL` / `LOG_CHANNEL` if needed.
- [ ] MySQL `slow_query_log` enabled server-side (complements the app-level
      `SLOW_QUERY_LOG_MS` [slow-query] warnings from O12c).
- [ ] Optional: **Laravel Pulse** (P1-17, MIT) operations dashboard, off by default. Turn it on with
      `PULSE_ENABLED=true` in `core/.env` (tables are created by `php artisan migrate`; nothing else to install, no
      `pulse:check` daemon: the server card is not used). Open `/pulse` (on a split-origin install: the admin host)
      as a superadmin; everyone else, and guests, are redirected. Records only requests slower than 1 s, queries
      slower than 1 s, exceptions, queue and cache activity, sampled (cache 10%, queues 25%;
      `PULSE_*_SAMPLE_RATE`, `PULSE_SLOW_*_THRESHOLD`). Entries carry route templates, SQL without values, class
      names and a user id, never a name, email, phone or request data. Data is trimmed after 7 days
      (`PULSE_STORAGE_KEEP`). `PULSE_PATH` renames the URL; `PULSE_DB_CONNECTION` moves the `pulse_*` tables to
      another database. Set `PULSE_ENABLED=false` to stop recording at once (the tables stay; they are platform
      tables and are never part of a store export, transfer or delete).
- [ ] Supervisor worker log rotation is active (the shipped config caps
      `worker.log` at 50 MB with 10 backups); confirm the host did not override it.

## 8. Post-deploy smoke

- [ ] The health probe URL used by your deploy smoke includes the configured
      `HEALTHZ_TOKEN` query value when detailed readiness is protected.
- [ ] Storefront menu renders (a real post_template) over HTTPS.
- [ ] A test order places + a webhook delivers (data-plane intact).
- [ ] No `APP_DEBUG` stack traces leak on a forced 500.
- [ ] Confirm a deliberately failed staging deploy exits maintenance mode: your
      deploy script must run `artisan up` even when migrate/cache fails.

## 9. Release and rollback discipline

- [ ] Every migration in the release is backward-compatible with both the old
      and new application version (expand first; backfill; contract only in a
      later release).
- [ ] Prefer atomic release directories plus a symlink switch where the host
      supports them, so code/vendor/assets change as one unit.
- [ ] Do not automatically reset to an older Git SHA after forward migrations.
      On failure, keep the service reachable and ship a forward fix. Roll back
      code only when the operator has confirmed schema compatibility or has an
      explicit database restore/down-migration plan.

## 10. juwri Cloud: values behind the published promises (P1-23)

The website, Terms, DPA and security page promise these for juwri Cloud. The code ships them as
switches (off or neutral on a self-hosted install); set them on every Cloud box.

- [ ] `AI_REQUIRE_NO_TRAINING=true` — the platform AI key goes only to providers flagged
      `<PROVIDER>_NO_TRAINING=true` (Terms 5.3, DPA 11.2). Flip a provider's flag only after checking
      its current API terms for the account in use (OpenAI, Anthropic, Groq default true; Gemini
      only on the paid tier; MiMo, Kimi, DeepSeek default false).
- [ ] `FORCE_2FA_ADMIN=true` — staff production access (the superadmin panel) needs two-factor or a
      passkey (DPA Schedule 2 item 6).
- [ ] Off-box backups configured (`AWS_ACCESS_KEY_ID`, `AWS_BUCKET`, …); `BACKUP_KEEP_DAYS=30`;
      `BACKUP_S3_SSE=AES256` (or `aws:kms`) — "encrypted backups, taken daily and kept for 30 days"
      (security page 7, SLA 9.1). The bucket must not have versioning or a lifecycle rule that keeps
      deleted dumps longer than 90 days (Terms 7.6).
- [ ] Admin → Settings → General: switch on the nightly media mirror (`platform_media_backup_enabled`,
      uploaded files are part of the backups) and the weekly restore drill
      (`platform_backup_restore_check_enabled`, "we test restoring them regularly"). Both are off by
      default. A file deleted from the platform stays in the media mirror for `BACKUP_KEEP_DAYS`, then
      the nightly run removes it (manifest `backups/platform/media.deleted.json`).
- [ ] Encryption at rest of the database and upload volumes is switched on at the hosting provider
      (security page 7, DPA Schedule 2 item 3) — record the provider setting in the release notes.
- [ ] `BREACH_NOTICE_BCC=security@juwri.com`; the team knows `deploy/SECURITY-INCIDENT-RUNBOOK.md`
      (48 h breach notice, 24 h for Kuwait-established customers).
- [ ] Pharmacy: `PHARMACY_ENABLED_COUNTRIES` lists only the countries the owner has chosen to enable (owner-later, not a launch blocker, J35) (empty
      until then — AUP 3.1).
