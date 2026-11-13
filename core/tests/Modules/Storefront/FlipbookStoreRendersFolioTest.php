<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\LocalizedRoutes;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-037 (a) — a store imported from the retired `flipbook` template.
 *
 * `flipbook` was removed from the app (juwri Phase 0, WI-03), so a store that still
 * carries it in `store_template` has no template of its own to render. The theme service
 * maps it to its successor — StorefrontThemeService::RETIRED_SUCCESSORS ('flipbook' =>
 * 'folio') — because a flip-book store is image_menus-backed, exactly like folio. Without
 * the mapping the store would land on the generic fallback theme and lose its pages.
 *
 * The legs:
 *  1. the resolver maps 'flipbook' to 'folio';
 *  2. the storefront page of such a store IS the folio theme and shows every page;
 *  3. a store with an empty/unknown template never renders folio;
 *  4. the same page answers under the /ar locale prefix (that leg boots the storefront
 *     routes with a language prefix — see beforeBootstrap()).
 */
class FlipbookStoreRendersFolioTest extends TestCase
{
    use RefreshDatabase;

    /** The JUWRI_LOCALE_ROUTES value this test replaced, restored after the /ar leg. */
    private ?string $previousLocaleRoutes = null;

    /**
     * The storefront routes are registered while the app boots, so the /ar leg needs the
     * language-prefixed group: JUWRI_LOCALE_ROUTES names the codes (the worker mode
     * LocalizedRoutes registers) and include_language_code switches the storefront group
     * into its localized form. app.locale stays 'en' so 'ar' is NOT the hidden default
     * locale — mcamara would then redirect /ar/... back to the unprefixed URL.
     *
     * Only that one leg boots this way; the other legs keep the default (session) locale
     * mode the rest of the suite uses.
     */
    protected function beforeBootstrap(Application $app): void
    {
        if (! $this->wantsLocalePrefix()) {
            return;
        }

        $previous = getenv('JUWRI_LOCALE_ROUTES');
        $this->previousLocaleRoutes = $previous === false ? null : (string) $previous;

        putenv('JUWRI_LOCALE_ROUTES=ar');
        $_ENV['JUWRI_LOCALE_ROUTES'] = $_SERVER['JUWRI_LOCALE_ROUTES'] = 'ar';
        LocalizedRoutes::forget();
        $app->booting(fn () => config(['settings.include_language_code' => true, 'app.locale' => 'en']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->previousLocaleRoutes !== null) {
            putenv('JUWRI_LOCALE_ROUTES='.$this->previousLocaleRoutes);
            $_ENV['JUWRI_LOCALE_ROUTES'] = $_SERVER['JUWRI_LOCALE_ROUTES'] = $this->previousLocaleRoutes;
        } else {
            putenv('JUWRI_LOCALE_ROUTES');
            unset($_ENV['JUWRI_LOCALE_ROUTES'], $_SERVER['JUWRI_LOCALE_ROUTES']);
        }

        $this->previousLocaleRoutes = null;
        LocalizedRoutes::forget();
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        $this->supportedLocales(['en', 'ar']);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** The /ar leg boots the storefront routes with a language prefix; the others do not. */
    private function wantsLocalePrefix(): bool
    {
        return str_contains($this->name(), 'arabic_locale_prefix');
    }

    /** The locale registry the /ar prefix is served for (the mcamara service memoises it). */
    private function supportedLocales(array $codes): void
    {
        $meta = config('laravellocalization.localesMetadata');
        $list = [];
        foreach ($codes as $code) {
            $list[$code] = ['name' => $code] + ($meta[$code] ?? []);
        }
        config(['laravellocalization.supportedLocales' => $list]);
        app('laravellocalization')->setSupportedLocales($list);
    }

    /**
     * A store on a plan, with the given saved template and (optionally) picture-menu pages.
     *
     * @param  string  $template  the saved store_template ('' = the store never chose one)
     * @param  int  $pages  how many image_menus pages the store carries
     */
    private function makeStore(string $template, int $pages = 0): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'flipbook-store-'.Str::lower(Str::random(8)),
            'color' => '#111', 'title' => 'Flip '.Str::random(6), 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($template !== '') {
            StoreOption::updateStoreOption($store->id, 'store_template', $template);
        }

        for ($i = 1; $i <= $pages; $i++) {
            ImageMenu::create([
                'store_id' => $store->id,
                'name' => 'Page '.$i,
                'image' => 'flipbook-page-'.$i.'.jpg',
                'active' => '1',
                'position' => $i,
            ]);
        }

        return $store;
    }

    /** One menu item, so the FALLBACK theme (a food theme) has something to render. */
    private function addMenuItem(Store $store): void
    {
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1']);
    }

    /** The rendered #folioConfig JSON block, decoded. */
    private function folioConfig(string $html): array
    {
        $this->assertMatchesRegularExpression('/<script type="application\/json" id="folioConfig"[^>]*>(.*?)<\/script>/s', $html);
        preg_match('/<script type="application\/json" id="folioConfig"[^>]*>(.*?)<\/script>/s', $html, $m);
        $config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertIsArray($config, 'folioConfig JSON decodes');

        return $config;
    }

    // ---- 1. the resolver ----

    public function test_flipbook_resolves_to_the_folio_theme(): void
    {
        // `flipbook` is gone from disk — that is WHY the successor mapping exists.
        $this->assertFalse(view()->exists('store_templates.flipbook.index'), 'the flipbook template is retired');
        $this->assertTrue(view()->exists('store_templates.folio.index'), 'folio is on disk');

        $this->assertSame('folio', resolve_store_theme('flipbook'));
    }

    // ---- 2. the storefront page ----

    public function test_a_flipbook_store_renders_the_folio_theme_with_its_image_menu_pages(): void
    {
        $store = $this->makeStore('flipbook', 3);

        $response = $this->get('/'.$store->slug);
        $response->assertOk();
        $html = $response->getContent();

        // The folio theme itself — not the generic fallback the resolver would pick.
        $response->assertSeeHtml('id="folioTheme"');
        $response->assertSeeHtml('assets/store_templates/folio/js/folio.js');

        // Every picture-menu page is on the page, and in the store's page order.
        $this->assertSame(3, substr_count($html, 'class="folio-list-img"'), 'one list image per visible page');
        foreach (['flipbook-page-1.jpg', 'flipbook-page-2.jpg', 'flipbook-page-3.jpg'] as $image) {
            $this->assertStringContainsString($image, $html, $image.' is rendered');
        }

        $first = strpos($html, 'flipbook-page-1.jpg');
        $second = strpos($html, 'flipbook-page-2.jpg');
        $third = strpos($html, 'flipbook-page-3.jpg');
        $this->assertIsInt($first);
        $this->assertIsInt($second);
        $this->assertIsInt($third);
        $this->assertTrue($first < $second && $second < $third, 'the pages keep the store order');

        $config = $this->folioConfig($html);
        $this->assertCount(3, $config['pages']);
        $this->assertStringContainsString('flipbook-page-1.jpg', $config['pages'][0]['url']);
    }

    // ---- 3. the negative legs ----

    public function test_a_store_without_a_template_does_not_render_folio(): void
    {
        $store = $this->makeStore('');
        $this->addMenuItem($store);

        $this->assertNotSame('folio', resolve_store_theme(''));

        $response = $this->get('/'.$store->slug);
        $response->assertOk();
        $response->assertDontSeeHtml('id="folioTheme"');
        $response->assertDontSeeHtml('assets/store_templates/folio/js/folio.js');
    }

    public function test_a_store_with_an_unknown_template_does_not_render_folio(): void
    {
        $store = $this->makeStore('no-such-template');
        $this->addMenuItem($store);

        $this->assertNotSame('folio', resolve_store_theme('no-such-template'));

        $response = $this->get('/'.$store->slug);
        $response->assertOk();
        $response->assertDontSeeHtml('id="folioTheme"');
        $response->assertDontSeeHtml('assets/store_templates/folio/js/folio.js');
    }

    // ---- 4. the /ar locale prefix ----

    public function test_a_flipbook_store_renders_the_folio_theme_under_the_arabic_locale_prefix(): void
    {
        if (! Route::has('loc.publicView')) {
            $this->markTestSkipped('the storefront route is not registered with a language prefix in this environment');
        }

        $store = $this->makeStore('flipbook', 3);

        $response = $this->get('/ar/'.$store->slug);
        $response->assertOk();
        $html = $response->getContent();

        // Still the folio theme, now right-to-left for the Arabic locale.
        $response->assertSeeHtml('id="folioTheme"');
        $response->assertSeeHtml('data-folio-rtl="1"');
        $this->assertSame(3, substr_count($html, 'class="folio-list-img"'), 'one list image per visible page');
        foreach (['flipbook-page-1.jpg', 'flipbook-page-2.jpg', 'flipbook-page-3.jpg'] as $image) {
            $this->assertStringContainsString($image, $html, $image.' is rendered');
        }
    }
}
