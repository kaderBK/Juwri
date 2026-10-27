<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Seo;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Octane\BeginRequest;
use Juwri\Core\Support\LocalizedRoutes;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-031/BL-032 on the migrated install: root language at "/", the other languages under /{code}/, the module on.
 * `/{locale}/{store}` keeps answering 200 at the same URL (J63), `/{numericId}` opens the store by id, unknown
 * names 404, and the marketing/app routes are never shadowed by a store slug or a language prefix.
 * The prefixed route groups register at boot, so the app boots with prefix mode on (as OctaneLocalizedRoutesTest).
 */
class LegacyLocaleStorefrontRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeBootstrap(Application $app): void
    {
        putenv('JUWRI_LOCALE_ROUTES=ar,fr');
        $_ENV['JUWRI_LOCALE_ROUTES'] = $_SERVER['JUWRI_LOCALE_ROUTES'] = 'ar,fr';
        LocalizedRoutes::forget();
        $app->booting(fn () => config(['settings.include_language_code' => true, 'app.locale' => 'en', 'legacy_compat.enabled' => true]));
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
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        foreach (['en' => 'ltr', 'ar' => 'rtl', 'fr' => 'ltr'] as $code => $dir) {
            Language::updateOrCreate(['code' => $code], ['name' => strtoupper($code), 'direction' => $dir, 'active' => 1]);
        }
        config(['settings.include_language_code' => true, 'legacy_compat.enabled' => true]);
        $all = config('laravellocalization.localesMetadata');
        $list = [];
        foreach (['en', 'ar', 'fr'] as $code) {
            $list[$code] = ['name' => $code] + $all[$code];
        }
        config(['laravellocalization.supportedLocales' => $list]);
        app('laravellocalization')->setSupportedLocales($list);
        $this->app->setLocale('en');
        LegacyCompatServiceProvider::activate($this->app);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class]);
    }

    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
        (new BeginRequest)->handle((object) ['sandbox' => app()]);
    }

    private function get2(string $path): TestResponse
    {
        $this->nextRequest();

        return $this->get($path);
    }

    private function store(string $slug): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['scan_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => $slug, 'color' => '#111', 'title' => 'Store', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    public function test_store_page_keeps_200_at_root_and_under_each_language_prefix(): void
    {
        $this->store('cafe-one');

        $this->assertSame(200, $this->get2('/cafe-one')->getStatusCode());
        $fr = $this->get2('/fr/cafe-one');
        $this->assertSame(200, $fr->getStatusCode(), 'J63: 200 at the same URL, no redirect');
        $this->assertNull($fr->headers->get('Location'));
        $this->assertSame(200, $this->get2('/ar/cafe-one')->getStatusCode());
    }

    public function test_unknown_store_and_unknown_language_prefix_404(): void
    {
        $this->store('cafe-one');

        $this->assertSame(404, $this->get2('/fr/no-such-store')->getStatusCode());
        $this->assertSame(404, $this->get2('/no-such-store')->getStatusCode());
        $this->assertSame(404, $this->get2('/de/cafe-one')->getStatusCode(), 'de is not an enabled language here');
    }

    public function test_numeric_id_opens_the_store_by_id(): void
    {
        $store = $this->store('cafe-two');

        $this->assertSame(200, $this->get2('/'.$store->id)->getStatusCode());
        $this->assertSame(404, $this->get2('/'.($store->id + 1000))->getStatusCode());
    }

    public function test_marketing_routes_are_not_shadowed_by_the_storefront_catch_all(): void
    {
        $this->store('cafe-one');

        foreach (['/pricing', '/fr/pricing', '/ar/pricing', '/contact', '/fr/contact', '/blog', '/fr/blog', '/features', '/faq', '/studio'] as $path) {
            $response = $this->get2($path);
            $this->assertContains($response->getStatusCode(), [200, 301, 302], $path.' answered '.$response->getStatusCode());
            $this->assertStringNotContainsString('Store', (string) ($response->headers->get('X-Store-Page') ?? ''));
        }
        $this->assertSame('pricing', (function () {
            $this->get2('/fr/pricing');

            return Route::currentRouteName();
        })(), 'a language prefix resolves to the marketing route, not publicView');
    }

    public function test_unprefixed_store_url_honours_the_stores_default_language(): void
    {
        $store = $this->store('cafe-ar');
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['en', 'ar', 'fr']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');

        $root = $this->get2('/cafe-ar');
        $this->assertSame(200, $root->getStatusCode());
        $root->assertSee('dir="rtl"', false);
        $this->get2('/fr/cafe-ar')->assertSee('<html lang="fr"', false);
        $this->get2('/ar/cafe-ar')->assertSee('dir="rtl"', false);
    }
}
