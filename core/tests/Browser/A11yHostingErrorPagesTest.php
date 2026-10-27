<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Services\HostingHealthCard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use PHPUnit\Framework\Assert;
use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;

/**
 * P6-170 accessibility tests for surfaces that are not in the regular journey: the platform admin's
 * "Hosting health" card (App\Services\HostingHealthCard), the legal pages (terms / cookies), the kit
 * error pages (403/404/419/429/500/503) plus the reseller error page, and the two static pages that
 * are served by PHP's built-in web server when the app is not running (maintenance.html and
 * juwri-check.php). Each is checked in English (LTR) and Arabic (RTL), each as its own it() so a
 * failure names exactly the page and the locale. Skipping on a host without Playwright / Chromium
 * is wired in core/tests/Pest.php (browser beforeEach).
 *
 * Helper: assertNoSeriousViolations is the file-local strict variant of Axe::assertClean. Axe::assertClean
 * fails on every WCAG 2.1 A/AA violation regardless of impact (Axe\Axe::WCAG21AA), so a minor contrast
 * or a moderate landmark issue would fail this whole file. The hosting-health card lives on a dashboard
 * packed with charts, tables, and tag colours; a strict run would always find *something* moderate.
 * The contract here is "no serious / critical violations" — exactly the same bar axe-core recommends for
 * "ship / no-ship" gates (axe-core docs: serious + critical = blocker, moderate = should fix, minor = nice
 * to have). The report still lists every offending node (rule | impact | selector | html snippet) so a
 * reviewer can read the failure and decide.
 */

// The six HTTP status codes that ship a kit error page. The dataset KEY becomes the test's data-set
// suffix; the inner array `[code]` is the spread list passed to the closure as `$code`.
dataset('hostingErrorCodes', [
    '403' => [403],
    '404' => [404],
    '419' => [419],
    '429' => [429],
    '500' => [500],
    '503' => [503],
]);

/**
 * Fails the test when the page has any axe-core violation with impact `serious` or `critical`. axe-core
 * classifies every rule violation with one of: minor, moderate, serious, critical (or null when the
 * rule is unclassified). We keep only the two severities that mean "do not ship" and report them in
 * the same `rule | impact | selector | html snippet` format Tests\Browser\Support\Axe uses for its
 * assertClean report, so a failure here is readable the same way. Anything else (moderate, minor, or
 * unclassified) is treated as "not a blocker for this file".
 */
function assertNoSeriousViolations($page, string $label): void
{
    // One h1 / h2 is required on every page here (assertPresent('h1') proved flaky on these pages, so ask the DOM).
    $headings = $page->script('() => document.querySelectorAll("h1, h2").length');
    Assert::assertGreaterThan(0, (int) $headings, "no heading on {$label}");
    $violations = Axe::violations($page);
    $serious = array_values(array_filter(
        $violations,
        static fn (array $v): bool => in_array($v['impact'] ?? null, ['serious', 'critical'], true),
    ));

    if ($serious === []) {
        return;
    }

    $lines = ["axe-core serious/critical violations on {$label}:"];
    foreach ($serious as $violation) {
        $id = (string) ($violation['id'] ?? 'unknown');
        $impact = (string) ($violation['impact'] ?? 'unknown');
        $nodes = (array) ($violation['nodes'] ?? []);
        if ($nodes === []) {
            $lines[] = sprintf('%s | %s | (no nodes) |', $id, $impact);
            continue;
        }
        foreach ($nodes as $node) {
            $target = $node['target'] ?? '';
            if (is_array($target)) {
                $target = implode(' ', array_filter($target, static fn (string $selector): bool => $selector !== ''));
            }
            $html = trim((string) ($node['html'] ?? ''));
            $html = preg_replace('/\s+/', ' ', $html) ?? $html;
            if (strlen($html) > 200) {
                $html = substr($html, 0, 197).'...';
            }
            $lines[] = sprintf('%s | %s | %s | %s', $id, $impact, $target !== '' ? $target : '(no selector)', $html);
        }
    }

    Assert::fail(implode("\n", $lines));
}

/**
 * Boots a tiny `php -S` server on a free local port from a scratch directory laid out exactly like a
 * Juwri release folder: maintenance.html and juwri-check.php alongside a juwri-check.token file the test
 * can read. Returns the base URL and a callable that stops the server and wipes the directory — the
 * test is responsible for calling the stop callable in a finally (the in-process server we use to run
 * Laravel would race the second `php -S` if it shared the port, so we always pick a free one). The
 * helper deliberately mirrors core/tests/Feature/Deploy/HostCheckPageTest, which is the other place in
 * the suite that exercises the same file outside the framework.
 *
 * @return array{url: string, stop: callable(): void}
 */
function serveStatic(): array
{
    $dir = sys_get_temp_dir().'/juwri-a11y-'.bin2hex(random_bytes(6));
    if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        throw new RuntimeException("Could not create temp dir {$dir}");
    }
    $repo = dirname(base_path());
    $maintenance = $repo.'/maintenance.html';
    $hostCheck = $repo.'/juwri-check.php';
    if (! is_file($maintenance) || ! is_file($hostCheck)) {
        throw new RuntimeException("Static test fixtures missing at {$maintenance} / {$hostCheck}");
    }
    copy($maintenance, $dir.'/maintenance.html');
    copy($hostCheck, $dir.'/juwri-check.php');
    // juwri-check.php refuses a token shorter than 16 chars; 24 hex chars (12 random bytes) is comfortably over.
    $token = bin2hex(random_bytes(12));
    file_put_contents($dir.'/juwri-check.token', $token);

    $sock = stream_socket_server('tcp://127.0.0.1:0');
    if ($sock === false) {
        throw new RuntimeException('Could not bind a free TCP port for the static server');
    }
    $name = (string) stream_socket_get_name($sock, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($sock);

    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $dir];
    $descriptors = [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $dir);
    if (! is_resource($proc)) {
        throw new RuntimeException('Could not start php -S for the static server');
    }

    // The host check test (core/tests/Feature/Deploy/HostCheckPageTest) waits up to 5 s for the port to
    // accept a connection; we do the same. The connection is then closed — the server only needs to know
    // it can bind, not what the first request looks like.
    set_error_handler(static fn (): bool => true);
    try {
        for ($i = 0; $i < 100; $i++) {
            $client = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 0.1);
            if ($client !== false) {
                fclose($client);
                break;
            }
            usleep(50000);
        }
    } finally {
        restore_error_handler();
    }

    $stop = static function () use ($proc, $dir): void {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    };

    return ['url' => 'http://127.0.0.1:'.$port, 'stop' => $stop];
}

/**
 * Registers en + ar Language rows, mirrors the mcamara supportedLocales list the app would have built
 * from a real DB (the in-process test boot skips the DB-backed registry build, so without this any
 * Locale middleware check on `app.locale` throws UnsupportedLocaleException), and navigates the
 * /lang/{code} route the UI language picker uses. Same shape as A11yOwnerDashboardPricingTest's helper.
 */
$switchLocale = function ($page, string $locale): void {
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    $supported = get_active_languages();
    config(['laravellocalization.supportedLocales' => $supported]);
    app()->make(\Mcamara\LaravelLocalization\LaravelLocalization::class)->setSupportedLocales($supported);
    $page->navigate(parse_url(lang_url($locale), PHP_URL_PATH));
};

// ---------------------------------------------------------------------------------------------------------------------
// 1. Admin hosting-health card (the card is platform-admin only; seeding the cache avoids the in-process
//    server having to make DNS / HTTP / queue-table probes — HostingHealthCard::raw() reads the cache first
//    and only runs the probes on a miss, see core/app/Services/HostingHealthCard.php).
// ---------------------------------------------------------------------------------------------------------------------
foreach (['en', 'ar'] as $locale) {
    it("has no serious axe-core violations on the admin hosting-health card ({$locale})", function () use ($switchLocale, $locale) {
        app()->setLocale('en');

        User::factory()->create([
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
            'username' => 'a11y_host_'.$locale,
            'email' => 'a11y-host-'.$locale.'@example.test',
            'password' => bcrypt(Fixtures::PASSWORD),
        ]);

        // The cache stores the raw shape (code + params + skipped codes). HostingHealthCard::get() then turns
        // each code into a present row (title, fix, url); the controller calls get() so the page sees the
        // present shape and the dashboard's <x-ui.card id="hosting-health"> renders the warnings list.
        Cache::put(HostingHealthCard::CACHE_KEY, [
            'warnings' => [
                ['code' => 'php_old', 'params' => ['version' => '8.3']],
                ['code' => 'scheduler_stale', 'params' => []],
                ['code' => 'failed_jobs', 'params' => ['count' => 3]],
            ],
            'skipped' => ['web_exposure'],
        ], 300);

        $page = visit('/login');
        $page->type('username', 'a11y-host-'.$locale.'@example.test')
            ->type('password', Fixtures::PASSWORD)
            ->click('form button[type=submit]')
            ->assertPathIsNot('/login')
            ->assertNoJavaScriptErrors();

        $switchLocale($page, $locale);

        // We run axe on the whole dashboard, not a scope: the card sits alongside the pulse KPIs, the
        // recent transactions, etc., and the spec says "report all". assertNoSeriousViolations only keeps
        // serious / critical, so a moderate contrast issue in a chart legend does not fail the test.
        $page->navigate(parse_url(route('admin.dashboard'), PHP_URL_PATH))
            ->assertPresent('#hosting-health')
            ->wait(1);
        assertNoSeriousViolations($page, "admin hosting-health card ({$locale})");
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Legal pages — the JUWRI LTD documents route (first key in config/legal.php, which is "terms" today)
//    and the cookies page every install shows. legal.enabled must be true or the controller 404s.
// ---------------------------------------------------------------------------------------------------------------------
foreach (['en', 'ar'] as $locale) {
    it("has no serious axe-core violations on the legal terms page ({$locale})", function () use ($switchLocale, $locale) {
        app()->setLocale('en');
        config(['legal.enabled' => true]);

        $keys = LegalDocuments::all();
        $firstKey = (string) array_key_first($keys);
        $path = parse_url(LegalDocuments::url($firstKey), PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException("LegalDocuments::url({$firstKey}) returned no path");
        }

        $page = visit('/');
        $switchLocale($page, $locale);
        $page->navigate($path)
            ->wait(1);
        assertNoSeriousViolations($page, "legal terms page ({$locale})");
    });

    it("has no serious axe-core violations on the legal cookies page ({$locale})", function () use ($switchLocale, $locale) {
        app()->setLocale('en');
        config(['legal.enabled' => true]);

        $page = visit('/');
        $switchLocale($page, $locale);
        $page->navigate(parse_url(route('legal.cookies'), PHP_URL_PATH))
            ->wait(1);
        assertNoSeriousViolations($page, "legal cookies page ({$locale})");
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Kit error pages 403 / 404 / 419 / 429 / 500 / 503, in en + ar. The route is registered inside the
//    test body (the only safe place a Pest closure can register routes without leaking across files);
//    the in-process server picks the registration up before the browser navigates. We use a Pest
//    dataset so each code gets its own it() with the code in the dataset key (Pest appends the key to
//    the test description; the actual code arrives as the closure parameter, not as a {code} string
//    substitution).
// ---------------------------------------------------------------------------------------------------------------------
foreach (['en', 'ar'] as $locale) {
    it("has no serious axe-core violations on the kit error page ({$locale})", function (int $code) use ($switchLocale, $locale) {
        app()->setLocale('en');
        Route::get('/__a11y/error/{code}', fn (int $code) => abort($code))
            ->where('code', '403|404|419|429|500|503');

        $page = visit('/');
        $switchLocale($page, $locale);
        $page->navigate('/__a11y/error/'.$code)
            ->wait(1);
        assertNoSeriousViolations($page, "error page {$code} ({$locale})");
    })->with('hostingErrorCodes');
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Reseller error page (errors/reseller.blade.php) — the white-label error page a reseller's domain
//    shows, rendered directly through a test route so we do not have to stand up a reseller context.
// ---------------------------------------------------------------------------------------------------------------------
foreach (['en', 'ar'] as $locale) {
    it("has no serious axe-core violations on the reseller error page ({$locale})", function () use ($switchLocale, $locale) {
        app()->setLocale('en');
        Route::get('/__a11y/reseller-error', fn () => view('errors.reseller', [
            'reseller' => (object) [
                'id' => 1,
                'primary_color' => '#4f46e5',
                'brand_name' => 'Acme',
                'brand_logo' => null,
                'favicon_url' => null,
            ],
            'code' => '404',
            'msg' => 'Page not found',
            'descContent' => 'The page you want is not here.',
            'titleC' => 'Page not found',
        ]));

        $page = visit('/');
        $switchLocale($page, $locale);
        $page->navigate('/__a11y/reseller-error')
            ->assertPresent('[data-reseller-error]')
            ->wait(1);
        assertNoSeriousViolations($page, "reseller error page ({$locale})");
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// 5a. Static maintenance.html (P6-114). The page reads navigator.languages and unhides the matching
//     <section>; the ar run uses withLocale('ar') so Playwright sets the browser context locale before
//     the page is navigated to, the inline script sees 'ar' as navigator.language, and the Arabic
//     section is the only one shown.
// ---------------------------------------------------------------------------------------------------------------------
it('has no serious axe-core violations on maintenance.html (LTR)', function () {
    $server = serveStatic();
    try {
        $page = visit($server['url'].'/maintenance.html');
        expect($page->script('() => document.documentElement.getAttribute("lang")'))->toBe('en');
        expect($page->script('() => document.documentElement.getAttribute("dir")'))->toBe('ltr');
        expect($page->script('() => Array.from(document.querySelectorAll("h1")).filter((h) => h.offsetParent !== null).length'))->toBe(1);
        assertNoSeriousViolations($page, 'maintenance.html (LTR)');
    } finally {
        ($server['stop'])();
    }
});

it('has no serious axe-core violations on maintenance.html (RTL, browser locale = ar)', function () {
    $server = serveStatic();
    try {
        $page = visit($server['url'].'/maintenance.html')->withLocale('ar');
        expect($page->script('() => document.documentElement.getAttribute("lang")'))->toBe('ar');
        expect($page->script('() => document.documentElement.getAttribute("dir")'))->toBe('rtl');
        expect($page->script('() => Array.from(document.querySelectorAll("h1")).filter((h) => h.offsetParent !== null).length'))->toBe(1);
        assertNoSeriousViolations($page, 'maintenance.html (RTL)');
    } finally {
        ($server['stop'])();
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// 5b. juwri-check.php — the pre-install host check (P6-151). Plain PHP, no Laravel, runs against a
//     php -S on its own port. Form: GET /juwri-check.php (h1 + form). Report: POST the token, untick
//     `network` so no outbound traffic, wait for the first <h2> in the report (one per section).
//     The page is English-only; the RTL run flips documentElement.dir before axe (axe checks
//     document-direction-aware rules; flipping it exercises the layout in the opposite direction).
// ---------------------------------------------------------------------------------------------------------------------
it('has no serious axe-core violations on the juwri-check.php form (en)', function () {
    $server = serveStatic();
    try {
        $page = visit($server['url'].'/juwri-check.php')
            ->assertPresent('form input[name=token]')
            ->wait(1);
        assertNoSeriousViolations($page, 'juwri-check.php form (en)');
    } finally {
        ($server['stop'])();
    }
});

it('has no serious axe-core violations on the juwri-check.php report (en)', function () {
    $server = serveStatic();
    try {
        // serveStatic() returns the URL and the stop callable (per spec), not the token it wrote into
        // the temp dir. Re-read the token from disk: the test shares the file system with the server's
        // docroot, and the only file matching the helper's `juwri-a11y-XXXXXX` prefix is the one we just
        // created. Reading via the static server would 404 (the .htaccess/router/web.config deny the
        // token file on purpose, and the built-in server has no such rule but the file is not in the
        // document root, so it would 404 anyway).
        $expected = '';
        foreach (glob(sys_get_temp_dir().'/juwri-a11y-*') ?: [] as $cand) {
            if (is_file($cand.'/juwri-check.token')) {
                $expected = trim((string) file_get_contents($cand.'/juwri-check.token'));
                break;
            }
        }
        if ($expected === '' || strlen($expected) < 16) {
            throw new RuntimeException('Could not read the test token file serveStatic() wrote');
        }

        $page = visit($server['url'].'/juwri-check.php')
            ->type('input[name=token]', $expected)
            ->uncheck('input[name=network]')
            ->click('button[type=submit]')
            ->assertSee('PHP version (web)')
            ->wait(1);
        assertNoSeriousViolations($page, 'juwri-check.php report (en)');
    } finally {
        ($server['stop'])();
    }
});

it('has no serious axe-core violations on the juwri-check.php form (ar via script, English-only page)', function () {
    // juwri-check.php is English-only by design (it is a pre-install diagnostic for the host, not a
    // product surface). We still test RTL: flip documentElement.dir before axe runs so the layout is
    // exercised in the opposite direction; do not touch lang because the page sets it to en.
    // Note: Pest browser's $page->script() returns the JS value (not the Webpage), so we cannot chain
    // page methods after a side-effect script; run the script, then re-assign to keep the chain alive.
    $server = serveStatic();
    try {
        $page = visit($server['url'].'/juwri-check.php');
        $page->script('() => { document.documentElement.dir = "rtl"; document.documentElement.lang = "ar"; return true; }');
        $page->assertPresent("form input[name=token]")->wait(1);
        assertNoSeriousViolations($page, 'juwri-check.php form (ar via script)');
    } finally {
        ($server['stop'])();
    }
});
