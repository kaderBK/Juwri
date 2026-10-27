<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Language;
use Juwri\Core\Octane\BeginRequest;
use Juwri\Core\Support\LocalizedRoutes;
use Tests\TestCase;

/**
 * Phase 2 / item 16 follow-up (docker test 2026-10-07): inside an Octane worker the routes are registered once, at boot,
 * so a language prefix taken from the boot request (`/ar/pricing`) never existed there: every prefixed URL answered 404
 * while php-fpm served it. In worker mode the localized groups are now registered for every active language up front
 * (LocalizedRoutes). This test boots ONE application in that mode (JUWRI_LOCALE_ROUTES=ar,fr) and serves several
 * "requests" from it, in both orders, the way a worker does.
 */
class OctaneLocalizedRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Before the config files load: name the languages, and switch the "language code in the URL" mode on for the boot
     *  (the platform setting is not in the empty test database, and the route files read it while they register). */
    protected function beforeBootstrap(Application $app): void
    {
        putenv('JUWRI_LOCALE_ROUTES=ar,fr');
        $_ENV['JUWRI_LOCALE_ROUTES'] = $_SERVER['JUWRI_LOCALE_ROUTES'] = 'ar,fr';
        LocalizedRoutes::forget();
        $app->booting(fn () => config(['settings.include_language_code' => true, 'app.locale' => 'en']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('JUWRI_LOCALE_ROUTES');
        unset($_ENV['JUWRI_LOCALE_ROUTES'], $_SERVER['JUWRI_LOCALE_ROUTES']);
        LocalizedRoutes::forget();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'Français', 'direction' => 'ltr', 'active' => 1]);
        config(['settings.include_language_code' => true]);
        $this->supported(['en', 'ar', 'fr']);
        $this->app->setLocale('en');
        $this->withoutMiddleware([Installed::class]);
    }

    /** The languages the request-time list holds (production reads it from the active `languages` rows). */
    private function supported(array $codes): void
    {
        $all = config('laravellocalization.localesMetadata');
        $list = [];
        foreach ($codes as $code) {
            $list[$code] = ['name' => $code] + $all[$code];
        }
        config(['laravellocalization.supportedLocales' => $list]);
        app('laravellocalization')->setSupportedLocales($list);
    }

    /** What Octane does between two requests of a worker. */
    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
        (new BeginRequest)->handle((object) ['sandbox' => app()]);
    }

    private function page(string $path, string $lang, string $dir): void
    {
        $this->nextRequest();
        $response = $this->get($path);
        $this->assertSame(200, $response->getStatusCode(), $path.' answered '.$response->getStatusCode().' '.$response->headers->get('Location'));
        $response->assertSee('<html lang="'.$lang.'" dir="'.$dir.'"', false);
    }

    public function test_the_worker_registers_one_prefixed_group_for_the_supported_languages(): void
    {
        $this->assertSame(['ar', 'fr'], LocalizedRoutes::registered());
        $this->assertTrue(Route::has('pricing'));
        $this->assertTrue(Route::has('loc.pricing'));
        $this->assertFalse(Route::has('ar.pricing'), 'one {locale} group, not one group per language');
    }

    public function test_one_app_instance_serves_the_default_and_two_prefixed_languages_in_any_order(): void
    {
        foreach ([['/pricing', 'en', 'ltr'], ['/ar/pricing', 'ar', 'rtl'], ['/fr/pricing', 'fr', 'ltr']] as [$p, $l, $d]) {
            $this->page($p, $l, $d);
        }
        foreach ([['/fr/pricing', 'fr', 'ltr'], ['/ar/pricing', 'ar', 'rtl'], ['/pricing', 'en', 'ltr'], ['/ar/pricing', 'ar', 'rtl'], ['/pricing', 'en', 'ltr']] as [$p, $l, $d]) {
            $this->page($p, $l, $d);
        }
    }

    public function test_the_matched_route_keeps_its_plain_name_and_links_follow_the_requests_language(): void
    {
        $this->nextRequest();
        $this->get('/ar/pricing')->assertOk();
        $this->assertSame('pricing', Route::currentRouteName(), 'routeIs() and cache keys see the same name as under php-fpm');
        $this->assertSame('/ar/pricing', parse_url(route('pricing'), PHP_URL_PATH), 'on an /ar page, route() answers the Arabic URL');

        $this->nextRequest();
        $this->get('/pricing')->assertOk();
        $this->assertSame('pricing', Route::currentRouteName());
        $this->assertSame('/pricing', parse_url(route('pricing'), PHP_URL_PATH), 'the next, unprefixed request does not inherit /ar');
    }

    /** P6-23b: the cloned `loc.*` route kept the compiled pattern/parameter names of an earlier request, so after a few
     *  requests in one worker the bare `/ar` and `/fr` (and URL generation with a parameter) answered 500. */
    public function test_bare_language_roots_and_url_generation_survive_worker_reuse(): void
    {
        foreach ([['/ar', 'ar', 'rtl'], ['/fr', 'fr', 'ltr'], ['/ar/pricing', 'ar', 'rtl'], ['/fr/for/jo', 'fr', 'ltr'], ['/ar/for/jo', 'ar', 'rtl'], ['/en', 'en', 'ltr'], ['/ar', 'ar', 'rtl'], ['/fr', 'fr', 'ltr']] as [$p, $l, $d]) {
            $this->nextRequest();
            $response = $this->get($p);
            $this->assertLessThan(500, $response->getStatusCode(), $p.' answered '.$response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $response->assertSee('<html lang="'.$l.'" dir="'.$d.'"', false);
            }
            $pre = $l === 'en' ? '' : '/'.$l;
            $this->assertSame($pre.'/for/sa', parse_url(route('country.landing', ['sa']), PHP_URL_PATH), 'positional arg fills {country}, not {locale}, after '.$p);
            $this->assertSame($pre.'/for/sa', parse_url(route('country.landing', ['country' => 'sa']), PHP_URL_PATH));
            $this->assertSame($pre.'/pricing', parse_url(route('pricing'), PHP_URL_PATH));
            $this->assertSame($pre === '' ? '/' : $pre, (parse_url(route('home'), PHP_URL_PATH) ?? '/'), 'the matched route itself, generated right after it matched');
        }
        $this->nextRequest();
        $this->get('/pricing')->assertOk();
        $this->assertSame('/pricing', parse_url(route('pricing'), PHP_URL_PATH));
    }

    /** Deterministic form of the same bug: a request that MATCHED `loc.x` leaves its compiled pattern and parameter names
     *  on that route; the language copy cloned from it afterwards must not inherit them. */
    public function test_the_language_copy_of_an_already_matched_route_does_not_inherit_its_compiled_state(): void
    {
        $original = Route::getRoutes()->getByName('loc.country.landing');
        $this->assertSame(['locale', 'country'], $original->parameterNames(), 'the matched original is compiled with {locale}');
        $original->getCompiled();

        foreach (['ar', 'fr', 'ar'] as $lang) {
            $lookup = new \Juwri\Core\Routing\LocalizedRouteLookup(Route::getRoutes(), fn () => $lang);
            $copy = $lookup->getByName('country.landing');
            $this->assertSame($lang.'/for/{country}', $copy->uri());
            $this->assertSame(['country'], $copy->parameterNames(), 'no stale {locale} parameter on the '.$lang.' copy');
            $this->assertSame('/'.$lang.'/for/sa', app('url')->toRoute($copy, ['sa'], false), 'positional arg fills {country}');
        }
        $this->assertSame(['locale', 'country'], $original->parameterNames(), 'the shared original is untouched');
    }

    public function test_a_language_switched_off_since_the_worker_started_answers_404(): void
    {
        Language::where('code', 'fr')->update(['active' => 0]);
        $this->supported(['en', 'ar']);
        $this->nextRequest();
        $this->get('/fr/pricing')->assertNotFound();
        $this->nextRequest();
        $this->get('/ar/pricing')->assertOk();
    }

    public function test_a_language_that_was_never_registered_has_no_routes(): void
    {
        Language::updateOrCreate(['code' => 'de'], ['name' => 'Deutsch', 'direction' => 'ltr', 'active' => 1]);
        $this->nextRequest();
        $this->get('/de/pricing')->assertNotFound();
    }

    public function test_every_localized_route_has_one_prefixed_copy_independent_of_language_count(): void
    {
        $localized = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getAction('locale_prefix') === null) {
                continue;
            }
            $localized++;
            $this->assertStringStartsWith('{locale}', $route->uri());
            $this->assertMatchesRegularExpression('/^(\w+\|)*\w+$/', $route->wheres['locale'] ?? '');
        }
        $this->assertGreaterThan(100, $localized, 'the localized groups hold the marketing, storefront and studio routes');
    }

    public function test_route_count_does_not_grow_with_the_number_of_languages(): void
    {
        $added = function (string $codes, string $uri): int {
            config(['laravellocalization.localeRoutes' => $codes]);
            LocalizedRoutes::forget();
            $before = count(Route::getRoutes()->getRoutes());
            LocalizedRoutes::group(['localized' => true], fn () => Route::get($uri, fn () => 'x'));

            return count(Route::getRoutes()->getRoutes()) - $before;
        };

        $this->assertSame(2, $added('ar,fr', 'probe-two'), 'one prefixed + one plain');
        $this->assertSame(2, $added('ar,fr,de,es,hi,pt,ru,tr,ur,zh,en', 'probe-eleven'));
    }

    public function test_the_route_table_still_compiles_for_route_cache(): void
    {
        // route:cache fails on duplicate names and on closures; the per-language copies must not introduce either.
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();
        $compiled = $routes->compile();
        $this->assertArrayHasKey('attributes', $compiled);
        $this->assertArrayHasKey('loc.pricing', $compiled['attributes']);
    }
}
