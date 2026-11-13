<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\ThemeSettingValues;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.3: the Liquid `settings` global carries the theme-level
 * settings from config/settings_schema.json — schema defaults ← published
 * customizer values ← the store layout's `theme.settings` — and only ids the
 * schema declares (it used to be always empty).
 */
class StudioThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/settingstest';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $dir = storage_path(self::BASE.'/1');
        File::ensureDirectoryExists($dir.'/templates');
        File::ensureDirectoryExists($dir.'/sections');
        File::ensureDirectoryExists($dir.'/config');
        File::put($dir.'/config/settings_schema.json', json_encode([
            ['name' => 'Brand', 'settings' => [
                ['id' => 'brand_color', 'type' => 'color', 'label' => 'Brand', 'default' => '#111111'],
                ['id' => 'tagline', 'type' => 'text', 'label' => 'Tagline', 'default' => 'Fresh daily'],
            ]],
            ['name' => 'Catalog', 'settings' => [
                ['id' => 'show_ratings', 'type' => 'checkbox', 'label' => 'Ratings', 'default' => true],
            ]],
        ]));
        File::put($dir.'/templates/index.json', json_encode(['sections' => ['s' => ['type' => 'probe']], 'order' => ['s']]));
        File::put($dir.'/sections/probe.liquid',
            '<p data-probe>{{ settings.brand_color }}|{{ settings.tagline | escape }}|{% if settings.show_ratings %}on{% else %}off{% endif %}</p>');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function theme(): MarketplaceTheme
    {
        return MarketplaceThemeResolver::resolve('mp:settingstest@1');
    }

    public function test_resolve_applies_defaults_then_customizer_then_layout(): void
    {
        $theme = $this->theme();

        $this->assertSame(
            ['brand_color' => '#111111', 'tagline' => 'Fresh daily', 'show_ratings' => true],
            ThemeSettingValues::resolve($theme)
        );

        $values = ThemeSettingValues::resolve(
            $theme,
            ['brand_color' => '#222222', 'show_ratings' => false, 'sections.s.x' => 'leak', 'stale_key' => 'x', 'tagline' => ['not', 'scalar']],
            ['theme' => ['settings' => ['brand_color' => '#333333', 'unknown' => 'y']]],
        );

        $this->assertSame(['brand_color' => '#333333', 'tagline' => 'Fresh daily', 'show_ratings' => false], $values);
    }

    public function test_layout_theme_settings_are_validated(): void
    {
        $layout = LayoutDocument::normalize([
            'sections' => ['s' => ['type' => 'probe']],
            'theme' => ['settings' => ['brand_color' => 'red', 'show_ratings' => 'yes', 'nope' => 1, 'tagline' => 'ok']],
        ]);

        $this->assertEqualsCanonicalizing(
            ['theme.settings.brand_color', 'theme.settings.show_ratings', 'theme.settings.nope'],
            array_keys(LayoutDocument::validate($this->theme(), $layout))
        );
    }

    public function test_storefront_templates_read_the_settings_global(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:settingstest@1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringContainsString('<p data-probe>#111111|Fresh daily|on</p>', $html, 'defaults');

        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'mp:settingstest',
            'settings' => ['tagline' => '<b>Hot</b>', 'show_ratings' => false],
            'layout' => ['sections' => ['s' => ['type' => 'probe']], 'order' => ['s'], 'theme' => ['settings' => ['brand_color' => '#abcdef']]],
        ]);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringContainsString('<p data-probe>#abcdef|&lt;b&gt;Hot&lt;/b&gt;|off</p>', $html, 'customizer + layout values');
    }
}
