<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Router for PHP built-in server (preview only). Serves real files, else hands off to the app front controller.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
// INTEGRATIONS-PACK F0-9: never serve the integrations/ package sources or the
// rev/ working docs as static files (repo root is the docroot). Mirrors the
// .htaccess / web.config denies for the Apache/IIS paths.
if (preg_match('#^/(integrations/(make-app|n8n-nodes-juwri|zapier-app)|rev)(/|$)#', $uri)
    // P6-133: /integrations, /integrations/odoo ... are app routes; beyond the named package dirs only files that exist are denied.
    || preg_match('#^/rev(/|$)#', $uri)
    // P6-173: a vendor/ folder uploaded to the web root is never served either.
    || preg_match('#^/vendor(/|$)#', $uri) || (preg_match('#^/integrations/.#', $uri) && file_exists(__DIR__ . $uri))) {
    http_response_code(404);
    echo 'Not Found';

    return true;
}
// P6-151: the host-check token and attempt counter are never served.
if (preg_match('#^/juwri-check\.(token|attempts)$#', $uri)) {
    http_response_code(404);
    echo 'Not Found';

    return true;
}
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}
require __DIR__ . '/index.php';
