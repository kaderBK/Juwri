<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
//
// OpenLiteSpeed only, opt-in (P6-155): copy this file to the web root as `maintenance.php` (next to .htaccess and
// maintenance.html). While `maintenance.flag` exists, the .htaccess flag rule sends every request here on a LiteSpeed
// server, and this answers 503 + Retry-After + the static page. (OpenLiteSpeed cannot set Retry-After from a rewrite
// rule, and it ignores `ErrorDocument` for rewrite 503s.) It needs lsphp to be running, so for PHP-less downtime
// leave this file out: the flag rule then still answers 503, with the page from the vhost `errorPage 503` setting.
// See deploy/OLS-VHOST.md. Without the flag file it answers 404 so it never advertises maintenance on a live site.

if (! is_file(__DIR__.'/maintenance.flag')) {
    http_response_code(404);
    exit;
}

http_response_code(503);
header('Retry-After: 300');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
$page = __DIR__.'/maintenance.html';
if (is_file($page)) {
    readfile($page);
} else {
    echo 'Down for maintenance. Please try again shortly.';
}
