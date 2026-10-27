<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ErrorPage;
use RuntimeException;
use Tests\TestCase;

/**
 * P6-173: never serve an empty 500 body.
 *
 * (a) ErrorPage::emergency() always renders a self-contained English page
 *     (no framework calls, no views, no translator, no config) with the
 *     right status and a non-empty body.
 * (b) Handler::renderHttpException() wraps ErrorPage::response() in a
 *     try/catch and falls back to emergency() when anything inside that
 *     path throws (here: the translator throws on get()).
 * (c) index.php's two-stage shutdown fallback writes a real, non-empty 500
 *     page when the front controller itself dies during boot (the case
 *     ErrorPage can never cover, because Laravel never finished booting).
 *     Spun up with `php -S` against a temp copy of index.php + a fatal
 *     core/vendor/autoload.php, so this exercises the actual shutdown
 * handler instead of mocking one.
 */
class EmptyFiveHundredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'app.locale' => 'en']);
        app()->setLocale('en');
    }

    public function test_emergency_500_is_a_self_contained_english_page(): void
    {
        $res = ErrorPage::emergency(500);

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(500, $res->getStatusCode());
        $body = (string) $res->getContent();
        $this->assertNotSame('', $body);
        $this->assertStringContainsString('<h1>500</h1>', $body);
        $this->assertStringContainsString('lang="en"', $body);
        $this->assertStringContainsString('Something went wrong', $body);
        $this->assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        // Never leaks: framework calls leave no trace of themselves in the body.
        $this->assertStringNotContainsString('app.name', $body);
        $this->assertStringNotContainsString('<?php', $body);
    }

    public function test_emergency_503_says_down_for_maintenance(): void
    {
        $res = ErrorPage::emergency(503);

        $this->assertSame(503, $res->getStatusCode());
        $this->assertStringContainsString('Down for maintenance', (string) $res->getContent());
    }

    public function test_handler_returns_emergency_when_translator_throws(): void
    {
        // ErrorPage::response -> ErrorPage::t() -> app('translator')->get($key...).
        // A translator that throws on get() is the smallest seam that exercises
        // the catch in production code: the lang/ folder can be read fine, so
        // the failure is exactly the translator lookup the catch was added for.
        Route::get('/__t/boom', fn () => abort(500));
        $this->app->instance('translator', new class
        {
            public function get(string $key, array $replace = [], $locale = null, $fallback = true): string
            {
                throw new RuntimeException('P6-173: translator exploded');
            }
        });

        $res = $this->get('/__t/boom');

        $res->assertStatus(500);
        $body = $res->getContent();
        $this->assertStringContainsString('<h1>500</h1>', $body, 'emergency() body must replace the broken one');
        $this->assertStringContainsString('lang="en"', $body);
        $this->assertStringNotContainsString('P6-173: translator exploded', $body, 'fallback must never leak the original exception');
    }

    public function test_index_php_shutdown_fallback_emits_a_non_empty_500_on_boot_fatal(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable; cannot start a real php -S for the shutdown test.');
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            $this->markTestSkipped('proc_open is disabled in php.ini.');
        }

        $worktree = dirname(__DIR__, 4);
        $tmp = $worktree.'/.loop/tmp/p6173-'.uniqid('', true);
        @mkdir($tmp.'/core/vendor', 0777, true);
        copy($worktree.'/index.php', $tmp.'/index.php');
        copy($worktree.'/maintenance.html', $tmp.'/maintenance.html');
        file_put_contents($tmp.'/core/vendor/autoload.php', "<?php\nnonexistent_function_p6173();\n");

        // Pick a free TCP port on 127.0.0.1. try several candidates and skip
        // the test if nothing is free (CI runners can have unexpected bind use).
        $port = null;
        for ($candidate = 19800; $candidate < 19900; $candidate++) {
            $sock = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.1);
            if ($sock === false) {
                $port = $candidate;
                break;
            }
            fclose($sock);
        }
        if ($port === null) {
            $this->cleanupTmp($tmp);
            $this->markTestSkipped('No free TCP port for the built-in PHP server.');
        }

        $cmd = 'exec '.escapeshellarg(PHP_BINARY)
            .' -d display_errors=0 -S 127.0.0.1:'.$port.' -t '.escapeshellarg($tmp);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            $this->cleanupTmp($tmp);
            $this->markTestSkipped('proc_open could not start php -S.');
        }

        try {
            // Wait until the port accepts connections (max 5s).
            $ready = false;
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($sock !== false) {
                    fclose($sock);
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            if (! $ready) {
                $this->markTestSkipped('php -S did not start accepting connections within 5s.');
            }

            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            $body = @file_get_contents('http://127.0.0.1:'.$port.'/', false, $context);
            $headers = $http_response_header ?? [];
            $statusLine = $headers[0] ?? '';

            $this->assertStringContainsString('500', $statusLine, 'Fatal during boot should still be HTTP 500.');
            $this->assertNotEmpty($body, 'Body must not be empty.');
            $this->assertTrue(
                str_contains($body, 'maintenance') || str_contains($body, '<h1>'),
                'Body must carry the maintenance page or its inline fallback.'
            );
        } finally {
            @proc_terminate($proc);
            @proc_close($proc);
            $this->cleanupTmp($tmp);
        }
    }

    private function cleanupTmp(string $tmp): void
    {
        @unlink($tmp.'/index.php');
        @unlink($tmp.'/maintenance.html');
        @unlink($tmp.'/core/vendor/autoload.php');
        @rmdir($tmp.'/core/vendor');
        @rmdir($tmp.'/core');
        @rmdir($tmp);
    }
}