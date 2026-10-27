<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ImageMenu;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Folio (flip-book) storefront render contract — rev/folio-theme/SPEC.md §4/§6.
 *
 * Display-only theme: image pages (image_menus) or an uploaded PDF are shown
 * as a StPageFlip book. These tests pin the guest render path: the page-flip
 * bundle is served, the option contract maps onto data-attrs / CSS vars, the
 * empty state renders without JS errors, and RTL reverses the page order.
 */
class FolioThemeRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        // Mirror the shared $settings global the installed app boots with
        // (APP_INSTALLED is unset in the test env, which kills the composer).
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function makeFolioStore(int $menuLimit = 999): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => $menuLimit, 'category_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'F'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'folio');

        return [$owner, $store];
    }

    private function addPages(Store $store, int $count, string $prefix = 'folio-page'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ImageMenu::create([
                'store_id' => $store->id,
                'name' => 'Page '.$i,
                'image' => "{$prefix}-{$i}.jpg",
                'active' => '1',
            ]);
        }
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

    public function test_guest_render_shows_flip_book_with_page_flip_bundle(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 3);

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('id="folioTheme"');
        $res->assertSeeHtml('assets/global/page-flip/page-flip.browser.js');
        $res->assertSeeHtml('data-folio-source="images"');
        $res->assertSeeHtml('id="folioBook"');
        $res->assertSeeHtml('id="folioLoader"');

        $config = $this->folioConfig($res->getContent());
        $this->assertSame('images', $config['source']);
        $this->assertCount(3, $config['pages']);
        $this->assertStringContainsString('folio-page-1.jpg', $config['pages'][0]['url']);
    }

    public function test_empty_store_renders_empty_state_not_the_book(): void
    {
        [, $store] = $this->makeFolioStore();

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('id="folioTheme"');
        $res->assertSeeHtml('folio-empty');
        $res->assertDontSeeHtml('id="folioBook"');
    }

    public function test_inactive_pages_are_excluded(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);
        ImageMenu::create(['store_id' => $store->id, 'name' => 'Hidden', 'image' => 'folio-hidden.jpg', 'active' => '0']);

        $config = $this->folioConfig($this->get(route('publicView', $store->slug))->getContent());

        $this->assertCount(2, $config['pages']);
        $this->assertStringNotContainsString('folio-hidden.jpg', json_encode($config['pages']));
    }

    public function test_display_mode_options_map_onto_data_attrs(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        // Defaults: auto display, flip anim, soft cover.
        $res = $this->get(route('publicView', $store->slug));
        $res->assertSeeHtml('data-folio-display="auto"');
        $res->assertSeeHtml('data-folio-anim="flip"');
        $res->assertSeeHtml('data-folio-hardcover="0"');

        foreach (['single', 'double'] as $display) {
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_display', $display);
            StoreOption::flushOptionMemo();
            $this->get(route('publicView', $store->slug))->assertSeeHtml('data-folio-display="'.$display.'"');
        }

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_anim', 'swipe');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_hardcover', '1');
        StoreOption::flushOptionMemo();
        $res = $this->get(route('publicView', $store->slug));
        $res->assertSeeHtml('data-folio-anim="swipe"');
        $res->assertSeeHtml('data-folio-hardcover="1"');

        $config = $this->folioConfig($res->getContent());
        $this->assertSame('swipe', $config['anim']);
        $this->assertTrue($config['hardcover']);
    }

    public function test_invalid_display_option_falls_back_to_auto(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 1);
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_display', 'bogus');

        $this->get(route('publicView', $store->slug))->assertSeeHtml('data-folio-display="auto"');
    }

    public function test_toolbar_renders_default_controls(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $res = $this->get(route('publicView', $store->slug));

        $res->assertSeeHtml('id="folioThumbs"');   // tb_thumbs default 1
        $res->assertSeeHtml('id="folioZoom"');     // tb_zoom default 1
        $res->assertSeeHtml('id="folioFull"');     // tb_fullscreen default 1
        $res->assertSeeHtml('id="folioPrev"');
        $res->assertSeeHtml('id="folioNext"');
        $res->assertSeeHtml('id="folioCur"');
        $res->assertDontSeeHtml('id="folioDownload"'); // tb_download default 0
    }

    public function test_toolbar_toggles_hide_their_controls(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_tb_thumbs', '0');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_tb_zoom', '0');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_tb_fullscreen', '0');

        $res = $this->get(route('publicView', $store->slug));

        $res->assertDontSeeHtml('id="folioThumbs"');
        $res->assertDontSeeHtml('id="folioZoom"');
        $res->assertDontSeeHtml('id="folioFull"');
        // Core navigation never disappears.
        $res->assertSeeHtml('id="folioPrev"');
        $res->assertSeeHtml('id="folioCur"');
    }

    public function test_download_button_requires_pdf_source(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);
        // Toggle on, but the source is images — no PDF to download.
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_tb_download', '1');

        $this->get(route('publicView', $store->slug))->assertDontSeeHtml('id="folioDownload"');
    }

    public function test_pdf_source_renders_pdf_pipeline(): void
    {
        [, $store] = $this->makeFolioStore();
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_source', 'pdf');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf', 'folio-pdf/sample.pdf');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_tb_download', '1');

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('data-folio-source="pdf"');
        $res->assertSeeHtml('assets/global/pdfjs/pdf.min.js');
        $res->assertSeeHtml('assets/global/pdfjs/render-pdf.js');
        $res->assertSeeHtml('id="folioDownload"'); // pdf + tb_download=1
        $res->assertSeeHtml('id="folioError"');    // graceful-failure panel exists

        $config = $this->folioConfig($res->getContent());
        $this->assertSame('pdf', $config['source']);
        $this->assertStringContainsString('storage/folio-pdf/sample.pdf', $config['pdfUrl']);
        $this->assertSame([], $config['pages'], 'pdf pages are rasterised client-side');
    }

    public function test_pdf_source_without_file_degrades_to_images(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_source', 'pdf'); // no pdf path saved

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('data-folio-source="images"');
        $res->assertDontSeeHtml('assets/global/pdfjs/pdf.min.js');
        $this->assertCount(2, $this->folioConfig($res->getContent())['pages']);
    }

    public function test_menu_entitlement_caps_page_count(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [, $store] = $this->makeFolioStore(menuLimit: 2);
        $this->addPages($store, 4);

        $config = $this->folioConfig($this->get(route('publicView', $store->slug))->getContent());

        $this->assertCount(2, $config['pages'], '999 is the unlimited sentinel; a bounded plan caps the pages');
        $this->assertStringNotContainsString('folio-page-3.jpg', json_encode($config['pages']));
    }

    public function test_color_options_render_as_css_vars_on_the_root(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 1);

        // Defaults first (SPEC §3).
        $this->get(route('publicView', $store->slug))->assertSeeHtml('--folio-bg: #1f2430; --folio-accent: #6d28d9;');

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_bg', '#123456');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_accent', '#ab12cd');
        StoreOption::flushOptionMemo();

        $this->get(route('publicView', $store->slug))->assertSeeHtml('--folio-bg: #123456; --folio-accent: #ab12cd;');
    }

    public function test_malformed_color_options_fall_back_to_defaults(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 1);
        // A style-attribute breakout attempt must never be echoed.
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_bg', 'red;background:url(x)');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_accent', '"><script>x</script>');

        $res = $this->get(route('publicView', $store->slug));

        $res->assertSeeHtml('--folio-bg: #1f2430; --folio-accent: #6d28d9;');
        $res->assertDontSeeHtml('url(x)');
    }

    public function test_live_preview_receiver_renders_for_owner_preview_only(): void
    {
        [$owner, $store] = $this->makeFolioStore();
        $this->addPages($store, 1);

        // A public guest never gets the postMessage listener.
        $this->get(route('publicView', $store->slug))->assertDontSeeHtml('tc-ready');

        // The owner previewing in the customizer iframe does (WI-U3 bridge).
        $this->actingAs($owner)->get(route('publicView', $store->slug).'?preview_theme=1')->assertSeeHtml('tc-ready');
    }

    public function test_rtl_locale_reverses_page_order_and_stamps_attr(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 3);
        // The storefront renders in the store's default menu language.
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['ar', 'en']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('data-folio-rtl="1"');
        $res->assertSeeHtml('dir="rtl"');

        $config = $this->folioConfig($res->getContent());
        $this->assertTrue($config['rtl']);
        $this->assertCount(3, $config['pages']);
        // Reversed: the book opens right-to-left, logical page 1 is LAST.
        $this->assertStringContainsString('folio-page-3.jpg', $config['pages'][0]['url']);
        $this->assertStringContainsString('folio-page-1.jpg', $config['pages'][2]['url']);
    }

    public function test_ltr_store_has_no_rtl_attr(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $res = $this->get(route('publicView', $store->slug));

        $res->assertDontSeeHtml('data-folio-rtl');
        $config = $this->folioConfig($res->getContent());
        $this->assertFalse($config['rtl']);
        $this->assertStringContainsString('folio-page-1.jpg', $config['pages'][0]['url']);
    }
}
