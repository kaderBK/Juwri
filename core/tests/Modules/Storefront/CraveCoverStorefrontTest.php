<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * CC run — crave renders the store's cover_image + sub_title. The three fields
 * (cover hero, splash subtitle, cover-backed splash) are auto-on when a cover
 * exists and switch off through the customizer. A store with no cover (or the
 * toggles off) renders exactly the old flat markup — the append-layer contract.
 *
 * These pin the SERVER-rendered shell; the collapse animation and live text
 * preview are the Chrome pass.
 */
class CraveCoverStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /**
     * @param  array<string,mixed>  $attrs  Store overrides (cover_image, sub_title)
     * @param  array<string,mixed>  $settings  crave ThemeSetting values (published)
     */
    private function store(array $attrs = [], array $settings = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Crave House', 'sub_title' => '', 'description' => 'Some description',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ], $attrs));
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1']);

        if ($settings) {
            // The published layer is exactly what a customizer publish writes — asserting
            // the storefront reflects it IS the publish-parity check (no draft preview).
            ThemeSetting::create(['store_id' => $store->id, 'template' => 'crave', 'settings' => $settings]);
        }

        return $store;
    }

    private function render(Store $store): string
    {
        return $this->get('/'.$store->slug)->assertOk()->getContent();
    }

    // ── Concept 1: cover hero band ──────────────────────────────────────────

    public function test_a_cover_store_renders_the_hero_band_and_tagline(): void
    {
        $store = $this->store([
            'cover_image' => 'showcase2-diner-interior.jpg',
            'sub_title' => 'American grill and late-night cravings',
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('class="cv-cover"', $html);
        $this->assertStringContainsString('cv-cover-img', $html);
        $this->assertStringContainsString('brand-row--hero', $html);
        $this->assertStringContainsString('class="cv-tagline">American grill and late-night cravings<', $html);
        // The cover URL points at the cover directory, never the logo.
        $this->assertStringContainsString('store/cover/', $html);
    }

    public function test_a_store_without_a_cover_keeps_the_flat_brand_row(): void
    {
        $store = $this->store(['cover_image' => 'default.png', 'sub_title' => 'ignored']);

        $html = $this->render($store);

        $this->assertStringNotContainsString('class="cv-cover"', $html);
        $this->assertStringNotContainsString('brand-row--hero', $html);
        $this->assertStringNotContainsString('cv-tagline', $html);
        // The legacy brand row is untouched.
        $this->assertStringContainsString('id="cvBrandRow"', $html);
    }

    public function test_default_png_cover_is_treated_as_no_cover(): void
    {
        // An empty cover_image is the same as none — neither may light up the hero.
        $store = $this->store(['cover_image' => '', 'sub_title' => 'x']);

        $this->assertStringNotContainsString('class="cv-cover"', $this->render($store));
    }

    public function test_show_cover_toggle_off_kills_the_hero(): void
    {
        $store = $this->store(
            ['cover_image' => 'showcase2-diner-interior.jpg', 'sub_title' => 'x'],
            ['brand.show_cover' => false],
        );

        $html = $this->render($store);

        $this->assertStringNotContainsString('class="cv-cover"', $html);
        $this->assertStringNotContainsString('brand-row--hero', $html);
        $this->assertStringContainsString('id="cvBrandRow"', $html);
    }

    public function test_hiding_the_brand_row_also_removes_the_hero(): void
    {
        // The cover is part of the brand presentation: no brand row, no hero.
        $store = $this->store(
            ['cover_image' => 'showcase2-diner-interior.jpg', 'sub_title' => 'x'],
            ['sections.brand_row' => false],
        );

        $html = $this->render($store);

        $this->assertStringNotContainsString('class="cv-cover"', $html);
        $this->assertStringNotContainsString('id="cvBrandRow"', $html);
    }

    public function test_the_condensed_header_shell_only_ships_for_cover_stores(): void
    {
        $withCover = $this->store(['cover_image' => 'showcase2-diner-interior.jpg']);
        $this->assertStringContainsString('id="cvCondensed"', $this->render($withCover));

        $noCover = $this->store(['cover_image' => 'default.png']);
        $this->assertStringNotContainsString('id="cvCondensed"', $this->render($noCover));
    }

    // ── Concept 2: splash subtitle ──────────────────────────────────────────

    public function test_splash_shows_the_subtitle_from_sub_title(): void
    {
        $store = $this->store(['sub_title' => 'Late night eats']);

        $html = $this->render($store);

        $this->assertStringContainsString('class="splash-sub" data-tc-text="texts.hero_subtitle">Late night eats<', $html);
    }

    public function test_hero_subtitle_setting_overrides_the_splash_subtitle(): void
    {
        // Coverless so sub_title can only surface through the splash line, never a tagline.
        $store = $this->store(
            ['cover_image' => 'default.png', 'sub_title' => 'SubtitleFromField'],
            ['texts.hero_subtitle' => 'OverrideWins'],
        );

        $html = $this->render($store);

        $this->assertStringContainsString('data-tc-text="texts.hero_subtitle">OverrideWins<', $html);
        $this->assertStringNotContainsString('texts.hero_subtitle">SubtitleFromField<', $html);
    }

    public function test_splash_subtitle_is_absent_when_both_sources_are_empty(): void
    {
        $store = $this->store(['sub_title' => '']);

        $this->assertStringNotContainsString('splash-sub', $this->render($store));
    }

    // ── Concept 3: cover-backed splash ──────────────────────────────────────

    public function test_cover_backed_splash_layer_is_present_with_a_cover(): void
    {
        $store = $this->store(['cover_image' => 'showcase2-diner-interior.jpg']);

        $html = $this->render($store);

        $this->assertStringContainsString('splash--cover', $html);
        $this->assertStringContainsString('cv-splash-cover', $html);
    }

    public function test_cover_backed_splash_layer_is_absent_without_a_cover(): void
    {
        $store = $this->store(['cover_image' => 'default.png']);

        $this->assertStringNotContainsString('cv-splash-cover', $this->render($store));
    }

    public function test_cover_splash_toggle_off_removes_only_the_splash_layer(): void
    {
        $store = $this->store(
            ['cover_image' => 'showcase2-diner-interior.jpg'],
            ['brand.cover_splash' => false],
        );

        $html = $this->render($store);

        $this->assertStringNotContainsString('cv-splash-cover', $html);
        // The hero band is a separate toggle — it stays.
        $this->assertStringContainsString('class="cv-cover"', $html);
    }

    // ── Concept wiring: theme.json ──────────────────────────────────────────

    public function test_theme_json_exposes_the_three_customizer_fields_with_defaults(): void
    {
        $json = json_decode(file_get_contents(base_path('../assets/store_templates/crave/theme.json')), true);
        $this->assertIsArray($json, 'crave theme.json must be valid JSON');

        $fields = [];
        foreach ($json['settings'] as $group) {
            foreach (($group['fields'] ?? []) as $key => $field) {
                // "Texts"-style groups list fields; "tokens"-style keep them keyed.
                $id = is_array($field) ? ($field['id'] ?? $key) : $key;
                $fields[$id] = is_array($field) ? ($field['default'] ?? null) : ($field->default ?? null);
            }
        }

        $this->assertArrayHasKey('texts.hero_subtitle', $fields);
        $this->assertSame('', $fields['texts.hero_subtitle']);
        $this->assertArrayHasKey('brand.show_cover', $fields);
        $this->assertTrue($fields['brand.show_cover']);
        $this->assertArrayHasKey('brand.cover_splash', $fields);
        $this->assertTrue($fields['brand.cover_splash']);
    }
}
