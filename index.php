<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Front controller. The web root is the repository root; the Laravel
 * application lives in core/ (its public path is set back to this folder
 * by AppServiceProvider). .htaccess / web.config send every request that
 * is not a real file here.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$core = __DIR__.'/core';

// P6-173: never serve an empty 500 body. A fatal during boot (composer error, a
// typo that survived the deploy, a missing extension) leaves PHP with nothing
// to render. The web server may fill in `maintenance.html` for a 503, but an
// uncaught Error/E_PARSE/FATAL with display_errors=off is a bare 500. We arm a
// two-stage shutdown fallback BEFORE autoload so it is registered even when
// the require below dies.
//
// Shutdown queue ordering: register first so we run first at shutdown. Inside
// stage 1 we register stage 2; shutdown handlers registered DURING shutdown run
// after the ones already queued, so stage 2 runs after Laravel's own fatal
// handler had its chance to render its page.
register_shutdown_function(static function (): void {
    register_shutdown_function(static function (): void {
        // Anything thrown here must not propagate (we ARE the shutdown). A
        // syntax error above this line would be silenced at this point, so
        // keep this block minimal and free of framework calls.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $e = error_get_last();
        if (! is_array($e)) {
            return;
        }
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (($e['type'] & $fatal) === 0) {
            return;
        }
        try {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            $page = __DIR__.'/maintenance.html';
            if (is_readable($page)) {
                readfile($page);
            } else {
                echo '<!DOCTYPE html><html lang="en" dir="ltr"><head><meta charset="utf-8">'
                    .'<meta name="viewport" content="width=device-width, initial-scale=1">'
                    .'<title>Something went wrong</title></head>'
                    .'<body><main><h1>500</h1><p>Something went wrong.</p></main></body></html>';
            }
        } catch (\Throwable) {
            // No further fallback is possible; PHP will close the connection.
        }
    });
});

// `php artisan down` writes this file; while it exists it answers instead of the app.
if (is_file($core.'/storage/framework/maintenance.php')) {
    require $core.'/storage/framework/maintenance.php';
}

require $core.'/vendor/autoload.php';

$app = require_once $core.'/bootstrap/app.php';

$httpKernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $httpKernel->handle($request);
$response->send();
$httpKernel->terminate($request, $response);
