<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Foundation\CacheBasedMaintenanceMode;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Juwri\Core\Support\ErrorPage;
use Juwri\Core\Support\MaintenanceStaticPage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * P6-112: error pages (403/404/419/429/500/503) in all 11 locales with the right direction, 500/503 with no
 * database or session, maintenance mode (bypass secret, Retry-After), no leakage with APP_DEBUG=false, and the
 * static maintenance.html fallback for when PHP is down.
 */
class ErrorPagesMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = [
        403 => ['Access forbidden', 'You do not have permission to view this resource.'],
        404 => ['Page not found', 'The page you are looking for might have been moved or no longer exists.'],
        419 => ['Session expired', 'For your security, your session has timed out. Reload the page and try again.'],
        429 => ['Slow down a little', 'Too many requests in a short time. Wait a moment, then try again.'],
        500 => ['Something went wrong', 'We hit an unexpected error. Our team has been notified.'],
        503 => ['Down for maintenance', 'We are making the app better. Back online shortly.'],
    ];

    private const LOCALES = ['ar', 'de', 'en', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    private string $originalDb;

    private bool $downed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDb = (string) config('database.default');
        config(['app.debug' => false, 'app.locale' => 'en']);
        app()->setLocale('en');
        foreach ([403, 404, 419, 429, 503] as $code) {
            Route::get('/__t/'.$code, fn () => abort($code));
        }
        Route::get('/__t/500', fn () => throw new RuntimeException('SECRET-INTERNAL-MESSAGE'));
        Route::get('/__t/ok', fn () => 'normal-page-ok');
    }

    protected function tearDown(): void
    {
        if ($this->downed) {
            $this->app->maintenanceMode()->deactivate();
        }
        // The DB-down tests point the default connection at a dead host; put it back so the rollback can run.
        config(['database.default' => $this->originalDb]);
        parent::tearDown();
    }

    /** Maintenance state in an array cache, never the shared storage/framework/down file (parallel runs). */
    private function down(array $payload = []): void
    {
        $this->app->instance(
            MaintenanceModeContract::class,
            new CacheBasedMaintenanceMode($this->app['cache'], 'array', 'p6112:down')
        );
        $this->downed = true;
        $this->app->maintenanceMode()->activate($payload + [
            'except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null,
            'secret' => null, 'status' => 503, 'template' => null,
        ]);
    }

    public static function statusLocales(): array
    {
        $rows = [];
        foreach (array_keys(self::SOURCE) as $status) {
            foreach (['ar', 'ur', 'en', 'de', 'zh', 'hi'] as $locale) {
                $rows["$status-$locale"] = [$status, $locale];
            }
        }

        return $rows;
    }

    #[DataProvider('statusLocales')]
    public function test_every_error_code_renders_in_the_visitor_language_with_correct_direction(int $status, string $locale): void
    {
        $res = $this->withHeaders(['Accept-Language' => $locale])->get('/__t/'.$status);

        $res->assertStatus($status);
        $html = $res->getContent();
        $dir = in_array($locale, ['ar', 'ur'], true) ? 'rtl' : 'ltr';
        $this->assertStringContainsString('lang="'.$locale.'"', $html);
        $this->assertStringContainsString('dir="'.$dir.'"', $html);
        $this->assertStringContainsString((string) $status, $html);
        [$title, $description] = self::SOURCE[$status];
        $this->assertStringContainsString(e(ErrorPage::t($title, $locale)), $html);
        $this->assertStringContainsString(e(ErrorPage::t($description, $locale)), $html);
        if ($locale !== 'en') {
            $this->assertStringNotContainsString(e($title), $html, "$status/$locale shows the English title");
            $this->assertStringNotContainsString(e($description), $html, "$status/$locale shows the English text");
        }
    }

    public function test_all_eleven_locales_have_every_error_string_in_their_own_language(): void
    {
        $this->assertSame(self::LOCALES, ErrorPage::supportedLocales());
        $script = [
            'ar' => '/\p{Arabic}/u', 'ur' => '/\p{Arabic}/u', 'hi' => '/\p{Devanagari}/u',
            'ru' => '/\p{Cyrillic}/u', 'zh' => '/\p{Han}/u',
        ];
        $strings = ['Back to home', 'Error'];
        foreach (self::SOURCE as [$title, $description]) {
            $strings[] = $title;
            $strings[] = $description;
        }
        foreach (self::LOCALES as $locale) {
            foreach ($strings as $source) {
                $text = ErrorPage::t($source, $locale);
                $this->assertNotSame('', $text);
                if ($locale === 'en') {
                    continue; // en.lang.php words several of these its own way; the source text is the fallback
                }
                if (! ($locale === 'es' && $source === 'Error')) { // 'Error' is the Spanish word too
                    $this->assertNotSame($source, $text, "[$locale] '$source' is untranslated");
                }
                if (isset($script[$locale])) {
                    $this->assertMatchesRegularExpression($script[$locale], $text, "[$locale] '$source' is not in the locale's script");
                }
            }
        }
    }

    public function test_locale_comes_from_cookie_then_accept_language_then_default_without_the_database(): void
    {
        $req = fn (array $headers = ['Accept-Language' => ''], array $cookies = []) => Request::create('/', 'GET', [], $cookies, [], array_combine(
            array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)),
            array_values($headers)
        ) ?: []);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertSame('de', ErrorPage::resolveLocale($req(['Accept-Language' => 'ar'], [ErrorPage::LANG_COOKIE => 'de'])), 'cookie wins');
        $this->assertSame('ar', ErrorPage::resolveLocale($req(['Accept-Language' => 'ar'], [ErrorPage::LANG_COOKIE => 'xx'])), 'bad cookie is ignored');
        $this->assertSame('fr', ErrorPage::resolveLocale($req(['Accept-Language' => 'it;q=0.9, fr-CA;q=0.8, en;q=0.5'])));
        $this->assertSame('en', ErrorPage::resolveLocale($req(['Accept-Language' => 'fr;q=0.2, en;q=0.9'])), 'q-values rank');
        $this->assertSame('zh', ErrorPage::resolveLocale($req(['Accept-Language' => 'zh-Hans-CN'])));
        $this->assertSame('pt', ErrorPage::resolveLocale($req(['Accept-Language' => 'pt-BR,pt;q=0.9'])));
        $this->assertSame('en', ErrorPage::resolveLocale($req(['Accept-Language' => 'xx-YY, *;q=0.1'])), 'default');
        $this->assertSame('en', ErrorPage::resolveLocale($req()));
        $this->assertSame(0, $queries, 'locale resolution must not query the database');

        config(['app.locale' => 'ru']);
        $this->assertSame('ru', ErrorPage::resolveLocale($req()), 'configured default');
        config(['app.locale' => 'klingon']);
        $this->assertSame('en', ErrorPage::resolveLocale($req()), 'unsupported default falls back to en');
    }

    public function test_cookie_language_is_used_by_a_real_error_response(): void
    {
        $res = $this->withHeaders(['Accept-Language' => 'ar'])->withUnencryptedCookie(ErrorPage::LANG_COOKIE, 'de')->get('/__t/404');
        $res->assertNotFound();
        $this->assertStringContainsString('lang="de"', $res->getContent());
        $this->assertStringNotContainsString('dir="rtl"', $res->getContent());
    }

    public function test_a_4xx_page_leaves_no_locale_behind_in_a_long_lived_worker(): void
    {
        $this->assertSame('en', app()->getLocale());
        $this->withHeaders(['Accept-Language' => 'de'])->get('/__t/404')->assertNotFound();
        $this->assertSame('en', app()->getLocale(), 'the render must restore the app locale (Octane reuses it)');
    }

    public function test_500_renders_with_the_database_down_and_runs_no_view_composer(): void
    {
        View::composer('*', fn () => throw new LogicException('a view composer ran on the error page'));
        $queries = 0;
        config([
            'database.connections.down' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'gone',
                'username' => 'x', 'password' => 'x', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);
        Route::get('/__t/db500', function () {
            config(['database.default' => 'down']);
            DB::purge('down');

            return DB::table('users')->count();
        });
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $res = $this->withHeaders(['Accept-Language' => 'ar'])->get('/__t/db500');

        $res->assertStatus(500);
        $html = $res->getContent();
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString(e(ErrorPage::t('Something went wrong', 'ar')), $html);
        $this->assertSame(0, $queries, 'the 500 page ran a query');
        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('gone', $html);
        $res->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_500_and_503_pages_never_leak_exception_details_when_debug_is_off(): void
    {
        $res = $this->get('/__t/500');
        $res->assertStatus(500);
        $html = $res->getContent();
        foreach (['SECRET-INTERNAL-MESSAGE', 'RuntimeException', 'Stack trace', '#0 ', base_path(), '.php'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "500 page leaks: $needle");
        }

        $this->down();
        $html = $this->get('/__t/500')->assertStatus(503)->getContent();
        foreach (['MaintenanceModeException', 'Stack trace', base_path()] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_error_pages_use_only_inline_assets(): void
    {
        $html = ErrorPage::html(500, 'ar');
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<(script|link|img)\b#i', $html);
    }

    public function test_maintenance_returns_503_with_retry_after_in_the_visitor_language(): void
    {
        $this->down(['retry' => 120, 'refresh' => 30]);

        $res = $this->withHeaders(['Accept-Language' => 'ur'])->get('/__t/ok');

        $res->assertStatus(503);
        $res->assertHeader('Retry-After', '120');
        $res->assertHeader('Refresh', '30');
        $html = $res->getContent();
        $this->assertStringContainsString('lang="ur"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString(e(ErrorPage::t('Down for maintenance', 'ur')), $html);
        $this->assertStringNotContainsString('normal-page-ok', $html);
    }

    public function test_maintenance_503_still_answers_when_the_database_is_down(): void
    {
        config([
            'database.connections.down' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'gone',
                'username' => 'x', 'password' => 'x',
            ],
            'database.default' => 'down',
            'session.driver' => 'database',
        ]);
        $this->down(['retry' => 60]);

        $res = $this->withHeaders(['Accept-Language' => 'es'])->get('/');

        $res->assertStatus(503);
        $res->assertHeader('Retry-After', '60');
        $this->assertStringContainsString('lang="es"', $res->getContent());
    }

    public function test_maintenance_bypass_secret_lets_the_operator_through(): void
    {
        $this->down(['secret' => 'let-me-in', 'retry' => 60]);

        $this->get('/__t/ok')->assertStatus(503);

        $bypass = $this->get('/let-me-in');
        $bypass->assertRedirect('/');
        $cookie = collect($bypass->headers->getCookies())->first(fn ($c) => $c->getName() === 'laravel_maintenance');
        $this->assertNotNull($cookie, 'the bypass cookie is set');

        $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())
            ->get('/__t/ok')->assertOk()->assertSee('normal-page-ok');

        // A forged cookie (wrong secret) is refused.
        $forged = MaintenanceModeBypassCookie::create('some-other-secret');
        $this->withUnencryptedCookie('laravel_maintenance', $forged->getValue())->get('/__t/ok')->assertStatus(503);
    }

    public function test_maintenance_json_requests_get_json_not_html(): void
    {
        $this->down();
        $res = $this->getJson('/__t/ok');
        $res->assertStatus(503);
        $this->assertStringNotContainsString('<html', $res->getContent());
    }

    public function test_the_static_maintenance_page_is_current_branded_and_self_contained(): void
    {
        config(['app.name' => 'juwri']);
        $path = MaintenanceStaticPage::path();
        $this->assertFileExists($path);
        $file = file_get_contents($path);
        $this->assertSame(MaintenanceStaticPage::build(), $file, 'run: php artisan juwri:maintenance-page');

        foreach (self::LOCALES as $locale) {
            $dir = in_array($locale, ['ar', 'ur'], true) ? 'rtl' : 'ltr';
            $this->assertStringContainsString('<section lang="'.$locale.'" dir="'.$dir.'"', $file);
            $this->assertStringContainsString(e(ErrorPage::t('Down for maintenance', $locale)), $file);
        }
        $this->assertStringContainsString(e(config('app.name')), $file);
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*["\']?(https?:)?//#i', $file);
        $this->assertDoesNotMatchRegularExpression('#<(link|img|iframe)\b#i', $file);
        $this->assertStringNotContainsString('<?php', $file);
    }

    public function test_the_web_server_rules_serve_the_static_page_when_php_is_down(): void
    {
        $root = dirname(base_path());
        $htaccess = file_get_contents($root.'/.htaccess');
        $this->assertStringContainsString('ErrorDocument 503 /maintenance.html', $htaccess);
        $this->assertStringContainsString('maintenance.flag', $htaccess);
        $this->assertStringContainsString('Retry-After', $htaccess);
        // P6-155: OpenLiteSpeed opt-in route (503 + Retry-After through deploy/ols/maintenance.php) and its vhost doc.
        $this->assertStringContainsString('RewriteRule ^ maintenance.php [L]', $htaccess);
        $this->assertFileExists($root.'/deploy/ols/maintenance.php');
        $ols = file_get_contents($root.'/deploy/OLS-VHOST.md');
        foreach (['smoke:extraHeaders', 'smoke:expires', 'smoke:errorPage', 'Retry-After'] as $needle) {
            $this->assertStringContainsString($needle, $ols);
        }

        $caddy = file_get_contents($root.'/docker/Caddyfile');
        $this->assertStringContainsString('handle_errors', $caddy);
        $this->assertStringContainsString('/maintenance.html', $caddy);

        $doc = file_get_contents($root.'/deploy/MAINTENANCE.md');
        foreach (['shared', 'cpanel-vps', 'docker', 'Octane', '--secret', '--retry', 'maintenance.flag', 'juwri:maintenance-page'] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }
    }
}
