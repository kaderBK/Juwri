7
# Hosting juwri: choose a profile, install, and move

This is the owner's guide. It has four parts: choosing a profile, installing on shared hosting, installing on a cPanel
VPS, and moving from shared hosting to a VPS. Docker has its own guide (see the last section).

Terms: "the app folder" is `core/` inside the repo. Every `php artisan ...` line below is run inside it.

<a id="hosting-profile"></a>

## 1. Choosing a profile

One setting, `HOSTING_PROFILE` in `core/.env`, picks the profile. It only sets defaults. Any explicit setting you put in
`.env` (for example `QUEUE_CONNECTION`) still wins. If `HOSTING_PROFILE` is unset or unknown, juwri behaves as `docker`.

| | shared | cpanel-vps | docker |
|---|---|---|---|
| Host type | cPanel, no root | cPanel/WHM with root | Linux server with Docker |
| Queue | database, drained by the minute cron | database, run by supervisor/systemd workers | container workers |
| Worker mode | `cron` | `supervisor` | `container` |
| Heavy queue max time | 600 s | 1800 s | 1800 s |
| Cache / session | file | file | file |
| Redis | not available | optional | optional |
| Live screens (broadcasting) | off, screens poll | off, screens poll | off by default |
| Backup engine (`BACKUP_DUMP_ENGINE`) | `php` (no mysqldump needed) | `auto` (mysqldump when present) | `auto` |
| Scheduler | cron | cron | container |

When to pick each:

* **shared**: your first months, low volume, no root access. Cheapest and simplest. Limits are in section 2.
* **cpanel-vps**: you outgrow shared (jobs killed by the host, slow queue, want Redis), and you have WHM and root.
  Section 3 is the install, section 4 is the move.
* **docker**: you run your own Linux server and prefer containers.

Check any host at any time: `php artisan juwri:preflight --profile=shared` (or `cpanel-vps`, `docker`). It says plainly
what is missing. Add `--no-web` to skip the web PHP version probe.

### Is my shared plan enough?

Short answer: a small shop on shared hosting usually sees one to five requests a second, and a shared plan handles that. Move to a VPS when your busy hour regularly goes past the numbers below, or when one of the triggers below happens.

Measured on one quiet cloud box with a setup like a shared plan (nginx + PHP-FPM, MySQL, file cache and sessions, database queue, the web part limited to 1 CPU and 1 GB), 20 seconds per step. "Last good" is the highest rate where the 95th-percentile answer stayed under 1 second with under 1% errors and at least 90% of the offered traffic served. Rates are per second: journeys for browse and checkout (3 requests each), plain requests for the kitchen-screen/dashboard polling and POS orders.

| Traffic | 4 PHP children: last good, first bad | 8 PHP children: last good, first bad |
|---|---|---|
| Browse (home, catalog, item) | 4 per second, 6 per second | 4 per second, 6 per second |
| Checkout (item, quote, order) | 4 per second, 6 per second | 4 per second, 6 per second |
| Kitchen screen / dashboard polling | 6 per second, 8 per second | 4 per second, 6 per second |
| POS orders | 8 per second, 12 per second | 8 per second, 12 per second |

Same 1 CPU / 4 PHP children, with limits:

| Traffic | 20 entry processes: last good, first bad | 512 MB memory: last good, first bad |
|---|---|---|
| Browse | 4 per second, 6 per second | 6 per second, 8 per second |
| Checkout | 3 per second, 4 per second | 4 per second, 6 per second |
| Kitchen screen / dashboard polling | 6 per second, 8 per second | 6 per second, 8 per second |
| POS orders | 8 per second, 12 per second | 8 per second, 12 per second |

- With an entry-process limit of 20 the host answers 508 once more than 20 requests are in flight; the 508 errors start at the first bad step (errors 8% at 6 browse journeys/s, 34% at 8, over 50% above 12) instead of just getting slow, so a 20-process plan fails earlier and louder than a plan without the limit.
- Memory: 4 PHP children peaked at about 140-230 MB, so 512 MB is enough for the same rates as 1 GB. 8 children peaked at about 450 MB on browse (close to a 512 MB limit) and give no more traffic on 1 CPU, so keep 4 children on small plans.
- At 256 MB the host killed the web process for running out of memory during the run (a few errors, slower answers), so do not go below 512 MB.
- Measured 2026-10-19, 20 seconds per step, on a shared cloud box that was not fully quiet (other jobs ran, load about 10 on 16 cores), so treat the numbers as +/- one step.

What this shows: on 1 CPU the CPU is the limit, so 8 children are no better than 4. Past the first bad step the shop gets slow (answers of several seconds) before it gives errors; without a limit no errors appeared in the first runs. For comparison, an earlier run on a big 16-core box with 16 children was fine at 30 item requests a second (item p95 76 ms) and saturated at 90+ requests a second (p95 about 11.5 s, 2-6% errors); the Octane (VPS) profile served that same step at p95 about 380 ms. That earlier run was on a noisy box.

Move to the cPanel VPS profile when any of these is true:

- Your busy hour sustains more than the "last good" rate in the table (for example more than about 4 browse or checkout journeys a second).
- The host sends 503 or 508 "resource limit" emails.
- The queue backlog age keeps growing, or the cron drain falls behind.
- Backups fail on time or memory.

The steps are in [section 4](#4-moving-from-shared-to-a-cpanel-vps).

## 2. Shared hosting install (no root)

What works: the shop, POS, orders, payments, ZATCA reporting, email, backups. What does not: Studio PDF/thumbnail
export (needs Node and Chrome), the Marketplace directory import (needs DuckDB), the voice bridge (needs always-on
workers), and live push updates (screens poll instead).

### 2.0 Step 0: run the host check page (then delete it)

The release ships `juwri-check.php` next to `index.php`: a plain PHP page (no framework, no database) that tells you,
before you install, whether this host can run Juwri. It shows the PHP version against 8.4 (with the MultiPHP steps if it
is older), the required extensions, the disabled functions Juwri cares about, `memory_limit` and `max_execution_time`,
which folders are writable, whether `.htaccess` rewrites work, and (optional) whether outbound HTTPS reaches ZATCA and
the payment providers.

1. Upload the files (section 2.2 step 1), then in File Manager create a file `juwri-check.token` next to
   `juwri-check.php`. Put 16 or more random characters in it (a password-manager password is fine).
2. Open `https://YOUR-DOMAIN/juwri-check.php`, type the same text, and run the check. Fix every FAIL before installing.
3. **Delete `juwri-check.php`, `juwri-check.token` and `juwri-check.attempts` when you are done.**

Guards: without the token file the page does nothing; the token is posted, never put in the URL, and compared in
constant time; 10 tries an hour; it refuses to run once `core/.env` has an `APP_KEY` (Juwri installed); it never prints
full paths or phpinfo; the web rules never serve the token or counter file.

<a id="php-version"></a>

### 2.1 Check PHP 8.4 first

Juwri needs **PHP 8.4** for both the website and cron. Your current host runs lsphp 8.3, so check before anything else.

1. cPanel > Software > **Select PHP Version** (CloudLinux PHP Selector) or **MultiPHP Manager**. Look for 8.4. If it
   is there, set the Juwri domain to 8.4 and tick these extensions: sodium, openssl, mbstring, iconv, curl, gd, zip, dom,
   libxml, simplexml, fileinfo, json, ctype, filter, tokenizer, session, zlib, pdo_mysql. intl, gmp, bcmath and redis
   are not needed.
2. Find the 8.4 command-line binary for cron. Usually `/opt/cpanel/ea-php84/root/usr/bin/php` or
   `/opt/alt/php84/usr/bin/php`. Run it with `--version` to confirm it prints 8.4.
3. If 8.4 is not offered, ask the host to enable it or pick another host. Juwri will not run on 8.3.

Other host needs: MySQL 8.0+ or MariaDB 10.6+ (one database, one user), `memory_limit` 256M or more (512M better),
`max_execution_time` 60 or more, upload and post size 64M, AutoSSL for https, outbound HTTPS (port 443) open (ZATCA,
payment gateways, S3 backups), and a way to send mail (the host's SMTP or an HTTPS mail API).

### 2.2 Install

1. <a id="document-root"></a>**Upload.** Put the whole repo in your account, for example `~/juwri`. Set the domain's Document Root to that folder
   (the one with `index.php` and `.htaccess`). The 403 checks are in `deploy/cpanel/README-doc-root.md`.
2. **Composer.** Skip this step if you used the release zip (section 2.2c): its `core/vendor/` is already inside. Otherwise
   in cPanel Terminal or SSH: `cd ~/juwri/core && composer install --no-dev --optimize-autoloader`. No terminal or
   composer? Download the release zip instead of running composer (next section). There is no JavaScript build step.
3. **.env.** `cp .env.example .env`, `chmod 600 .env`, then set at least:
   ```
   HOSTING_PROFILE=shared
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://YOUR-DOMAIN
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...
   MAIL_MAILER=smtp        # host SMTP, or an HTTPS API mailer
   TRUSTED_PROXIES=        # empty unless Cloudflare or the host proxy sits in front
   ```
   Then `php artisan key:generate`. **Copy APP_KEY to your password manager right now** (see section 4, step 5).
4. **Migrate.** `php artisan migrate --force` (plus any seeders the release notes name). Never load someone else's
   production database dump.
5. **No `storage:link`.** Do not run `php artisan storage:link`. On this layout the web root is the repo root, so uploads
   already live in the real folder `~/juwri/storage/`, and `.htaccess` serves it safely (no PHP/HTML/JS runs from it, a
   missing file gives 404). `storage:link` tries to create `~/juwri/storage` as a symlink, but that folder already
   exists, so it fails. Just make `core/storage/`, `core/bootstrap/cache/` and `storage/` writable by your account.
6. <a id="cache-session-queue-drivers"></a>**Cache.** `php artisan config:cache && php artisan route:cache && php artisan view:cache`. Repeat after every
   `.env` change or upload, followed by `php artisan queue:restart`.
7. <a id="cron-and-scheduler"></a>**Cron.** cPanel > Cron Jobs, one line, every minute, with the full PHP 8.4 path (a bare `php` is often 8.3):
   ```
   * * * * * cd ~/juwri/core && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   This one line also drains the queue: every minute the scheduler runs `queue:work --stop-when-empty --max-time=50`,
   plus a separate drain for the heavy queue. No supervisor, no daemon needed. If the host disables `proc_open` (common),
   juwri notices and runs those commands inside the one cron process instead of starting child processes; nothing for you
   to set. The CLI line above is the preferred
   way. Only if the host forbids running PHP from cron (rare) use the HTTP trigger: set `CRON_TOKEN` to a long random
   string and call it every minute with the secret in a header (never in the URL, URLs end up in access logs):
   ```
   * * * * * curl -fsS -m 60 -H "X-Juwri-Cron-Token: YOUR_CRON_TOKEN" https://YOUR-DOMAIN/cronjob >/dev/null
   ```
   One HTTP run is capped at `CRON_MAX_SECONDS` (default 50), overlapping calls get 409, and the endpoint allows
   6 calls a minute. The old `?token=` form is refused unless `CRON_ALLOW_QUERY_TOKEN=true` (deprecated; logs a
   warning). Use the HTTP trigger only as a last resort: on a host without `proc_open` it runs only the queue drains
   and the heartbeat, not the module tasks.
   The admin dashboard (Hosting health) and `juwri:preflight` warn when only this web cron runs without `proc_open`:
   add the CLI cron line above and the warning clears after its first run.
8. **Preflight.** `php artisan juwri:preflight --profile=shared`. Fix every FAIL it prints.
9. **Check.** `https://YOUR-DOMAIN/up` returns `{"status":"up"}`. Admin > System shows the scheduler heartbeat within
   2 minutes.

### 2.2a Update runbook (shared hosting)

Docker runs migrations on start (one container, under a database lock; the others wait); shared hosting does not. After every upload do these steps in order, as the account user
in cPanel Terminal, with the PHP 8.4 path (`P=/opt/cpanel/ea-php84/root/usr/bin/php`, `cd ~/juwri/core`):

1. **Maintenance on.** `$P artisan down --secret=SOMETHING` (you can still open `https://YOUR-DOMAIN/SOMETHING`).
2. **Backup.** `$P artisan platform:db-backup`, then download the newest file from `storage/app/backups/platform/`
   (or confirm the off-box S3 copy). Also copy `.env`.
3. **Upload and composer.** Upload or `git pull` the new code (or unpack the new release zip, section 2.2c, which needs no composer) (keep `.env` and `storage/`), then
   `composer install --no-dev --optimize-autoloader` (use `$P ~/composer.phar ...` if `composer` runs PHP 8.3).
4. **Migrate.** `$P artisan migrate --force`.
5. **Caches.** `$P artisan config:cache && $P artisan route:cache && $P artisan view:cache` (run `optimize:clear` first if
   a release changed `.env` keys).
6. **Restart workers.** `$P artisan queue:restart` (cron workers pick up the new code on their next run).
7. **Preflight.** `$P artisan juwri:preflight --profile=shared`. Fix every FAIL; WARN rows (mail records, sender domain)
   do not block.
8. **Maintenance off.** `$P artisan up`, then check `https://YOUR-DOMAIN/up`.

If step 4 or 7 fails, leave maintenance on, restore the dump from step 2 and the previous code, then ask for help.

### 2.2b Things that differ on shared hosting

* **Supervisor and `deploy/supervisor/` are for the cpanel-vps and docker profiles only.** Shared hosting drains the queue
  from cron.
* **The voice bridge is not available on shared hosting** (it needs always-on Octane and Reverb). Keep
  `VOICE_BRIDGE_ENABLED=0`.
* **Do not run `storage:link`** (see step 5 of the install above).
* **External uptime check.** Add one monitor on `https://YOUR-DOMAIN/up` (every 1 to 5 minutes, alert by email or
  phone). Any provider works (UptimeRobot, Better Stack, Uptime Kuma, a second host's cron `curl`); nothing in juwri
  depends on a particular one. `/up` does not touch the database, so also look at Admin > System once a day: the
  scheduler heartbeat must be under 5 minutes old.
* **Email.** Read `deploy/EMAIL.md` before you send real order mail.

### 2.2c Download the release zip instead of composer

The release zip is the whole Community app with `core/vendor/` already installed (`composer install --no-dev
--optimize-autoloader`), so the host needs neither composer nor node. It leaves out `rev/`, tests, `.git`, dev configs,
`node_modules`, the Enterprise package and every `.env`; only `core/.env.example` ships. The frontend needs no build
(there is no JS build step today; if one is added, the zip builder runs `npm ci` and the build and includes the result).

**Upload and unpack**

1. Download `juwri-community-X.Y.Z.zip` and its two files `.zip.sha256` and `.zip.sig` from the release page. A name
   ending `-TEST-signed` is a rehearsal build signed with the public test key: use it to practise, never in production.
2. cPanel > File Manager: upload the zip to your home folder, create `~/juwri`, then **Extract** into it. Or over SSH:
   `unzip juwri-community-X.Y.Z.zip -d ~/juwri`. The folder must end up holding `index.php`, `.htaccess` and `core/`.
3. Continue at step 1 of section 2.2 for the document root, then skip step 2 (composer) and do steps 3 to 9. `.env`
   is created from `core/.env.example`; the zip never contains a real one.
4. **Verify the upload.** From `~/juwri`, with the PHP 8.4 binary from section 2.1:
   ```
   /opt/cpanel/ea-php84/root/usr/bin/php deploy/release-verify/verify.php
   ```
   It checks the signature of `RELEASE-MANIFEST.json` and then the size and sha256 of every file against it: nothing
   missing, nothing changed, nothing extra. Your own `core/.env`, `storage/`, `core/storage/` and
   `core/bootstrap/cache/` are ignored. Exit 0 and `verify: OK` means complete and genuine. Exit 3 means the files are
   intact but only test-signed. Exit 1 lists the problems: upload again.
   To be sure the zip did not bring its own key, pass a copy of `release.pub` from the owner or juwri.com:
   `php deploy/release-verify/verify.php --pub=/path/to/release.pub`. To check the zip itself before unpacking, run
   `sha256sum -c juwri-community-X.Y.Z.zip.sha256` (the `.sig` holds the Ed25519 signature of that hash).
5. Delete the zip from the account when done. After each update, upload and unpack the new zip over the old folder
   (keep `core/.env` and `storage/`), run `verify.php` again, then `php artisan migrate --force`, the three cache
   commands and `php artisan queue:restart`.

**Build it (maintainers, on a runner, never on a Mac)**

```
distribution/release/build_release.sh --version=X.Y.Z --test-key              # rehearsal, signed with the public test key
distribution/release/build_release.sh --version=X.Y.Z --key-file=/path/to/key # official, with the owner's key (RB-09)
```

Needs PHP 8.4 with sodium, composer, git and unzip. It builds only committed content of `--ref` (default HEAD), writes
`dist-out/juwri-community-X.Y.Z[-TEST-signed].zip` plus `.sha256` and `.sig`, and finishes by unpacking the zip and
running the verifier on it. The zip is reproducible: the same commit gives the same bytes. `release.pub` is still the
TEST key until the owner replaces it (RB-09); `--test-key` refuses to run after that. Enterprise is not in this zip: it
ships as the separate signed `juwri-enterprise` package (`build_enterprise.sh`).

### 2.3 Backups on shared hosting

The profile sets `BACKUP_DUMP_ENGINE=php`, so the daily `php artisan platform:db-backup` (scheduled 02:15) writes the
dump with PHP itself and does not need `mysqldump` or `proc_open`. Dumps go to `storage/app/backups/platform/` and are
kept `BACKUP_KEEP_DAYS` days (default 30). A copy on the same disk does not protect you from losing the account, so set
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` (and `AWS_ENDPOINT` for Backblaze, Wasabi or MinIO) for the
off-box copy. Also keep the host's own cPanel backups switched on as a second layer, and do a restore drill every quarter,
once from the bucket: `php artisan platform:db-restore-check --force --from-s3`. Details and per-provider settings:
`docs/ops/backup-restore.md` ("Off-box copy").

Keep copies of these outside the host, in a password manager: `APP_KEY`, the ZATCA private key and certificate, VAPID
keys, and any signing key.

### 2.4 Limits to expect

| Limit | Effect | What to do |
|---|---|---|
| No long-running processes | No Reverb, no queue daemon, no voice bridge | Queue is drained by cron; screens poll. Leave `VOICE_BRIDGE_ENABLED=0`. |
| Cron runs at most once a minute | A queued job waits up to about a minute | Fine for launch. ZATCA's 24-hour window is far longer. |
| Host kills long cron processes (often 5 to 15 minutes) | Big backups or imports can be cut off and retry | The shared profile caps the heavy queue at 600 s. Lower `QUEUE_HEAVY_MAX_TIME` if your host kills sooner. Keep `QUEUE_HEAVY_RETRY_AFTER` above it. |
| `proc_open`/`exec` often disabled | No Studio export, no DuckDB import | Those features are off here. Backups still work (PHP engine), and the cron scheduler runs its commands inside the cron process. The Studio PDF export answers "not available" instead of failing. |
| No Redis | Cache, sessions and locks use files | Fine on one server. |
| Web timeout 30 to 120 s | Long admin actions can be cut off | Exports and backups already run as queued jobs. |
| Outbound SMTP often blocked | Mail fails | Use the host's mail server or an HTTPS mail API. |
| Shared CPU | Lower throughput | Fine for launch volumes. Plan the move in section 4 when stores grow. |

The ZATCA production steps use plain `php artisan` in cPanel Terminal. "Restart workers"
there means `php artisan queue:restart`.

## 3. cPanel VPS install (WHM plus root)

Same app, but you control the server, so queue workers run all the time and cron is a real cron.

1. **PHP 8.4.** WHM > Software > EasyApache 4. Add the profile package **ea-php84** and these extensions: ea-php84-php-
   mysqlnd, -mbstring, -gd, -zip, -curl, -xml, -sodium, -fileinfo, -iconv, -opcache (plus -intl and -bcmath if you
   like; not required). Provision. Then WHM > MultiPHP Manager: set the Juwri domain to ea-php84 (PHP-FPM). In
   MultiPHP INI Editor set memory_limit 512M, max_execution_time 60, upload_max_filesize and post_max_size 64M.
   The CLI binary is `/opt/cpanel/ea-php84/root/usr/bin/php`.
2. **Account, database, TLS.** Create the cPanel account and domain. Create the MySQL database and user (cPanel >
   MySQL Databases). Run WHM > SSL/TLS > Manage AutoSSL for the domain and confirm the padlock.
3. **Code and .env.** Upload the repo as in section 2.2 steps 1 to 4, with these differences: `HOSTING_PROFILE=cpanel-vps`
   in `.env`; use the ea-php84 binary for composer (`/opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/composer install
   --no-dev --optimize-autoloader`); run artisan as the account user, never as root (root-owned files in `storage/`
   break the site).
4. **Real cron for the scheduler.** As the account user (`crontab -e`, or cPanel > Cron Jobs):
   ```
   * * * * * cd /home/ACCOUNT/juwri/core && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   The scheduler still runs the daily jobs. Its queue-drain fallback switches itself off while a worker daemon is
   alive, so the two do not fight over jobs.
5. <a id="queue-workers"></a>**Queue workers.** Pick supervisor OR systemd, not both.

   Supervisor (`/etc/supervisor/conf.d/juwri-worker.conf`; install supervisor first with `dnf install supervisor` or
   `apt install supervisor`, `systemctl enable --now supervisord`). Do not put `--stop-when-empty` in these commands:
   its absence is how juwri knows a daemon is running.
   ```ini
   [program:juwri-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=/opt/cpanel/ea-php84/root/usr/bin/php /home/ACCOUNT/juwri/core/artisan queue:work --queue=default --sleep=1 --tries=3 --max-time=3600
   directory=/home/ACCOUNT/juwri/core
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   user=ACCOUNT
   numprocs=2
   redirect_stderr=true
   stdout_logfile=/home/ACCOUNT/juwri/core/storage/logs/worker.log
   stdout_logfile_maxbytes=50MB
   stdout_logfile_backups=10
   stopwaitsecs=300

   [program:juwri-worker-heavy]
   process_name=%(program_name)s_%(process_num)02d
   command=/opt/cpanel/ea-php84/root/usr/bin/php /home/ACCOUNT/juwri/core/artisan queue:work heavy --queue=heavy --sleep=1 --tries=3 --max-time=3600
   directory=/home/ACCOUNT/juwri/core
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   user=ACCOUNT
   numprocs=1
   redirect_stderr=true
   stdout_logfile=/home/ACCOUNT/juwri/core/storage/logs/worker-heavy.log
   stdout_logfile_maxbytes=50MB
   stdout_logfile_backups=10
   stopwaitsecs=1900
   ```
   Start: `supervisorctl reread && supervisorctl update && supervisorctl start juwri-worker:* juwri-worker-heavy:*`.
   After every deploy: `supervisorctl restart juwri-worker:* juwri-worker-heavy:*`. (A ready-made file with the same
   shape is `deploy/supervisor/juwri-worker.conf`.)

   systemd (tested units ship in `deploy/systemd/`: `bash deploy/systemd/install.sh ACCOUNT` as root installs the
   templated worker `juwri-queue@default` / `juwri-queue@heavy` plus an optional `juwri-scheduler.timer`; after a
   deploy run `systemctl reload 'juwri-queue@*'`. First-day checks for the server itself, which a container cannot
   test (AutoSSL, EasyApache, CSF ports, mail DNS, backups, monitoring, log rotation, preflight): see
   `deploy/VPS-FIRST-DAY.md`. The inline example below is the minimal form of the same idea):
   ```ini
   [Unit]
   Description=juwri queue worker %i
   After=network.target mysqld.service

   [Service]
   User=ACCOUNT
   Group=ACCOUNT
   WorkingDirectory=/home/ACCOUNT/juwri/core
   ExecStart=/opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work --queue=default --sleep=1 --tries=3 --max-time=3600
   Restart=always
   RestartSec=3
   TimeoutStopSec=300

   [Install]
   WantedBy=multi-user.target
   ```
   And a heavy worker, `/etc/systemd/system/juwri-worker-heavy.service`, the same as above but with
   `ExecStart=... artisan queue:work heavy --queue=heavy --sleep=1 --tries=3 --max-time=3600` and
   `TimeoutStopSec=1900`; enable it with `systemctl enable --now juwri-worker-heavy`. After every deploy:
   `systemctl restart 'juwri-worker@*' juwri-worker-heavy` (or `php artisan queue:restart`).
6. **Redis (optional).** Not required. If you want it: install Redis on the server, add the PHP redis extension
   (`ea-php84-php-redis` or PECL), then set `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `REDIS_HOST=127.0.0.1` and, if
   you want, `QUEUE_CONNECTION=redis`. Restart the workers. Skip this until you see a need.
7. **Caches and migrate.** `php artisan migrate --force`, then `config:cache`, `route:cache`, `view:cache`. No
   `storage:link` here either if the web root is the repo root; follow `deploy/cpanel/README-doc-root.md`.
8. **Preflight.** `php artisan juwri:preflight --profile=cpanel-vps`. Fix every FAIL.
9. **Backups.** `BACKUP_DUMP_ENGINE` defaults to `auto`, which uses `mysqldump` when it exists (it does on a VPS).
   Daily `php artisan platform:db-backup` runs from the scheduler. Set the S3 keys for the off-box copy and keep WHM
   backups on as a second layer. Same quarterly restore drill.

## 4. Moving from shared to a cPanel VPS

Plan about two hours, off-peak. Do steps 1 to 3 days ahead. Do not delete anything on the shared host until the new
server has run cleanly for a week: that is your rollback.

> **WARNING: KEEP APP_KEY.** `APP_KEY` encrypts stored secrets (payment provider credentials, tokens, and other
> encrypted fields). If the new server has a different `APP_KEY`, all of that encrypted data becomes unreadable and
> cannot be recovered. Copy the old `.env` as is. Never run `php artisan key:generate` on the new server for this
> move. Keep the same for any other keys: the ZATCA private key and certificate, VAPID keys, signing keys. These live in
> `.env` or `core/storage`, so they travel with the files below, but check each is present before you cut over.

Before the day:

1. **Lower the DNS TTL.** At your DNS provider, set the domain's A record TTL to 300 seconds (5 minutes), at least
   a day before. Old resolvers need the old TTL to expire first.
2. **Build the new server** with section 3, steps 1 to 2 and 5 to 6 only (PHP, account, database, TLS, and the
   worker config files written). Leave the app files for step 3 of the day. Do NOT run `supervisorctl reread` or `update`
   yet: supervisor refuses a config whose log folder (`/home/ACCOUNT/juwri/core/storage/logs/`) does not exist yet, and the
   workers have nothing to run until the files are copied. The `supervisorctl reread && supervisorctl update` line is step 7
   of the day. For AutoSSL to pass before DNS moves, you can test
   the site by editing your own computer's hosts file to point the domain at the new IP.
3. **Take a practice run** of steps 4 to 9 with the hosts-file trick, so the real cutover has no surprises.

On the day, in this order:

1. **Let the queue empty, then maintenance mode on the shared host.** Maintenance mode also pauses the queue: while
   the site is down, no queue worker (and not the cron drain either) runs any job, and `php artisan queue:work
   --stop-when-empty` by hand does nothing unless you add `--force`. So first wait a minute or two with the site still up
   (Admin > System shows the queue size; the cron drain empties it), then run `php artisan down --secret=SOMETHING`.
   Customers now see the maintenance page. A job still waiting when you switch it off is not lost: it is in the
   database dump, travels with it, and the new server runs it, once, after step 9's `php artisan up`.
2. **Final backup.** Run `php artisan platform:db-backup`, and also take a plain dump:
   `mysqldump --single-transaction --routines DBNAME | gzip > final.sql.gz` if mysqldump exists, or download the dump
   written in `storage/app/backups/platform/`. Download it, and check `gzip -t final.sql.gz`.
3. **Copy the files.** Copy the whole repo folder, including `core/storage/`, the root `storage/` uploads folder, and
   `core/.env`, to the new server's account (rsync or the cPanel File Manager zip):
   `rsync -a --info=progress2 OLDUSER@OLDHOST:~/juwri/ /home/ACCOUNT/juwri/`. Fix ownership: files must belong to the
   account user.
4. **Restore the database** on the new server: `gunzip -c final.sql.gz | mysql -u NEWUSER -p NEWDBNAME`. Check row
   counts of a few tables (orders, users) against the old server.
5. **Fix .env, keep the keys.** Use the copied `.env`. Change only: `DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD` to the
   new database, and `HOSTING_PROFILE=cpanel-vps`. **Do not touch `APP_KEY`** or any other key. Optionally
   `QUEUE_HEAVY_MAX_TIME` can go back to its default (1800).
6. **Composer and caches.** If the files came from a git checkout, run `composer install --no-dev --optimize-autoloader`
   with the ea-php84 binary; if they came from the release zip (section 2.2c), `core/vendor/` is already inside, skip it.
   Then `php artisan config:clear` (the copied config cache still holds the old host's paths and database),
   `php artisan migrate --force` (should say nothing to migrate), then the three cache commands.
7. **Swap the cron drain for workers.** On the new server: install the real cron line (section 3, step 4) and start
   the supervisor or systemd workers (section 3, step 5: `supervisorctl reread && supervisorctl update && supervisorctl
   start juwri-worker:* juwri-worker-heavy:*`). Do NOT copy the shared host's cron job over. The new server is still in
   maintenance mode, so the workers idle until step 9's `php artisan up`; that is expected. The scheduler
   fallback drain turns itself off when a worker daemon is alive, so the queue is never read twice. On the old host,
   delete its cron line now so nothing there runs against the old database while you cut over.
8. **Preflight.** `php artisan juwri:preflight --profile=cpanel-vps` must show no FAIL. While the copied maintenance flag is
   still on, the Cron heartbeat and Queue workers rows show WARN, not FAIL (both pause by design); run the preflight
   once more after step 9's `php artisan up` and a minute's wait: both rows must then be OK.
9. **Cut over DNS.** Point the A record at the new server's IP (TTL 300 means most visitors follow within minutes).
   Make sure AutoSSL has issued the certificate for the domain on the new server (WHM > Manage AutoSSL > run check).
   Then run `php artisan up` on the new server (the maintenance state was copied with the files, so it will
   still show the maintenance page until you do). Leave the old host in maintenance mode. Logged-in users stay logged in:
   the sessions are files in `core/storage/framework/sessions/` and travelled with the copy (same `APP_KEY`). Anyone with
   the old host's cached DNS keeps seeing the maintenance page, not the old shop.

Verify (do all four, in order):

* **Health:** `curl https://YOUR-DOMAIN/up` returns `{"status":"up"}`; Admin > System shows the scheduler heartbeat
  and a live queue worker within 2 minutes.
* **Test order:** place a small test order from the storefront; it appears in Admin and the confirmation email arrives.
* **Queue job:** watch it run: `supervisorctl status` (or `systemctl status 'juwri-worker@*'`) shows the workers
  running, and `tail -f core/storage/logs/worker.log` shows jobs processing. The email above is itself a queued job.
* **ZATCA test:** run `php artisan fiscal:zatca-check --strict` (every row OK), then issue one test sale and expect
  `accepted` in Owner > Fiscal. The server's outbound IP changed, so this also proves port 443 to Fatoora works.

After a clean week: raise the DNS TTL back, switch the old account off, and keep the final dump somewhere safe.

**Rollback** (any time before you delete the old host):

1. On the new server put the site in maintenance mode (`php artisan down`) and stop everything that touches the data:
   `crontab -r` for the account and `supervisorctl stop juwri-worker:* juwri-worker-heavy:*`. Do this first: the new
   server's job queue is part of the dump below, and if its workers kept running they would run those jobs a second
   time.
2. If customers placed orders or uploaded files on the new server in the meantime (skip this step if not), carry that
   work back while the old host is still in maintenance mode. Take a fresh dump of the new database
   (`mysqldump --single-transaction --routines NEWDB | gzip > rollback.sql.gz`, plus `php artisan platform:db-backup` as a
   second copy) and restore it into the old database (`gunzip -c rollback.sql.gz | mysql -u OLDUSER -p OLDDBNAME`). The
   database rows point at files, so copy those back too: `rsync -a /home/ACCOUNT/juwri/storage/ OLDUSER@OLDHOST:~/juwri/storage/`
   and the same for `core/storage/app/`. Do not copy `core/storage/framework/down` back.
3. Point the A record back to the old shared host (TTL is still 300, so this is quick).
4. On the old host, re-add the cron line (section 2.2 step 7) and run `php artisan up`.
5. Logins: sessions created on the new server only exist there, so those users sign in again on the old host (copy
   `core/storage/framework/sessions/` back as well if you want to spare them). Sessions from before the move still work.
6. Find out what failed, fix it on the new server, and try again another day.

## 5. Docker

Several containers share one database (`app`, `queue`, `queue-heavy`, `scheduler`, `reverb`). Migrations are owned by
exactly one of them: the `app` container with `JUWRI_MIGRATE=1` runs `php artisan juwri:migrate-gate --run` under a
database advisory lock (MySQL `GET_LOCK`, PostgreSQL `pg_advisory_lock`), so two `app` replicas never race. Every other
role runs `juwri:migrate-gate --wait` first, so a worker never opens against a half-migrated database, and refuses to
start when the database is newer than this image (version skew, exit code `3`). `JUWRI_MIGRATE_TIMEOUT` (default `900`
seconds) bounds the wait; on timeout the container exits `1` and Docker restarts it. Upgrades are `docker compose ...
up -d`: the migrating container does the work, the rest wait. Set `JUWRI_MIGRATE=1` on exactly one `app` service even
though the lock makes several safe. The full procedure, exit codes, and troubleshooting rows are in
`docker/README.md` (sections "Upgrades and migrations" and "If something is wrong"); `docker/VOICE-BRIDGE.md` is the
optional voice add-on; backups are in `docs/ops/backup-restore.md`. Set `HOSTING_PROFILE=docker` or leave it unset.


<a id="mail-dns"></a>

## 6. Mail DNS (SPF and DMARC)

Orders, receipts and password resets are sent by email, so the sending domain needs DNS records or mail lands in spam.

1. **SPF.** One TXT record on the domain that includes your mail host, for example `v=spf1 include:YOUR-MAIL-HOST ~all`.
   Only one SPF record per domain.
2. **DKIM.** Turn on DKIM at your mail host (cPanel > Email Deliverability) and publish the record it gives you.
3. **DMARC.** A TXT record on `_dmarc.YOUR-DOMAIN`, for example `v=DMARC1; p=none; rua=mailto:you@YOUR-DOMAIN`.
   Start with `p=none`, check the reports, then tighten.
4. The admin health card shows an SPF or DMARC warning until the records resolve.

## 7. Manual refunds (2Checkout, Qrpay)

`TwoCheckoutAdapter` and `QrpayAdapter` both return `supportsRefund() === false` — there is no
provider-side refund call we can make on your behalf, so the in-app **Refund to original** button
records a *tracked manual refund* in Juwri instead of moving the money. The customer must be made
whole in the provider's dashboard first; Juwri then records the refund so the order state and
reports stay honest.

This is a host/staff workflow, not a configuration toggle. Follow these four steps in order:

1. **Open the provider dashboard.** Log in to 2Checkout (`https://secure.2checkout.com`) or Qrpay
   (`https://app.qrpay.is`) with the same merchant account the gateway was connected under. Find
   the order by its transaction id (the value stored on `orders.payment_id`).
2. **Refund the payment.** Issue a full or partial refund in the provider dashboard, matching the
   amount and currency you promised the customer. Keep the provider's refund id / receipt number —
   you'll need it in step 4.
3. **Mark the order refunded in Juwri.** Open the order in the owner dashboard
   (`restaurants.orders.show`) and click the refund action — Juwri will record the refund and
   update the order state. The owner-facing order row also shows a one-line reminder on every
   order paid via 2Checkout or Qrpay so this is hard to miss.
4. **Note the provider refund id** in the order's admin note (or in your store's bookkeeping log)
   so support can match the Juwri-side record back to the provider-side record if a dispute
   opens.

When either adapter grows a real refund driver (i.e. `supportsRefund()` starts returning `true`),
remove the gateway from the manual-refund note in `core/resources/views/owner/orders/order-row.blade.php`
and this section becomes historical.
