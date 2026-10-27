# juwri in Docker (FrankenPHP + Laravel Octane)

The self-host image runs the app in **Octane worker mode** on **FrankenPHP** (official image, PHP 8.4). The same image is
the web server, the queue worker and the scheduler; the command picks the role.

| Role | Command | What it runs |
|---|---|---|
| web (default) | `frankenphp run ...` | Caddy + the Octane worker script, health probe `GET /up` |
| queue | `queue` | `php core/artisan queue:work --queue=default` (a daemon, so the app's queue heartbeat sees it) |
| queue-heavy | `queue-heavy` | `php core/artisan queue:work heavy --queue=heavy` (long jobs, retry_after 1860 s) |
| scheduler | `scheduler` | `php core/artisan schedule:work` |

## Install from scratch (tested)

You need a Linux server (2 vCPU / 4 GB RAM is enough to start) with **Docker 24+ and the Compose plugin**
(`docker compose version`), and a copy of this repository (`git clone`). Everything below is run from the repository root.
Steps 1 to 6 were followed literally on a clean AWS server on 2026-10-07 with the Community edition (see "Status").

**1. Create your settings file.** It holds secrets: keep it out of git and out of the image.

```bash
cp docker/env.example docker/.env
```

**2. Fill in the secrets and your address.** (The compose file refuses to start without both database passwords, and
`APP_KEY` needs no running image: any 32 random bytes in base64 work.)

```bash
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|; \
        s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|; \
        s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(openssl rand -hex 16)|" docker/.env
```

Then edit `docker/.env` and set **`APP_URL`** to the exact address people will type, for example `https://shop.example.com`
(or `http://localhost:8080` to try it on your own machine). The app answers `400 Bad Request` to any other host name,
including the server's bare IP address, so always browse using `APP_URL`'s host. Keep `SERVER_NAME=:8080` when a TLS
proxy sits in front; set it to your domain instead to let the bundled Caddy get a certificate (publish ports 80 and 443
in `docker/compose.prod.yml` then). Fill the `MAIL_*` lines to let the app send e-mail. `JUWRI_MIGRATE=1` (set that way in `docker/env.example`; the compose file itself defaults to 0)
creates and updates the database tables every time the web container starts. See "Upgrades and migrations" for the
multi-container version (`JUWRI_MIGRATE_TIMEOUT` bounds the wait; `JUWRI_MIGRATE_WAIT=0` skips it for debugging containers
only).

**3. Build and start.**

```bash
docker compose -f docker/compose.prod.yml --env-file docker/.env up -d --build
```

The first build takes a few minutes. The first start runs the database migration (about a minute).

**4. Check that everything is healthy.**

```bash
docker compose -f docker/compose.prod.yml --env-file docker/.env ps     # app, db, queue x2, scheduler, reverb: "healthy"
curl -i http://localhost:8080/up                                         # HTTP 200 (add -H "Host: <APP_URL host>" if APP_URL is not localhost)
```

**5. Create your account and make it the platform admin.** Open `APP_URL/signup` in a browser and register. Nobody is an
admin on a fresh install, so promote your account once (use the e-mail you signed up with):

```bash
docker compose -f docker/compose.prod.yml --env-file docker/.env exec app \
  php artisan tinker --execute="DB::table('users')->where('email', 'you@example.com')->update(['user_type' => 'admin']);"
```

Sign in again: `APP_URL/admin` is the platform admin area, `APP_URL/dashboard` is your own store dashboard.

**6. Create your first store.** In the dashboard choose "Create store" (`APP_URL/stores/create`). Pick the store type,
a name and address (the web address, called the slug, is built from the name), a colour, and two images: the cover image
and the main image are required. Save. Your storefront is live at `APP_URL/<slug>`.

Day to day:

* `docker/compose.dev.yml` mounts the working tree and restarts the worker after every request.
* `docker/compose.prod.yml` is the production example: uploads and framework storage are volumes, two queue workers, a
  scheduler, and the optional live-updates server. Put your own TLS proxy in front (or use `SERVER_NAME`).
* Run any command in the app with `docker compose ... exec app php artisan <command>` (the working directory is the
  Laravel app). To run one before the stack exists: `docker run --rm <image> php artisan <command>`.
* No secret is ever in an image or in these files: configuration is the container's environment.
* Backups and restore: see `docs/ops/backup-restore.md`.

### If something is wrong

| Symptom | Cause and fix |
|---|---|
| `required variable DB_PASSWORD is missing` | Step 2 not done: both database passwords must be set before any `docker compose` command. |
| Every page answers `400 Bad Request` | You browsed with a host name that is not `APP_URL`'s host. Fix `APP_URL` or the address you use. |
| `app` keeps restarting, log says `Unsupported cipher or incorrect key length` | `APP_KEY` is empty or not 32 bytes in base64: redo step 2's `APP_KEY` line. |
| `app` is `unhealthy` right after start | The first migration is still running: wait about a minute, then `docker compose ... logs app`. |
| Worker / scheduler / Reverb keeps restarting with `N migration(s) still pending after Ns` | No app container has `JUWRI_MIGRATE=1`, or the one that does is not running. Set `JUWRI_MIGRATE=1` on exactly one `app` service and bring it up; everyone else will finish waiting once the migrations are applied (see "Upgrades and migrations"). |
| Worker / scheduler / Reverb (or another `app`) refuses to start with `version skew` | The database already has migrations this image does not have (a newer release ran on it). Run the newer image: never roll back the image without first restoring the matching database backup. |
| A language you just activated gives 404 at `/<code>/...` | Restart the web container: prefixed URLs are registered when it starts (see "Language URLs"). |

## Production defaults

`docker/env.example` ships production-safe values: `APP_ENV=production`, `APP_DEBUG=false`, header-only tokens,
strict passwords, secure cookies, signed webhooks only, an explicit `CORS_ALLOWED_ORIGINS`, and the guest storefront
page cache on (`STOREFRONT_FULLPAGE_CACHE=true`, `STOREFRONT_FULLPAGE_CACHE_TTL=300`). Set `APP_URL`,
`CORS_ALLOWED_ORIGINS` and `HEALTHZ_TOKEN`, then check the box:

    docker compose -f docker/compose.prod.yml --env-file docker/.env exec app php artisan ops:preflight

**Redis profile (recommended with real traffic).** The default stack keeps cache and sessions in files and the queue in
the database, so it needs nothing else. For cache + sessions + queue on Redis: in `docker/.env` uncomment the Redis block
(`QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `REDIS_HOST=redis`, a `REDIS_PASSWORD`), delete
the three file/database lines above it, add `COMPOSE_PROFILES=redis`, and `up -d` as usual. The `redis` service only
exists under that profile and publishes no host port. To use your own Redis instead, skip the profile and set `REDIS_HOST`.

**Port exposure.** The app port is published as `127.0.0.1:8080` (`APP_BIND` / `APP_PORT` in `docker/.env`), so only a
proxy on the same host reaches it. A published `0.0.0.0:8080` would let any LAN or internet peer talk to the app directly
and forge `X-Forwarded-For`. To expose it to a proxy on another machine set `APP_BIND` to the interface address that
proxy reaches (or `0.0.0.0`, then firewall port 8080 to the proxy only and list the proxy in `TRUSTED_PROXIES`).
The Reverb websocket port (when `BROADCAST_CONNECTION=reverb`) is published the same way: `127.0.0.1:8081`
(`REVERB_BIND` / `REVERB_SERVER_PORT`), for the TLS proxy on this host to forward as `wss`; set `REVERB_BIND` only if that
proxy runs elsewhere, and firewall the port to it.

## Production preflight

Check the running stack against the hardening checklist (exit 0 = every rule passes):
`docker compose -f docker/compose.prod.yml --env-file docker/.env exec app juwri-entrypoint php artisan ops:preflight`
(go through `juwri-entrypoint`: it gives the command the same `TRUSTED_PROXIES` default the web process has). `--cloud` adds the juwri Cloud rules.

## Behind a proxy (TRUSTED_PROXIES)

The app believes `X-Forwarded-For` / `-Proto` / `-Host` / `-Port` only from addresses in `TRUSTED_PROXIES`. Wrong in
one direction, every visitor shares the proxy's IP (one rate-limit bucket, the abuse guard blocks everyone at once) and
https is not detected (cookies, signed URLs and redirects use http). Wrong in the other, anyone can forge their IP.

- **Default (empty):** with `SERVER_NAME=:8080` (your TLS proxy in front) the image trusts loopback and private ranges
  only (`127.0.0.1/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, ::1, fc00::/7`), which covers nginx/Caddy/Traefik on
  the same host, the same docker network or the LAN. Then port 8080 **must not** be reachable from the internet except
  through that proxy (publish it as `127.0.0.1:8080:8080`, or firewall it). With `SERVER_NAME=your.domain` (bundled
  Caddy does TLS) nothing is trusted.
- **Never `*`**: any client could then claim any IP.
- **Cloudflare (proxied DNS):** list Cloudflare's ranges (https://www.cloudflare.com/ips-v4 and ips-v6), plus your local
  proxy if there is one between them, e.g. `TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,…,172.16.0.0/12`.
- **AWS ALB / other load balancer:** the VPC subnet(s) the balancer lives in, e.g. `TRUSTED_PROXIES=10.0.0.0/16`.
- **nginx on another public server:** its address, e.g. `TRUSTED_PROXIES=203.0.113.7`. nginx must set
  `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_set_header X-Forwarded-Proto $scheme;
  proxy_set_header X-Forwarded-Host $host;`.
- **`none`** trusts nothing (app reachable directly, no proxy).

Caddy itself passes the forwarded headers through to PHP unchanged; Laravel does the checking. If you also want Caddy's
own logs / `{client_ip}` to show the visitor, add the same list to Caddy:
`CADDY_GLOBAL_OPTIONS="servers { trusted_proxies static private_ranges }"` (or `static 173.245.48.0/20 …`).

Check after deploying: `docker compose … exec app php artisan tinker --execute 'echo json_encode(config("trustedproxy.proxies"));'`
and log in twice from two networks: rate limits are per visitor and the session cookie is marked `Secure`.

## Language URLs

Both language modes work in the Octane worker: the "language code in the URL" mode (`/ar/pricing`, the default) and the
cookie/session mode (`/lang/ar`, platform setting "language code in URL" off). Because a worker registers its routes once,
at start, the prefixed URLs exist for the languages that are **active when the container starts**: after you activate a
language in the admin area (Languages), or change the "language code in URL" setting, restart the web container
(`docker compose ... restart app`). A language you switch off answers 404 straight away, without a restart.

## What is and is not served

`docker/Caddyfile` mirrors the deny rules of `.htaccess`: nothing under `core/` (except `core/plugins/<id>/assets/`), no
dotfiles, no `rev/`, `integrations/`, `docs/`, `tools/`..., no `.php`, `.json`, `.md`, `.lock`, `.sql`... is served as a
static file; a missing file under `/storage/` is a 404 without booting the framework; `/index.php/<path>` is redirected
to `/<path>`. Everything else goes to the worker.

## Worker count and sizing

`FRANKENPHP_WORKERS` unset (the default) starts one Octane worker per CPU core the container may use (a `cpus:` limit is
honoured; 2 to 32). Rule of thumb: **one worker per vCPU, no more.** Measured on 16 vCPU (P6-39, busy store with 2,000
items, MySQL on the same box): 2 workers cannot carry a few requests a second; 8 workers saturate at 8 vCPU of PHP; 16 use
the whole box; 32 are no faster than 16. Each worker holds roughly 100-200 MB, so on a small server set it by memory
(`workers x 200 MB` must fit beside MySQL): `FRANKENPHP_WORKERS=4` in `docker/.env`.

Per-store daily cost caps (`storecap`, P6-74) and every rate limiter count in the cache: the cache driver MUST be `redis` or `database` in production. The `file` driver is not atomic, so concurrent requests can slip past a cap.

Drivers: the defaults (`CACHE_DRIVER=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database`) are right for ONE app
container; Redis for cache, session and queue made no measurable difference to latency or capacity on one box (identical
p95 at 16 workers). Use Redis (or a shared database) only when you run several app containers or hosts that must share
sessions and cache. The queue workers and the scheduler use well under 10% of a core at these rates.

What one box carries (p95 under 500 ms; mixed storefront + dashboard + KDS traffic, one busy store, cold carts):

| Box | Workers | Storefront journeys/s (item, quote, order) | Mixed "busy medium" profile |
|---|---|---|---|
| 4 vCPU / 8 GB | 4 | about 10 | not tested; expect about a quarter of the 16 vCPU figure |
| 8 vCPU / 16 GB | 8 | about 25 | about 35 requests/s |
| 16 vCPU / 32 GB | 16 | 40 (measured) | 69 requests/s (measured) |

The journey figure is about 3 to 4 journeys/s per vCPU, i.e. roughly 10 requests/s per vCPU for the cheap storefront
calls. A request costs: item 25 ms of CPU, quote 35 ms, place order 75 ms, KDS poll 45 ms, dashboard orders page 280 ms,
home page of a 2,000-item store 370 ms, catalogue call of that store 450 ms (a normal store's home page is about 70 ms).
Size by `sum(rate x cost)`: that many vCPU, plus about 15% for MySQL. The 4 and 8 vCPU rows are extrapolated from the
per-request costs, not measured. The detailed diagnosis is in the internal load-test notes.

## Octane is optional

php-fpm, Herd and `php artisan serve` keep working without Octane: `config/octane.php` returns only a stub when
`laravel/octane` is not installed, and every worker-mode step is a no-op outside a worker. The worker-safety audit is kept
in the internal run notes; the guard test is `tests/Feature/OctaneWorkerSafetyTest.php`.

## Live updates (Reverb) are optional and off by default

The orders list, the KDS and the POS refresh by polling. With Laravel Reverb they also refresh the moment something
changes; polling stays as the fallback, so a stopped or unreachable Reverb costs nothing but the speed-up.

1. In `docker/.env`: `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (random strings),
   `REVERB_HOST=reverb` (the app publishes to the `reverb` service inside the compose network).
2. The web container's Caddy already proxies the browser's WebSocket (`/app/<key>`, upgrade requests only) to the `reverb`
   service, so your TLS proxy needs no extra route: set `REVERB_PUBLIC_HOST` / `REVERB_PUBLIC_PORT` / `REVERB_PUBLIC_SCHEME` to
   the public address of the web container (the browser connects there; the dashboard CSP gets that one origin). Publishing
   the reverb port itself is optional.
3. Channels are private, one per store and screen (`store.{id}.orders`, `.kds`, `.pos`) and are authorized through
   `POST /broadcasting/auth` with the dashboard's own store-access rules. Events carry ids and a status only.

Tested end to end (by an internal live-TLS end-to-end script): a real browser behind an outer TLS proxy (Caddy with its
own CA) → this image in proxy mode (`SERVER_NAME=:8080`, default `TRUSTED_PROXIES`) → `reverb`. The owner's orders screen
opens `wss://<host>/app/<key>`, signs its channel through `/broadcasting/auth`, and a guest's new order appears through the
socket with the 25 s poll timer switched off; `/broadcasting/auth` refuses another tenant's store, crafted channel names and
guests. Set `REVERB_PUBLIC_HOST` to the public host name and keep `REVERB_ALLOWED_ORIGINS` (default: the host of `APP_URL`)
matching it, or Reverb refuses the browser. The PHP and JS sides are covered by `tests/Feature/LiveUpdates` (including an
allowed/denied matrix for every channel) and `tests/js/live`.

## After a deploy

A worker keeps the code it booted with: restart the containers (`docker compose ... up -d` after a rebuild does this).
Admin changes (platform settings, languages) need no restart: they are read again on every request.

## Upgrades and migrations (several containers)

Several containers share one database (`app`, `queue` x2, `queue-heavy`, `scheduler`, `reverb`, anything else you add).
Only one of them migrates; everything else waits. The gate is `php artisan juwri:migrate-gate`, called by the entrypoint
(`docker/entrypoint.sh`).

- **`JUWRI_MIGRATE=1` on an `app` container** → it runs `juwri:migrate-gate --run`: takes a database advisory lock
  (MySQL `GET_LOCK`, PostgreSQL `pg_advisory_lock`), runs `migrate --force`, releases. Two `app` replicas with
  `JUWRI_MIGRATE=1` no longer race: the second waits on the lock, then finds nothing to do. The lock lives in the
  database, so it works whatever `CACHE_DRIVER` is (file-cache locks do not span containers or hosts).
- **Every other role** (`app` with `JUWRI_MIGRATE=0`, `queue`, `queue-heavy`, `scheduler`, `reverb`) runs
  `juwri:migrate-gate --wait` before starting its real work: waits until no migration is pending, then starts. Workers
  never run against a half-migrated database.
- **`JUWRI_MIGRATE_TIMEOUT`** (default `900` seconds) bounds both the lock wait (running) and the pending wait
  (waiting). On timeout the container exits `1` and Docker restarts it (`restart: unless-stopped`); raise the value when
  your database is large or the first migration takes longer than fifteen minutes.
- **`JUWRI_MIGRATE_WAIT=0`** skips the wait (so a one-off `docker run ... php artisan ...` debugging container does not
  block on migrations). Leave it at the default for every real container.
- **Version skew.** If the database lists migrations this image does not have (a newer release already ran on it),
  every role refuses to start with exit code `3`. Fix: run the newer image. Never roll the image back without first
  restoring the matching database backup — the older image cannot run against the newer schema.
- **Exit codes from `juwri:migrate-gate`**: `0` ok · `1` timeout / lock not obtained · `2` database unreachable ·
  `3` version skew · `4` migrate failed.

**Upgrade procedure.**

1. Pull / build the new image.
2. `docker compose -f docker/compose.prod.yml --env-file docker/.env up -d`. The one `app` container with
   `JUWRI_MIGRATE=1` migrates; the rest (`queue`, `queue-heavy`, `scheduler`, `reverb`, and any other `app` replicas)
   wait in the gate, then start with the new schema.

**Recommendation.** Set `JUWRI_MIGRATE=1` on exactly one `app` service, even though the lock makes several safe: one
service migrates on its own restart, so you always know which container is responsible for the schema.

**Manual run** (the same thing the entrypoint does for the `app` container with `JUWRI_MIGRATE=1`):

```bash
docker compose -f docker/compose.prod.yml --env-file docker/.env exec app php artisan juwri:migrate-gate --run
```

Use `--wait` (instead of `--run`) to make another container just block until migrations are done.

## Status

Tested on 2026-10-07 on a clean AWS server (Docker 29, Compose 5, 8 vCPU / 16 GB), from the repository and from the
Community edition mirror: the image builds; the stack (MySQL 8.4, web, 2 queue workers, scheduler, reverb) starts
healthy; `/up` answers 200; the dashboard login and a storefront render; a queued job runs on a queue worker; the
scheduler runs its tasks without errors; the image holds no secrets and every role runs as a non-root user; two stores in
two languages and two owners' dashboards, requested in parallel against the worker, never see each other's data. The
repeatable check is an internal test script that is kept with the project's run tooling. PostgreSQL instead of MySQL (the commented service in
`compose.prod.yml`) was not run in this test.

## Octane browser journeys

An internal Octane end-to-end script (run on a test server, in a synced checkout) boots this image with 2 Octane workers and runs the storefront (EN, AR, FR: bare locale, store, item, cart, quote, checkout start) and owner (login, dashboard, menu, phone agent settings, call log) journeys twice in a row against it, then against a per-request PHP server on the same code, and prints the differences and log errors. Bugs that need worker reuse cannot be reproduced in-process.
