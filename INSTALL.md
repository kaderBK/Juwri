# Installing juwri from zero

This guide installs the Community Edition on a Linux server (or your own machine) without Docker. Docker has its own
guide (`docker/README.md`); shared hosting and cPanel VPS are in `deploy/HOSTING.md`. The commands below were run on a
clean server (PHP 8.4, Composer 2, SQLite) from an export of this repository and finished without errors.

## 1. Requirements

- PHP 8.4 or newer with the extensions `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `intl`, `mbstring`,
  `openssl`, `simplexml`, `sodium`, `tokenizer`, `xml`, `zip` and `zlib`, plus the PDO driver of your database
  (`pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`). `composer install` stops and names any missing one.
- Composer 2.
- A database: MySQL 8.4 or PostgreSQL 18 for a real site. SQLite works for a trial.
- Node.js 20 and npm only if you run the browser journeys or Studio export. The owner interface needs no front-end build.
- A web server whose document root is the repository root (not `core/`): `index.php` there is the front controller and
  the shipped `.htaccess` / `web.config` route requests and block direct access to `core/`. Examples for OpenLiteSpeed,
  Caddy and cPanel are in `deploy/OLS-VHOST.md`, `docker/Caddyfile` and `deploy/HOSTING.md`. `php artisan serve` works for a trial.
- A way to run a queue worker and the scheduler (section 7).

## 2. Get the code and install dependencies

```bash
git clone <repository-url> juwri
cd juwri/core
composer install --no-interaction --prefer-dist
```

## 3. Configure `.env`

```bash
cp .env.example .env
chmod 600 .env
php artisan key:generate --force
```

Edit `.env`:

- `APP_URL`: the exact address people will use, for example `https://shop.example.com`. Other host names are refused.
- `APP_ENV=production` and `APP_DEBUG=false` for a real site. With `APP_ENV=local` the install also loads demo stores and
  demo users (useful for a trial, never for a live site).
- Database: `DB_CONNECTION` (`mysql`, `pgsql` or `sqlite`), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD`. For SQLite set `DB_CONNECTION=sqlite`, `DB_DATABASE=/absolute/path/juwri.sqlite` and create the empty file
  (`touch /absolute/path/juwri.sqlite`).
- Mail: `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`
  (`deploy/EMAIL.md`). The sample file points at a local test mail server that does not exist on a new machine.
- Plain `http://` is only for a trial on this machine (`http://localhost` or `http://127.0.0.1:<port>`). With
  `APP_ENV=production` the session cookie is marked secure, so sign-in over `http://` on any other address silently fails: use
  HTTPS for a real site, or set `SESSION_SECURE_COOKIE=false` for an http-only trial.
- `QUEUE_CONNECTION=database` is the default and needs nothing else.

Never commit `.env` and keep `APP_KEY` backed up: stored credentials are encrypted with it.

## 4. Create the tables and the reference data

```bash
php artisan migrate --seed --force
php artisan storage:link
```

`--seed` loads the reference data (cuisines, plans, languages, templates). In a non-production environment it also loads
demo content; in production it loads reference data only (no users, no stores).

## 5. Check the machine

```bash
php artisan juwri:preflight --profile=docker --no-web
```

It lists what is missing in plain words (`--profile=shared` or `cpanel-vps` for those hosts). Fix every FAIL row.
WARN rows (for example mail on `log`, Studio export, directory import) switch a feature off but do not block the install.

## 6. First admin

Nobody is an admin on a fresh install. Opening the site address goes to login; create the first account from the link there, or open `/signup` directly. Open `APP_URL/signup`, register your account, then promote it once, using the
e-mail you signed up with:

```bash
php artisan tinker --execute="DB::table('users')->where('email', 'you@example.com')->update(['user_type' => 'admin']);"
```

Sign in again if the admin link does not show. `APP_URL/admin` is the platform admin area; `APP_URL/dashboard` is your own store dashboard, where you
create your first store (`APP_URL/stores/create`: title, address, description, a main image, a cover image and a colour are
required; the store page is then `APP_URL/<slug>`). For a production site turn on two-factor sign-in for staff
(`deploy/PROD-HARDENING-CHECKLIST.md`).

## 7. Queue worker and scheduler

juwri sends mail, runs imports and exports and many housekeeping jobs through the queue and the scheduler. Both must run.

Scheduler: one cron entry, every minute:

```cron
* * * * * cd /path/to/juwri/core && php artisan schedule:run >> /dev/null 2>&1
```

Queue worker, kept alive by supervisor or systemd (examples in `deploy/supervisor/` and `deploy/systemd/`):

```bash
php artisan queue:work --queue=default
php artisan queue:work heavy --queue=heavy   # long jobs, optional second worker
```

On shared hosting without long-running processes, drain the queue from cron instead
(`deploy/HOSTING.md`, profile `shared`).

Quick test: sign up a user, then `php artisan queue:work --stop-when-empty` should process the welcome mail jobs and exit;
`php artisan schedule:list` shows every scheduled task.

## 8. Storage and files

User uploads go to `FILESYSTEM_DISK` (default `local`, under `core/storage/app`). Make `core/storage` and
`core/bootstrap/cache` writable by the web server user, and make sure the web server cannot serve files from `core/`
(Apache and IIS are covered by the shipped rules; on nginx or Caddy deny it explicitly, see `docker/Caddyfile`). S3-compatible storage is configured with the `AWS_*` variables in `.env.example`.

## 9. Optional pieces

- Legal pages (terms, privacy and others) are off by default (`LEGAL_PAGES=false`). They are JUWRI LTD's documents for
  juwri Cloud; write your own for your site.
- Backups: `docs/ops/backup-restore.md`. Upgrades: `docs/ops/upgrade-notes.md`.
- Production hardening, OpenLiteSpeed, cPanel, systemd and Docker: the `deploy/` and `docker/` folders.

## Trying it without a server

```bash
cd core
composer install && cp .env.example .env && php artisan key:generate
touch database/juwri.sqlite   # set DB_CONNECTION=sqlite and DB_DATABASE=<absolute path> in .env
php artisan migrate --seed
php artisan serve
```

If you serve from the repository root instead (`php -S 127.0.0.1:8099 -t . router.php`, preview only), `APP_URL` must be that
exact address.
