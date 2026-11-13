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
 * Folio Polish ("FP" run) — lane A storefront render contract.
 *
 * These pin the SERVER-rendered markup / #folioConfig that the folio.js polish
 * features read (deep-links, chips, hint, list+SEO, texture/sound, hotspots,
 * CTA/books, metrics). JS behaviour is the orchestrator's Chrome pass (SPEC
 * §15). Lane A has no FolioHelper.php, so every backend-helper call is guarded
 * with function_exists() and these tests exercise the FALLBACK branch only —
 * the merged (helper-present) branch is covered by lane B + the Chrome battery.
 */
class FolioPolishStorefrontTest extends TestCase
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

    /** Add a page with an explicit name (for section-chip grouping). */
    private function addNamedPage(Store $store, string $name, string $image): void
    {
        ImageMenu::create([
            'store_id' => $store->id,
            'name' => $name,
            'image' => $image,
            'active' => '1',
        ]);
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

    // ---- A1 deep links (JS-only; server render must be unchanged) ----

    public function test_a1_deep_link_is_a_js_only_change_server_render_unchanged(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 3);

        // ?page= is honoured purely client-side — the server still emits the
        // full ordered page list and the same book bundle, no new markup.
        $res = $this->get(route('publicView', $store->slug).'?page=2');

        $res->assertOk();
        $res->assertSeeHtml('id="folioBook"');
        $res->assertSeeHtml('assets/store_templates/folio/js/folio.js');

        $config = $this->folioConfig($res->getContent());
        $this->assertCount(3, $config['pages']);
        $this->assertStringContainsString('folio-page-1.jpg', $config['pages'][0]['url']);
    }

    // ---- A2 section chips (JS-built; server must supply page names) ----

    public function test_a2_config_pages_carry_names_for_section_grouping(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addNamedPage($store, 'Starters', 'p1.jpg');
        $this->addNamedPage($store, 'Starters', 'p2.jpg');
        $this->addNamedPage($store, 'Mains', 'p3.jpg');

        $config = $this->folioConfig($this->get(route('publicView', $store->slug))->getContent());

        // JS groups consecutive equal names into chips; it needs the names here.
        $this->assertSame(['Starters', 'Starters', 'Mains'], array_column($config['pages'], 'name'));
    }

    // ---- A3 first-visit hint (label shipped in config) ----

    public function test_a3_hint_label_is_passed_in_config_labels(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $config = $this->folioConfig($this->get(route('publicView', $store->slug))->getContent());

        $this->assertArrayHasKey('hint', $config['labels']);
        $this->assertSame('Swipe or tap to turn the page', $config['labels']['hint']);
    }

    // ---- A5 view-as-list + SEO ----

    public function test_a5_list_section_renders_every_visible_page_with_alts(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addNamedPage($store, 'Breakfast', 'p1.jpg');
        $this->addNamedPage($store, 'Lunch', 'p2.jpg');
        $this->addNamedPage($store, 'Dinner', 'p3.jpg');

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertSame(3, substr_count($html, 'class="folio-list-img"'), 'one list img per visible page');
        $this->assertMatchesRegularExpression('/id="folioList"[^>]*\bhidden\b/', $html, 'list starts hidden for JS toggle');
        // alt = the page name.
        $this->assertStringContainsString('alt="Breakfast"', $html);
        $this->assertStringContainsString('alt="Dinner"', $html);
    }

    public function test_a5_list_alt_falls_back_to_page_number_when_unnamed(): void
    {
        [, $store] = $this->makeFolioStore();
        ImageMenu::create(['store_id' => $store->id, 'name' => '', 'image' => 'p1.jpg', 'active' => '1']);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('alt="Page 1"', $html);
    }

    public function test_a5_noscript_reveals_list_and_hides_stage(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('<noscript>', $html);
        // The reveal style is nonce'd (CSP enforce ratchet) and shows the list.
        $this->assertMatchesRegularExpression('/<noscript>\s*<style nonce="[^"]+">/', $html);
        $this->assertStringContainsString('.folio-list { display: flex; }', $html);
    }

    public function test_a5_og_meta_is_pushed_to_the_layout_head(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        // og:image is the first visible page's URL.
        $this->assertMatchesRegularExpression('/property="og:image" content="[^"]*folio-page-1\.jpg/', $html);
    }

    public function test_a5_helper_absent_fallback_full_list_no_srcset_no_variants(): void
    {
        // Lane A has no FolioHelper — folio_srcset()/book helpers are absent, so
        // the list is the full capped page set, list imgs carry no srcset, and
        // the config has no pageVariants key.
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 3);

        $res = $this->get(route('publicView', $store->slug));
        $html = $res->getContent();

        $this->assertSame(3, substr_count($html, 'class="folio-list-img"'));
        $this->assertStringNotContainsString('srcset=', $html);

        $config = $this->folioConfig($html);
        $this->assertArrayNotHasKey('pageVariants', $config);
        $this->assertCount(3, $config['pages']);
    }

    public function test_a5_list_toggle_button_only_in_images_mode(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);
        $this->get(route('publicView', $store->slug))->assertSeeHtml('id="folioListToggle"');

        // PDF mode has no server page list → no list toggle.
        [, $pdfPost] = $this->makeFolioStore();
        StoreOption::updateStoreOption($pdfPost->id, 'restaurant_folio_source', 'pdf');
        StoreOption::updateStoreOption($pdfPost->id, 'restaurant_folio_pdf', 'folio-pdf/x.pdf');
        StoreOption::flushOptionMemo();
        $this->get(route('publicView', $pdfPost->slug))->assertDontSeeHtml('id="folioListToggle"')->assertDontSeeHtml('id="folioList"');
    }

    // ---- A6 ambient texture + sound ----

    public function test_a6_texture_and_sound_data_attrs_render_from_options(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        // Defaults: flat texture, sound off.
        $this->get(route('publicView', $store->slug))->assertSeeHtml('data-folio-texture=""')->assertSeeHtml('data-folio-sound="0"');

        foreach (['linen', 'wood', 'marble', 'paper'] as $texture) {
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_texture', $texture);
            StoreOption::flushOptionMemo();
            $this->get(route('publicView', $store->slug))->assertSeeHtml('data-folio-texture="'.$texture.'"');
        }

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_sound', '1');
        StoreOption::flushOptionMemo();
        $this->get(route('publicView', $store->slug))->assertSeeHtml('data-folio-sound="1"');
    }

    public function test_a6_invalid_texture_falls_back_to_flat(): void
    {
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 1);
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_texture', 'holographic');

        $this->get(route('publicView', $store->slug))->assertSeeHtml('data-folio-texture=""');
    }

    // ---- A7 hotspots (fallback: no helper ⇒ no key, page still renders) ----

    public function test_a7_hotspots_key_absent_without_helper_and_page_renders(): void
    {
        // Lane A has no folio_hotspots_payload() — the config must omit the
        // hotspots key entirely and the book must render as normal.
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 3);

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertSeeHtml('id="folioBook"');

        $config = $this->folioConfig($res->getContent());
        $this->assertArrayNotHasKey('hotspots', $config);
    }

    public function test_a7_sheet_and_search_labels_ship_in_config(): void
    {
        // JS builds the sheet/search chrome from these labels.
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $config = $this->folioConfig($this->get(route('publicView', $store->slug))->getContent());

        // Values pass through ___() (lang table may re-case), so assert the
        // keys exist and are non-empty rather than pinning exact strings.
        foreach (['close', 'soldOut', 'search'] as $key) {
            $this->assertArrayHasKey($key, $config['labels']);
            $this->assertNotSame('', $config['labels'][$key]);
        }
    }

    // ---- A8 CTA + book switcher (fallback: no helpers) ----

    public function test_a8_cta_absent_when_unconfigured(): void
    {
        // A store with no CTA option renders no CTA button (folio_cta() returns
        // null). With FolioHelper merged the `books` key is always present; an
        // unconfigured store carries an empty book list, so no switcher.
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $res = $this->get(route('publicView', $store->slug));
        $res->assertDontSeeHtml('folio-btn-cta');

        $config = $this->folioConfig($res->getContent());
        $this->assertSame([], $config['books']['list'] ?? []);
    }

    public function test_a8_book_param_is_noop_when_no_books_defined(): void
    {
        // ?book= filters nothing when the store defines no books / page tags:
        // every capped page still renders and the active book resolves null.
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 4);

        $config = $this->folioConfig(
            $this->get(route('publicView', $store->slug).'?book=lunch')->getContent()
        );

        $this->assertCount(4, $config['pages']);
        $this->assertNull($config['books']['active']);
    }

    // ---- A9 metrics beacon (merged: folio.metrics route present) ----

    public function test_a9_metrics_url_points_at_the_beacon_route(): void
    {
        // folio.metrics ships with B8; the guard now yields the real endpoint
        // and folio.js beacons page-dwell to it.
        [, $store] = $this->makeFolioStore();
        $this->addPages($store, 2);

        $config = $this->folioConfig($this->get(route('publicView', $store->slug))->getContent());

        $this->assertArrayHasKey('metricsUrl', $config);
        $this->assertSame(route('folio.metrics', $store->id), $config['metricsUrl']);
    }

    // ---- §12 pre-rastered PDF pages (fallback: no helper = legacy pdf.js) ----

    public function test_pdf_pre_raster_absent_helper_keeps_legacy_pdfjs_path(): void
    {
        // folio_pdf_pages() does not exist in lane A, so a PDF store keeps the
        // client-side pdf.js pipeline exactly as today (no server page list).
        [, $store] = $this->makeFolioStore();
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_source', 'pdf');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf', 'folio-pdf/x.pdf');
        StoreOption::flushOptionMemo();

        $res = $this->get(route('publicView', $store->slug));

        $res->assertSeeHtml('data-folio-source="pdf"');
        $res->assertSeeHtml('assets/global/pdfjs/pdf.min.js');
        $res->assertDontSeeHtml('id="folioList"'); // no server page list

        $config = $this->folioConfig($res->getContent());
        $this->assertSame('pdf', $config['source']);
        $this->assertSame([], $config['pages']);
    }
}
