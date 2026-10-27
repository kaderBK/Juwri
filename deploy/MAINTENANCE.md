# Maintenance mode and error pages (all hosting profiles)

Applies to the three hosting profiles (J40): `shared` (plain PHP-FPM / LiteSpeed on cPanel, no root), `cpanel-vps`
(cPanel/WHM with root) and `docker` (FrankenPHP + Octane). The same two layers work on all three.

## Layer 1: Laravel maintenance mode (PHP is healthy)

```
php artisan down --secret="<long random word>" --retry=300
php artisan up
```

- `--secret`: the operator opens `https://<site>/<secret>` once; the app sets a signed cookie and redirects to `/`,
  and that browser sees the live site while everyone else sees the 503 page. A forged cookie is refused.
- `--retry=N`: sends `Retry-After: N` (crawlers and uptime monitors back off). `--refresh=N` also sends `Refresh`.
- Do not use `--render=...` or `--redirect`: `--render` bakes ONE language into a file; without it the 503 page is
  drawn per request in the visitor's language (language cookie, then `Accept-Language`, then `DEFAULT_LANGUAGE`).
- JSON / API clients get a JSON 503, not HTML.
- The 500 and 503 pages need no database, cache or session. They are plain PHP in
  `core/app/Core/Support/ErrorPage.php`, so they still render when MySQL/Redis is down. They never print the
  exception message, file or trace (with `APP_DEBUG=false`, which production must have).
- 403/404/419/429 use the branded Blade pages (`resources/views/errors`), same languages, same direction rules.
- Languages: ar, de, en, es, fr, hi, pt, ru, tr, ur, zh. `ar` and `ur` are right-to-left.

### Per profile

| Profile | Where the file lives | Notes |
|---|---|---|
| shared | `core/storage/framework/down`, read by the repo-root `index.php` on every request | Nothing to install. `down` / `up` from SSH or the cPanel terminal or a cron one-liner. |
| cpanel-vps | same | Same; if workers run under supervisor/systemd they keep running (queue jobs still process). Restart them after `up` if a deploy changed code. |
| docker | same file, inside the `/app/core` volume | Octane workers read the `down` file on every request, so `down`/`up` need no restart. Run it as `docker compose exec app php artisan down ...`. Octane long-lived state: the error pages keep no per-request state (only the list of lang folders). |

For several app containers, set `config/app.php` `maintenance.driver` to `cache` with a shared store (Redis) so every
node sees the same state; with the `file` driver run the command on each node.

## Layer 2: static fallback (PHP itself is broken or switched off)

`/maintenance.html` (repo root) is pre-rendered, branded and self-contained: all 11 languages in one file, picked in
the browser from `navigator.language`, English with scripts off, RTL for ar/ur, no external request. Rebuild it on
deploy and after changing `APP_NAME`:

```
php artisan juwri:maintenance-page          # write it
php artisan juwri:maintenance-page --check  # exit 1 if missing or out of date
```

### Apache / LiteSpeed (shared, cpanel-vps): rules shipped in the repo-root `.htaccess` (section 0)

```
ErrorDocument 503 /maintenance.html
ErrorDocument 500 /maintenance.html
RewriteCond %{DOCUMENT_ROOT}/maintenance.flag -f
RewriteCond %{REQUEST_URI} !^/maintenance\.html$
RewriteRule ^ - [R=503,L,E=JUWRI_MAINT:1]
Header always set Retry-After "300" env=JUWRI_MAINT
```

- PHP-FPM/LSAPI down or unreachable: Apache answers 503 itself and shows `maintenance.html`.
- A PHP fatal error that kills the request: the front controller (`index.php`, P6-173) catches it at shutdown and, when
  no header has been sent yet, answers status 500 with `maintenance.html` as the body, so the visitor never sees a
  blank page. The app's own exception path (`Handler` -> `ErrorPage`) also always writes a body, even when
  translations or the view engine are broken.
- An EMPTY 500 that PHP-FPM itself returns (for example `display_errors=Off` plus a crash before `index.php` runs, or a
  fatal inside `php.ini`-level `auto_prepend_file`) is passed through by Apache as an empty page; `ErrorDocument` is not
  used for a response a backend already produced. Two ways to cover it:
  - VPS / root: in the vhost add `ProxyErrorOverride On` (Apache `mod_proxy_fcgi`), keep `ErrorDocument 500 /maintenance.html`.
    It cannot be set from `.htaccess`. For nginx in front: `fastcgi_intercept_errors on; error_page 500 /maintenance.html;`.
  - Shared hosting (no vhost access): ask the host to enable `ProxyErrorOverride` (or "custom error pages for PHP
    errors") for the account, or turn on cPanel `Error Pages` for 500 and make it point to the same file. Without it
    only the `index.php` fallback above applies.
- Planned PHP-less downtime (PHP upgrade, switching PHP version in cPanel): `touch maintenance.flag` in the web root
  (the repo root), do the work, `rm maintenance.flag`. Every request answers 503 + `Retry-After: 300` + the page.
  This has no bypass cookie; to test while it is on, use a second hostname or an IP allow rule above the flag rule.
- cPanel: the web root is the repo root (see `deploy/cpanel/README-doc-root.md`). If cPanel's own "Error Pages"
  editor defines 500/503 pages for the account, remove them or they win over `.htaccess`.
- OpenLiteSpeed (not LiteSpeed Enterprise) ignores `Header`/`ErrorDocument` in `.htaccess`: use the vhost `errorPage 503` block and the opt-in `maintenance.php` for Retry-After, see `deploy/OLS-VHOST.md`.
- An nginx front (cpanel-vps with nginx proxy): `error_page 502 503 504 /maintenance.html; location = /maintenance.html { internal; root <web root>; add_header Retry-After 300 always; }`.

### Docker (Caddy + FrankenPHP + Octane): `docker/Caddyfile` `handle_errors`

When the worker is down (502/503/504 from `php_server`) Caddy serves `/maintenance.html` with the real status and
`Retry-After: 300`. A Laravel 503 from `artisan down` comes from the worker and uses Layer 1.

## Checks

- `php artisan down --secret=abc --retry=60`, then `curl -si https://<site>/ | head` shows `503` and `Retry-After: 60`;
  `curl -si -H 'Accept-Language: ar' https://<site>/` shows `dir="rtl"`; `up` again.
- Stop PHP-FPM (or `docker compose stop app`): the site answers the static page with a 5xx status.
- Tests: `tests/Modules/Platform/ErrorPagesMaintenanceTest.php`.
