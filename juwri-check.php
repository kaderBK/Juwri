<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * juwri-check.php — host check for cPanel owners, run BEFORE installing Juwri (P6-151).
 *
 * Plain PHP: no Laravel, no Composer, no database. Written in PHP 7.0 syntax on purpose, so it still runs (and says
 * "too old") on the 7.x / 8.3 a host may be on.
 *
 * Guards:
 *   - it only runs while a file `juwri-check.token` sits next to it (you create it, 16+ random characters). You type
 *     the same token into the form; it is compared in constant time and only ever sent by POST (not in the URL);
 *   - it refuses once Juwri is installed (core/.env holds an APP_KEY);
 *   - at most 10 form posts an hour (file counter `juwri-check.attempts`, next to it); fails closed when it cannot count;
 *   - it never prints a full path, a php.ini value that is a path, or phpinfo();
 *   - the web rules (.htaccess, web.config, router.php) never serve the token or counter file.
 *
 * DELETE juwri-check.php, juwri-check.token and juwri-check.attempts after use (deploy/HOSTING.md section 2, step 0).
 */

@ini_set('display_errors', '0');
error_reporting(0);

define('JC_ROOT', __DIR__);
define('JC_TOKEN_FILE', JC_ROOT.'/juwri-check.token');
define('JC_COUNTER_FILE', JC_ROOT.'/juwri-check.attempts');
define('JC_MAX_POSTS', 10);
define('JC_WINDOW', 3600);
define('JC_MIN_TOKEN', 16);
define('JC_PHP_REQUIRED', '8.4.0');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

function jc_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function jc_stop($status, $title, $text)
{
    http_response_code($status);
    jc_page($title, '<p>'.jc_h($text).'</p>');
    exit;
}

function jc_page($title, $body)
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
        .'<meta name="viewport" content="width=device-width,initial-scale=1"><title>'.jc_h($title).'</title>'
        .'<style>body{font:15px/1.5 system-ui,sans-serif;max-width:860px;margin:24px auto;padding:0 16px;color:#222}'
        .'table{border-collapse:collapse;width:100%;margin:8px 0 20px}td,th{border:1px solid #ccc;padding:4px 8px;text-align:left;vertical-align:top}'
        .'.ok{color:#176a2c;font-weight:600}.warn{color:#8a5a00;font-weight:600}.fail{color:#b00020;font-weight:600}'
        .'.box{background:#fff4d6;border:1px solid #e0b84c;padding:8px 12px}</style></head><body>'
        .'<h1>'.jc_h($title).'</h1>'.$body
        .'<p class="box"><strong>Delete this page after use:</strong> remove juwri-check.php, juwri-check.token and '
        .'juwri-check.attempts from the Juwri folder.</p></body></html>';
}

/** True once Juwri is installed: core/.env exists and holds a non-empty APP_KEY. */
function jc_installed()
{
    $env = JC_ROOT.'/core/.env';
    if (! is_file($env)) {
        return false;
    }
    $src = (string) @file_get_contents($env);

    return preg_match('/^\s*APP_KEY\s*=\s*["\']?[^\s"\'#]+/m', $src) === 1;
}

/**
 * Counts one form post in a fixed one-hour window. Returns true when the post is allowed, false when the limit is
 * reached, null when the counter cannot be written (the caller refuses: fail closed).
 */
function jc_count_post()
{
    $fh = @fopen(JC_COUNTER_FILE, 'c+');
    if ($fh === false) {
        return null;
    }
    if (! flock($fh, LOCK_EX)) {
        fclose($fh);

        return null;
    }
    $raw = stream_get_contents($fh);
    $now = time();
    $start = $now;
    $count = 0;
    if (is_string($raw) && preg_match('/^(\d+) (\d+)$/', trim($raw), $m) === 1 && $now - (int) $m[1] < JC_WINDOW) {
        $start = (int) $m[1];
        $count = (int) $m[2];
    }
    $count++;
    ftruncate($fh, 0);
    rewind($fh);
    $ok = fwrite($fh, $start.' '.$count) !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (! $ok) {
        return null;
    }

    return $count <= JC_MAX_POSTS;
}

function jc_bytes($v)
{
    $v = trim((string) $v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (float) $v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') {
        $n *= 1073741824;
    } elseif ($u === 'm') {
        $n *= 1048576;
    } elseif ($u === 'k') {
        $n *= 1024;
    }

    return (int) $n;
}

/** GET {base}/juwri-check-rewrite-probe on this same site; the .htaccess rule maps it to this file. */
function jc_rewrite_works()
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if (preg_match('/^[A-Za-z0-9.-]{1,253}(:\d{1,5})?$/', $host) !== 1) {
        return null;
    }
    $https = (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    $base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/')), '/');
    $url = ($https ? 'https' : 'http').'://'.$host.$base.'/juwri-check-rewrite-probe';
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0],
        // The probe goes to this same site; a self-signed or not-yet-issued certificate must not hide the answer.
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);

    return is_string($body) && strpos($body, 'juwri-rewrite-ok') !== false;
}

/** TLS connect to host:443 with a short timeout and certificate checks on. */
function jc_https_reachable($host)
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
    if ($s === false) {
        return false;
    }
    fclose($s);

    return true;
}

function jc_row($label, $state, $note)
{
    $txt = ['ok' => 'OK', 'warn' => 'CHECK', 'fail' => 'FAIL'];

    return '<tr><td>'.jc_h($label).'</td><td class="'.$state.'">'.$txt[$state].'</td><td>'.jc_h($note).'</td></tr>';
}

function jc_table($title, $rows)
{
    return '<h2>'.jc_h($title).'</h2><table><tr><th>Item</th><th>Result</th><th>Notes</th></tr>'.implode('', $rows).'</table>';
}

function jc_report($network)
{
    $out = '';
    $phpOk = version_compare(PHP_VERSION, JC_PHP_REQUIRED, '>=');
    $rows = [jc_row('PHP version (web)', $phpOk ? 'ok' : 'fail', PHP_VERSION.' — Juwri needs 8.4 or newer. Server API: '.PHP_SAPI)];
    $out .= jc_table('PHP', $rows);
    if (! $phpOk) {
        $out .= '<div class="box"><p><strong>How to switch this site to PHP 8.4:</strong></p><ol>'
            .'<li>cPanel &gt; Software &gt; <strong>MultiPHP Manager</strong>: tick this domain, pick <strong>ea-php84</strong>, Apply. '
            .'On CloudLinux use <strong>Select PHP Version</strong> instead and pick 8.4.</li>'
            .'<li>Reload this page and post the token again: the version above must say 8.4.</li>'
            .'<li>Cron needs 8.4 too: the command-line binary is usually /opt/cpanel/ea-php84/root/usr/bin/php or '
            .'/opt/alt/php84/usr/bin/php (run it with --version).</li>'
            .'<li>No 8.4 offered? Ask the host to enable it. Juwri does not run on 8.3 or older.</li></ol></div>';
    }

    $rows = [];
    foreach (['sodium', 'openssl', 'mbstring', 'iconv', 'curl', 'gd', 'zip', 'dom', 'libxml', 'simplexml', 'fileinfo', 'json', 'ctype', 'filter', 'tokenizer', 'session', 'zlib', 'pdo', 'pdo_mysql'] as $ext) {
        $rows[] = jc_row($ext, extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'loaded' : 'missing: tick it in Select PHP Version / ask the host');
    }
    $out .= jc_table('Required extensions', $rows);

    $disabled = array_filter(array_map('trim', explode(',', strtolower((string) ini_get('disable_functions')))));
    $needed = ['putenv' => 'fail', 'curl_exec' => 'fail', 'curl_multi_exec' => 'fail', 'escapeshellarg' => 'fail',
        'proc_open' => 'warn', 'set_time_limit' => 'warn', 'ignore_user_abort' => 'warn', 'symlink' => 'warn', 'fsockopen' => 'warn', 'stream_socket_client' => 'warn', ];
    $rows = [];
    foreach ($needed as $fn => $severity) {
        $off = in_array($fn, $disabled, true) || ! function_exists($fn);
        $rows[] = jc_row($fn, $off ? $severity : 'ok', $off ? ($severity === 'fail' ? 'disabled: Juwri needs it, ask the host to enable it' : 'disabled: some features are limited') : 'allowed');
    }
    $rows[] = jc_row('open_basedir', 'ok', ini_get('open_basedir') ? 'set (fine if the Juwri folder is inside it)' : 'not set');
    $out .= jc_table('Disabled functions', $rows);

    $mem = jc_bytes(ini_get('memory_limit'));
    $met = (int) ini_get('max_execution_time');
    $up = jc_bytes(ini_get('upload_max_filesize'));
    $post = jc_bytes(ini_get('post_max_size'));
    $rows = [
        jc_row('memory_limit', ($mem === -1 || $mem >= 268435456) ? 'ok' : 'fail', ini_get('memory_limit').' — needs 256M or more (512M better)'),
        jc_row('max_execution_time', ($met === 0 || $met >= 60) ? 'ok' : 'warn', $met.' s — 60 or more'),
        jc_row('upload_max_filesize', ($up === -1 || $up >= 67108864) ? 'ok' : 'warn', ini_get('upload_max_filesize').' — 64M recommended'),
        jc_row('post_max_size', ($post === -1 || $post >= 67108864) ? 'ok' : 'warn', ini_get('post_max_size').' — 64M recommended'),
    ];
    $out .= jc_table('Limits', $rows);

    $rows = [];
    foreach (['core', 'core/storage', 'core/storage/app', 'core/storage/framework', 'core/storage/framework/cache', 'core/storage/framework/sessions', 'core/storage/framework/views', 'core/storage/logs', 'core/bootstrap/cache'] as $rel) {
        $p = JC_ROOT.'/'.$rel;
        if (! is_dir($p)) {
            $rows[] = jc_row($rel.'/', 'fail', 'folder missing: upload the full release');
        } else {
            $rows[] = jc_row($rel.'/', is_writable($p) ? 'ok' : 'fail', is_writable($p) ? 'writable' : 'not writable: set it to 755 (folders) in File Manager');
        }
    }
    $out .= jc_table('Writable folders', $rows);

    if ($network) {
        $rw = jc_rewrite_works();
        $rows = [jc_row('.htaccess rewrites', $rw === true ? 'ok' : ($rw === null ? 'warn' : 'fail'),
            $rw === true ? 'pretty URLs reach the app' : ($rw === null ? 'could not test (unusual Host header)' : 'rewrites do not work: check mod_rewrite / AllowOverride with the host, and that .htaccess was uploaded'))];
        foreach (['gw-fatoora.zatca.gov.sa' => 'ZATCA e-invoicing', 'api.stripe.com' => 'Stripe', 'api.moyasar.com' => 'Moyasar', 'api.tap.company' => 'Tap', 'api-m.paypal.com' => 'PayPal'] as $host => $what) {
            $ok = jc_https_reachable($host);
            $rows[] = jc_row('HTTPS to '.$host, $ok ? 'ok' : 'warn', $what.($ok ? ': reachable' : ': not reachable in 4 s (outbound port 443 blocked?)'));
        }
        $out .= jc_table('Network', $rows);
    }

    return $out;
}

// ---------------------------------------------------------------------------------------------------------------------

if (jc_installed()) {
    jc_stop(410, 'Juwri is installed', 'This check page only works before installing. Delete juwri-check.php now.');
}
if (! is_file(JC_TOKEN_FILE)) {
    jc_stop(403, 'Juwri host check is locked', 'Create a file named juwri-check.token next to this page, holding '
        .JC_MIN_TOKEN.' or more random characters, then reload. Type the same text into the form.');
}
$stored = trim((string) @file_get_contents(JC_TOKEN_FILE));
if (strlen($stored) < JC_MIN_TOKEN) {
    jc_stop(403, 'Juwri host check is locked', 'juwri-check.token must hold '.JC_MIN_TOKEN.' or more random characters.');
}

// The rewrite probe: only answers when reached through the .htaccess rule, never by its own file name.
$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
if (isset($_GET['juwri_probe']) && strpos($uri, 'juwri-check.php') === false) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'juwri-rewrite-ok';
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if ($method !== 'POST') {
    jc_page('Juwri host check', '<p>Type the text you put in juwri-check.token.</p>'
        .'<form method="post" autocomplete="off"><p><input type="password" name="token" aria-label="Token from juwri-check.token" size="40" required></p>'
        .'<p><label><input type="checkbox" name="network" value="1" checked> Also test .htaccess rewrites and outbound '
        .'HTTPS to ZATCA and payment providers (up to 30 s)</label></p><p><button type="submit">Run the check</button></p></form>');
    exit;
}

$allowed = jc_count_post();
if ($allowed === null) {
    jc_stop(503, 'Juwri host check cannot run', 'The Juwri folder is not writable, so the attempt counter cannot be kept. '
        .'Make the folder writable (755) and try again.');
}
if ($allowed === false) {
    jc_stop(429, 'Too many attempts', 'This page allows '.JC_MAX_POSTS.' tries an hour. Wait an hour, or delete juwri-check.attempts.');
}
$given = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
if (! hash_equals(hash('sha256', $stored), hash('sha256', $given))) {
    jc_stop(403, 'Wrong token', 'The text does not match juwri-check.token.');
}

jc_page('Juwri host check', jc_report(! empty($_POST['network'])));
