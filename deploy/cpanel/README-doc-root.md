# cPanel / shared-Apache deployment — doc-root & source protection

> Full install, VPS and migration guide: [`deploy/HOSTING.md`](../HOSTING.md).

This guide covers shared / cPanel-Apache hosting. On cPanel the load-bearing control is the **repo-root `.htaccess`**,
which Apache honours natively (unlike the local Herd/nginx dev site).

## The layout (why this matters)

The Laravel application lives in **`core/`**,
reached only through the repo-root front controller **`index.php`**
(`require __DIR__.'/core/vendor/autoload.php'` + `core/bootstrap/app.php`).
The web document root is the **repo root**, so `core/` is a *servable
subdirectory* — `/core/.env`, `/core/app/...`, `/core/storage/logs/...` would
be fetchable unless denied. The only path under `core/` that is legitimately
public is `core/plugins/<id>/assets/`.

The shipped root `.htaccess` (guarded by `DocRootExposureTest`) already denies:
`core/{app,bootstrap,config,database,lang,resources,routes,tests,storage,vendor,node_modules}/`,
top-level `core/*.{env,json,lock,xml}`, `core/artisan`, `core/server.php`, and
sets `Options -Indexes` + security headers — while leaving
`core/plugins/*/assets/` served.

## Setup — pick ONE

### Option A — repo-root document root (simplest, matches the shipped `.htaccess`)

1. Upload/clone the repo into the account, e.g. `~/juwri` (or straight into
   `public_html`).
2. Point the domain's **Document Root** at the repo root (the dir containing
   `index.php` + `.htaccess`). In cPanel: *Domains → manage → Document Root*.
3. Confirm **`AllowOverride All`** is on for that path so `.htaccess` is read.
   On cPanel shared hosting this is the default for the account doc-root; if you
   run your own Apache vhost, add it explicitly:
   ```apache
   <Directory "/home/USER/juwri">
       AllowOverride All
   </Directory>
   ```
4. Ensure `mod_headers` + `mod_rewrite` are enabled (standard on cPanel).

### Option B — repo outside web root (defense-in-depth, optional)

Place the repo *outside* `public_html` and set the domain Document Root to the
repo dir directly (not a symlink into `public_html`). Same `.htaccess` applies.
Prefer A unless your host's policy forbids doc-roots outside `public_html`.

> A full standard-Laravel `public/`-only root is a larger restructure (asset
> paths + the `usePublicPath` override + plugin asset URLs) and is **not**
> required when the `.htaccess` deny rules are honoured.

## File permissions

- `core/.env` → **600** (owner read/write only).
- `core/storage/` and `core/bootstrap/cache/` → writable by the web user; never
  directory-listable (covered by `Options -Indexes`).
- Everything else → 644 files / 755 dirs.

## Verification (run after DNS + deploy — must all return 403 or 404)

```bash
curl -so /dev/null -w '%{http_code}\n' https://PROD/core/.env                 # 403/404
curl -so /dev/null -w '%{http_code}\n' https://PROD/core/composer.json        # 403/404
curl -so /dev/null -w '%{http_code}\n' https://PROD/core/app/Models/Post.php  # 403/404
curl -so /dev/null -w '%{http_code}\n' https://PROD/core/storage/logs/laravel.log  # 403/404
curl -so /dev/null -w '%{http_code}\n' https://PROD/                          # 200 (app loads)
```

If any of the `core/...` probes returns **200**, `.htaccess` is not being read
(`AllowOverride` off) — stop and fix before going live. This live check is the
**owner's at deploy time** (outward-facing; the repo ships + guards the rules,
the owner applies + verifies them on the host).

See also: [`../PROD-HARDENING-CHECKLIST.md`](../PROD-HARDENING-CHECKLIST.md).
