# OpenLiteSpeed vhost settings (security headers on static files, asset caching, maintenance 503)

OpenLiteSpeed (OLS) reads only the **rewrite rules** of the repo-root `.htaccess`. It ignores `Header`, `Expires*`,
`Files/FilesMatch`, `SetEnvIf` and `ErrorDocument`. What the app itself sends (every PHP response) is unaffected; this page
covers what PHP never sees: static files (css, js, images, fonts, `robots.txt`) and the maintenance 503.
Tested with OpenLiteSpeed 1.9.3 + lsphp 8.4 by a smoke test that applies the exact blocks below.

**LiteSpeed Enterprise** (cPanel/WHM, DirectAdmin) honours the full `.htaccess`: nothing here is needed, skip this page.

Where to put the blocks: the vhost config file, `/usr/local/lsws/conf/vhosts/<vhost>/vhconf.conf` (WebAdmin: Virtual Hosts >
your vhost; on the Docker image the template is `/usr/local/lsws/conf/templates/docker.conf`). Then apply it:
`sudo /usr/local/lsws/bin/lswsctrl restart` (WebAdmin: Graceful Restart). Keep the values below identical to
`.htaccess` section 1 and to `Juwri\Core\Http\Middleware\AddSecurityHeaders::BASELINE`.

## 1. Security headers on static files: `extraHeaders`

Goes inside the `context /` block (the one with `RewriteFile .htaccess`). It applies to static files and also to PHP
answers under that context; OLS replaces a header of the same name, so nothing is sent twice (smoke-tested).
Do not add `X-Frame-Options` or `Content-Security-Policy` here: the app owns framing (embeddable storefront) and the SVG
sandbox CSP.

<!-- smoke:extraHeaders -->
```
    extraHeaders              <<<END_extraHeaders
Header set X-Content-Type-Options "nosniff"
Header set X-XSS-Protection "1; mode=block"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Permissions-Policy "accelerometer=(), gyroscope=(), magnetometer=(), midi=(), browsing-topics=()"
END_extraHeaders
```

HTTPS-only vhosts may add this line inside the same block (never on a plain-HTTP vhost):
`Header set Strict-Transport-Security "max-age=31536000; includeSubDomains"`.

## 2. Asset caching: `expires`

Goes at vhost level (next to `index`, not inside a context). Asset URLs carry `?v=<version>`, so one year is safe, like
the Apache rules. Everything not listed keeps the default of 7 days.

<!-- smoke:expires -->
```
  expires  {
    enableExpires           1
    expiresDefault          A604800
    expiresByType           text/css=A31536000, application/javascript=A31536000, text/javascript=A31536000, image/webp=A31536000, image/avif=A31536000, image/png=A31536000, image/jpeg=A31536000, image/gif=A31536000, image/svg+xml=A31536000, image/x-icon=A31536000, font/woff2=A31536000, font/woff=A31536000
  }
```

`A31536000` means "access time plus 31 536 000 seconds" (1 year). OLS then sends `Cache-Control: public, max-age=...` and `Expires`.

## 3. Maintenance page body: `errorPage 503`

With `touch maintenance.flag` the `.htaccess` rule answers 503, but OLS then shows its own grey error page, not ours.
Add at vhost level so the branded page is used (the status stays 503):

<!-- smoke:errorPage -->
```
  errorPage 503 {
    url                     /maintenance.html
  }
```

## 4. Retry-After on the maintenance 503: opt-in `maintenance.php`

OLS cannot set `Retry-After` from a rewrite rule (tried `[E=Retry-After:300]`, `[E=Cache-Control:...]`, `[H=...]`, a
rewrite to the static page, and vhost-level `extraHeaders`: none puts a header on a rewrite-generated 503; `extraHeaders`
is not valid at vhost level and applies to every response of a context, so it would also mark live pages). Rewriting to a PHP
script works. So there is an opt-in script:

```
cp deploy/ols/maintenance.php maintenance.php      # in the web root, next to .htaccess
touch maintenance.flag                              # maintenance on  : 503 + Retry-After: 300 + the page
rm maintenance.flag                                 # maintenance off
```

The `.htaccess` flag rule uses it only on LiteSpeed servers and only when that file exists. Apache, and OLS without the
file, keep the plain rule (503, no Retry-After on OLS). The script needs lsphp running: for PHP-less downtime on OLS
(PHP version switch) do not copy it; you still get the 503 and, with section 3, the page. It answers 404 when the flag is
absent, so it never shows on a live site. It is not part of the web root by default and is not listed in any
allow rule, so nothing changes for installs that do not opt in.

## 5. Check it

```
curl -sI https://example.com/assets/app.css | grep -iE 'x-content-type|referrer|cache-control|expires'
touch maintenance.flag; curl -sI https://example.com/ | grep -iE '^HTTP|retry-after'; rm maintenance.flag
```

LiteSpeed Enterprise native config (only if you configure it in WebAdmin instead of `.htaccess`; not tested here): the same
three settings exist as Context > Header Operations (extraHeaders), Virtual Host > General > Expires, and Error Pages.
