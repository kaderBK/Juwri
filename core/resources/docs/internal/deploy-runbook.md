---
title: "Deploy and backup runbook"
audience: internal
group: Internal
updated: 2026-07-29
---

# Deploy and backup runbook

Engineering runbook for deploying juwri, the post-pull steps that keep a
checkout bootable, the production hardening invariants, and the backup/restore
drill. Production steps are owner-actioned — the loop and CI cannot reach the
live box, so treat the `deploy/` checklists as the source of truth and confirm
with the owner before touching production.

Primary sources in the repo: [`deploy/PROD-HARDENING-CHECKLIST.md`](../../../../deploy/PROD-HARDENING-CHECKLIST.md),
[`deploy/cpanel/README-doc-root.md`](../../../../deploy/cpanel/README-doc-root.md),
and `rev/BACKUP-RESTORE-RUNBOOK.md`. This page summarises them and pins the code
facts.

## Repo layout and document root

This is not a stock Laravel tree. The Laravel app
lives entirely in `core/`; the web document root is the **repo root**. The
root `index.php` is the front controller — it requires `core/vendor/autoload.php`
and `core/bootstrap/app.php`. `router.php` is a shim for the PHP built-in server
(local preview only).

`AppServiceProvider::register()` calls `app()->usePublicPath(base_path('../'))`,
so `public_path()` resolves to the repo root. Consequences:

- Static assets live at repo-root `assets/`, not `core/public/`. There is no
  `core/public/` directory.
- `php artisan storage:link` links repo-root `storage/` to
  `core/storage/app/public`. User uploads land under `core/storage/app` and are
  served through that symlink. Both repo-root `storage/` and `core/public/storage`
  are git-ignored.
- Because `core/` is a servable subdirectory, `/core/.env`, `/core/app/...`,
  `/core/storage/logs/...` would be fetchable unless denied. The root `.htaccess`
  denies them (guarded by `DocRootExposureTest`); the only public path under
  `core/` is `core/plugins/<id>/assets/`. See
  [security](security.md) for the full deny list.
- The same reasoning covers two more top-level dirs that live in the repo root:
  `integrations/` (the Zapier / Make / n8n package sources — `.js`/`.ts`/`.json`)
  and `rev/` (working docs). The root `.htaccess`, `web.config` and `router.php`
  all deny them (also guarded by `DocRootExposureTest`). On **nginx**, add the
  equivalent — this is a deploy requirement, not enforced in code:

  ```nginx
  location ~ ^/(integrations/(make-app|n8n-nodes-juwri|zapier-app)|rev)(/|$) { return 404; }
  ```

## Deploy steps (production)

From [`deploy/PROD-HARDENING-CHECKLIST.md`](../../../../deploy/PROD-HARDENING-CHECKLIST.md)
section 6, run inside `core/`:

```bash
cd core
composer install --no-dev --optimize-autoloader
composer audit --locked
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link   # only if public storage is used
```

- `--no-dev` keeps PHPUnit/dev tooling out of production.
- `composer.json` pins `config.platform.php` to `8.2` and requires `php: ^8.2`.
  Never run `composer update --ignore-platform-reqs` — it bypasses the 8.2
  platform pin and produces a lock that breaks CI.
- `migrate --force` is required because `migrate` refuses to run non-interactively
  in production without it.
- Route caching is supported by the current route table and is a required
  release gate. A serialization failure blocks the deploy.
- Re-run the three cache commands on every deploy so config/route/view caches
  match the deployed code. If you change `.env` on the box, run
  `php artisan config:clear && php artisan config:cache`.

### CI deploy workflow

`.github/workflows/deploy.yml` can run these steps over SSH, but only when six
repository secrets are configured: `DEPLOY_HOST`, `DEPLOY_USER`,
`DEPLOY_SSH_KEY`, `DEPLOY_PATH`, `DEPLOY_UP_SECRET`, and
`DEPLOY_HEALTHZ_URL` (optional `DEPLOY_PORT`, default 22). The health URL may
include the `HEALTHZ_TOKEN` query value. When any required secret is missing the
workflow emits a "Deploy skipped" notice instead of failing — an all-green run
does **not** prove a deploy happened until the deploy job itself ran.

The remote script enters maintenance before pulling code and installs
dependencies, migrates, and rebuilds caches under an EXIT trap. Any failed
command triggers a best-effort `php artisan up`, preventing a failed migration
or cache build from stranding production in maintenance. Health smoke is
mandatory; it cannot be silently skipped and there is no default maintenance
secret.

### Release compatibility and rollback

The workflow intentionally does **not** reset to the previous Git SHA after a
failed smoke. Migrations already applied to the database may not be compatible
with old code, so an automatic code rollback can deepen the outage.

- Prefer a forward fix after a failed release.
- Use expand/contract migrations: add compatible schema first, deploy and
  backfill, then remove old columns or constraints in a later release.
- Where the host supports it, build versioned release directories and switch a
  `current` symlink atomically so code, vendor dependencies, and assets change
  together.
- Roll code back only after an operator verifies that every applied migration
  remains backward-compatible, or coordinates a tested database restore/down
  migration. Keep `artisan up` independent from rollback so the status page is
  reachable while recovery is decided.

### One-time post-deploy steps

- `php artisan storage:migrate-staff-docs --move` — relocates legacy staff HR
  documents from public storage to the private disk (run once after the
  private-disk fix ships; without `--move` it is a dry-run report). See
  [security](security.md).

### Queue worker and scheduler

The default queue connection is `database` (`.env.example`: `QUEUE_CONNECTION=database`),
so queued work (webhook delivery, sends, lifecycle jobs) only runs when a worker
is up. Production must run:

- `php artisan queue:work` under supervisor (or a cron respawn), and
- the scheduler cron: `* * * * * php artisan schedule:run`.

See [queues and jobs](queues-jobs.md) for what runs where.

### Payments health alerts (`payments:watch`)

`payments:watch` runs every 5 minutes (`withoutOverlapping`; a crash raises a
`[scheduler-fail] payments:watch` alert). It checks the payment tables and raises
`payments-watch` platform alerts (superadmin alerts dashboard + `platform_alerts`
log channel / Slack). A finding about one store also e-mails **that store's owner**
(in the owner's language, only that store's rows). Each finding alerts at most once
per cooldown (default 60 min); the dashboard row is also de-duplicated per day.
Thresholds live in `config/payments.php` → `watch` (env overrides in `.env.example`).

| Alert | Default | What to do |
|---|---|---|
| `N <gateway> payment(s) unconfirmed for over 30 min` | ≥ 3 per gateway (bank transfer, wallet transfer, cash, GoCardless, Fawry kiosk and other slow rails excluded) | The gateway's webhook is not reaching us or is failing: check the provider's webhook delivery log, our `[payment-webhook]` / `[payment-settlement]` log lines, and the gateway keys. Owners get it for their own store gateway. |
| `N webhook notification(s) rejected in hour …` | ≥ 20/h platform, ≥ 5/h per store (owner e-mail) | Wrong or rotated webhook secret, sandbox/live mix-up, or someone probing the endpoint. Compare the rejection reasons in the log. |
| `refund … outcome UNKNOWN … reconcile with the provider` | pending > 15 min | The provider call timed out (RefundGuard keeps the amount reserved). Look the refund up in the provider dashboard; if it went through, mark the `details.gateway_refunds` entry `ok`, else `failed`. Never just refund again. The store owner is told too. |
| `refund … refused by the provider` | in the last 60 min | Read the provider's reason; the reservation was released. |
| `ledger:reconcile found drift` | checked hourly | Run `php artisan ledger:reconcile` for the detail (see [ledger](ledger-and-reconciliation.md)). The ledger is append-only: fix with a reversal. |
| `N seller withdrawal(s) pending for over 72 h` | > 72 h | Pay or reject them in the marketplace withdrawals admin. |
| `outbox relay backlog …` / `outbox message(s) failed after all retries` | owed > 15 min / any failed | Check that the scheduler and queue worker run (`payments:outbox-relay`); read `outbox_messages.last_error`, fix the cause, set the row back to `pending`. |

## Post-pull requirements (any environment)

After pulling — locally or on the box — a checkout is not necessarily bootable
until dependencies and schema catch up. Standard post-pull sequence:

```bash
cd core
composer install          # vendor/ tracks composer.lock, not committed
php artisan migrate        # apply new migrations
php artisan config:clear   # drop stale cached config after env/config edits
```

Notes:

- `composer.json` `post-autoload-dump` runs `package:discover`; `post-update-cmd`
  publishes Laravel assets. These fire automatically on install/update.
- Some feature branches ship migrations and new composer deps together (studio
  templates, plans rebuild). If a page 500s right after a pull, run
  `composer install` then `php artisan migrate` before debugging further.
- Locally, prefer plain `composer install`. Do not pass `--ignore-platform-reqs`
  on `composer update` (see the platform-pin note above).

## Local environment (Herd + DBngin)

Local runs on **Herd + DBngin**. Local URL is `https://juwri.test`
(self-signed cert). The DB table prefix is `qrtest_` (e.g. `qrtest_options`).

### HTTPS asset gotcha (browser previews)

When the global option `enable_force_ssl` is on, `AppServiceProvider::boot()`
forces `HTTPS` on generated `asset()`/`route()` URLs for every host except
`localhost`/`127.0.0.1`. A plain `php -S` HTTP preview server therefore cannot
serve the forced `https://` asset URLs, so CSS/JS fail to load under a preview.

Workaround: serve on an HTTP port via `router.php` + `.claude/launch.json`, then
in the preview strip the failed `https` links and re-inject the same files via
explicit `http://localhost:<port>/...` URLs. Do **not** toggle `enable_force_ssl`
to work around this — app-wide setting changes are off-limits as a workaround.

### CLI CA-bundle gotcha

CLI PHP (and therefore `queue:work`) needs a valid `openssl.cafile`/`curl.cainfo`.
A stale `openssl.cafile` pointing at a non-existent bundle makes every CLI
outbound HTTPS call fail with `cURL error 77` — the visible symptom is WhatsApp/SMS
sends silently falling back to the `link` driver. Fix is a drop-in ini pointing
both at an existing bundle (`/etc/ssl/cert.pem`), then `herd restart` so FPM and
workers pick it up.

## Production safety hardening

The invariants below are enforced locally by `ConfigHardeningTest`,
`DocRootExposureTest`, `CspTest`/`CspEnforceTest`. The code ships the safe
defaults; the host-level values are owner-set at deploy time. Full list in
[`deploy/PROD-HARDENING-CHECKLIST.md`](../../../../deploy/PROD-HARDENING-CHECKLIST.md).

- **App env** — `APP_ENV=production`, `APP_DEBUG=false`, a real `base64:` `APP_KEY`
  (never the repo default), `APP_URL=https://PROD`. `app_debug_in_production()`
  (`core/app/Http/Helpers/Helper.php`) forces debug off in production regardless,
  but set the env correctly anyway.
- **PHP handler** — the root `.htaccess` sets
  `AddHandler application/x-httpd-ea-php83 .php .php8 .phtml`. Match it to the
  real production PHP version if the host differs; ask, do not guess. Herd
  ignores this line locally.
- **Transport/cookies** — HTTPS enforced at host/CDN; `SESSION_SECURE_COOKIE=true`
  (defaults true when `APP_ENV=production`); keep session cookies `http_only` +
  `same_site=lax`. Behind a CDN/LB, set `TRUSTED_PROXIES` to exact comma-separated
  IPs/CIDRs; use `*` only when the application origin is unreachable directly.
- **CORS** — `config('cors.allowed_origins')` must be an explicit list, never `*`;
  `supports_credentials=false`; `config('cors.paths')` is `api/*` +
  `sanctum/csrf-cookie`.
- **CSP** — complete the pre-flip browser pass per theme, widen `StorefrontPolicy`
  from `/csp-report` observations, then set `CSP_ENFORCE=true` (keep
  `CSP_ENABLED=true`). Inline scripts/styles in store_templates are already
  nonced. Flip `CSP_ENFORCE_DASHBOARD=true` only after a separate privileged-role
  browser pass.
- **Abuse enforcement** — production must not remain warn-only. After reviewing
  warn-mode events, set `ABUSE_MODE=enforce` and the six per-guard
  `ABUSE_MODE_*` values (AI, MCP, public write, signup, upload, webhook in) to
  `enforce`.
- **MCP origins** — leave `MCP_ALLOWED_ORIGINS` blank for server-to-server
  clients. Browser clients require exact trusted origins; wildcards are not
  supported.
- **Doc-root exposure** — Document Root at the repo root, `AllowOverride All`,
  `mod_headers` + `mod_rewrite` on. Verify live that `/core/.env`,
  `/core/composer.json`, `/core/app/Models/Post.php`,
  `/core/storage/logs/laravel.log` all return 403/404 and `/` returns 200. Any
  200 on a `/core/...` probe means `.htaccess` is not being read — stop and fix
  `AllowOverride` before going live. `core/.env` chmod **600**.
- **Secret rotation** — if any key was ever committed or shown on a debug page,
  list it and have the **owner** rotate it (`STRIPE_*`, `RAZORPAY_*`, `PAYPAL_*`,
  `TWILIO_*`, `NEXMO_*`, `GOOGLE_*`, `FACEBOOK_*`, mail/DB passwords). Rotating
  `APP_KEY` logs everyone out and makes every `encrypted` column unreadable —
  coordinate with the owner, never do it blindly.

Prod stack is shared/cPanel-Apache (owner-ratified 2026-07-06). Nginx is not
used, so the `.htaccess` is the load-bearing doc-root control. A stock Laravel
`public/`-only root is a tracked future follow-up, not required today.

## Backup and restore

The production DB holds wallet balances, orders, and credentials in one place.
A backup that has never been restored is a hope, not a backup — run the restore
drill at least once, and after any schema-heavy release. Full drill in
`rev/BACKUP-RESTORE-RUNBOOK.md`.

### What must be backed up

- **MySQL database** — the whole schema (orders, wallet/transactions, users,
  credentials, plans).
- **`core/.env`** — especially `APP_KEY`. Without it every `encrypted` column is
  unreadable: 2FA secrets/recovery codes, BYOK credentials, gateway secrets.
  Back it up separately from the DB, encrypted at rest. Losing `APP_KEY` = losing
  all encrypted data.
- **Uploads** — `core/storage/app` (the local disk root is `storage_path('app')`),
  or the S3/CDN bucket if offload is enabled.

### Daily backup

Cron on the app host, e.g. 02:30 daily:

```bash
mysqldump --single-transaction --quick --routines \
  -u <DB_USERNAME> -p'<DB_PASSWORD>' <DB_DATABASE> \
  | gzip > /var/backups/mbc/db-$(date +\%F).sql.gz
```

`--single-transaction` gives a consistent InnoDB snapshot without locking. Retain
locally, and copy off-box — a backup on the same disk as the DB dies with the
disk. Copy `.env` into the same off-box location, encrypted. Weekly, confirm the
file exists, size is non-trivial, and `gzip -t` exits 0.

### Restore drill

Restore into a **scratch** database, never over production:

```bash
mysql -u root -p -e "CREATE DATABASE mbc_restore_test;"
gunzip < /var/backups/mbc/db-<date>.sql.gz | mysql -u root -p mbc_restore_test
# point a scratch env (same APP_KEY) at mbc_restore_test, then:
cd core
php artisan --env=restore-test tinker --execute "
  echo 'users='.\App\Models\User::count().PHP_EOL;
  echo 'orders='.\App\Models\Order::count().PHP_EOL;
"
mysql -u root -p -e "DROP DATABASE mbc_restore_test;"
```

Pass criteria: counts within expected range of production **and** an `encrypted`
column (a 2FA secret or BYOK credential) decrypts with the backed-up `APP_KEY`.
If it does not decrypt, the `.env`/`APP_KEY` backup is out of sync with the DB
backup — fix that before you need it.

### Disaster recovery order

1. Provision DB host, restore the latest good dump.
2. Restore `core/.env` with the matching `APP_KEY`.
3. Restore uploads/bucket.
4. `php artisan migrate --force` (the dump may predate the latest release), then
   `config:cache` + `route:cache`.
5. Smoke test: log in (2FA challenge appears), load a menu, place a test order,
   check a wallet balance, confirm a webhook delivers.
