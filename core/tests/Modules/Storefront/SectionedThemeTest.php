<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-3: the OS 2.0 sectioned-template engine — JSON template + section files
 * with {% schema %}, blocks, ≤25/≤50 limits, settings precedence, and merchant
 * settings (keyed by section id) surviving a theme version update.
 */
class SectionedThemeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/sectiontest';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->writeThemeVersion('1', heroBody: '<h1>{{ section.settings.heading }}</h1>');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    /** Write a sectioned test theme version to the marketplace store. */
    private function writeThemeVersion(string $version, string $heroBody): void
    {
        $dir = storage_path(self::BASE.'/'.$version);
        File::ensureDirectoryExists($dir.'/templates');
        File::ensureDirectoryExists($dir.'/sections');

        File::put($dir.'/templates/index.json', json_encode([
            'sections' => [
                'main-hero' => ['type' => 'hero', 'settings' => ['heading' => 'From Template']],
                'cards' => [
                    'type' => 'card-list',
                    'blocks' => [
                        'b1' => ['type' => 'card', 'settings' => ['label' => 'One']],
                        'b2' => ['type' => 'card'], // label falls back to block default
                    ],
                    'block_order' => ['b2', 'b1'],
                ],
            ],
            'order' => ['main-hero', 'cards'],
        ]));

        File::put($dir.'/sections/hero.liquid', $heroBody."\n".
            '{% schema %}{"name":"Hero","settings":[{"id":"heading","type":"text","default":"Default Heading"},{"id":"accent","type":"color","default":"#3fd463"}]}{% endschema %}');

        File::put($dir.'/sections/card-list.liquid',
            '{% for block in section.blocks %}[{{ block.id }}:{{ block.settings.label }}]{% endfor %}'."\n".
            '{% schema %}{"name":"Cards","blocks":[{"type":"card","settings":[{"id":"label","type":"text","default":"Card"}]}]}{% endschema %}');
    }

    private function engine(): SectionedTemplate
    {
        return new SectionedTemplate(new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient));
    }

    private function theme(string $version = '1'): MarketplaceTheme
    {
        return MarketplaceThemeResolver::resolve('mp:sectiontest@'.$version);
    }

    public function test_renders_sections_in_order_with_settings_precedence(): void
    {
        // heading: schema default "Default Heading" ← template "From Template" — template wins.
        $out = $this->engine()->render($this->theme(), []);

        $this->assertStringContainsString('<h1>From Template</h1>', $out);
        $this->assertStringContainsString('id="mb-section-main-hero"', $out);
        // blocks honour block_order (b2 first) and block-type defaults (b2 → "Card").
        $this->assertStringContainsString('[b2:Card][b1:One]', $out);
        $this->assertTrue(
            strpos($out, 'mb-section-main-hero') < strpos($out, 'mb-section-cards'),
            'sections render in template order'
        );
    }

    public function test_merchant_settings_override_template_and_survive_version_update(): void
    {
        $merchant = ['main-hero' => ['heading' => 'Merchant Heading']];

        $out = $this->engine()->render($this->theme(), [], [], $merchant);
        $this->assertStringContainsString('<h1>Merchant Heading</h1>', $out);

        // Seller ships v2 with different section markup; SAME merchant record applies.
        $this->writeThemeVersion('2', heroBody: '<h2 data-v2>{{ section.settings.heading }}</h2>');
        $out2 = $this->engine()->render($this->theme('2'), [], [], $merchant);

        $this->assertStringContainsString('<h2 data-v2>Merchant Heading</h2>', $out2, 'merchant settings survived the update');
    }

    public function test_section_count_limit_enforced(): void
    {
        $dir = storage_path(self::BASE.'/1');
        $sections = [];
        $order = [];
        for ($i = 0; $i <= SectionedTemplate::MAX_SECTIONS; $i++) {
            $sections['s'.$i] = ['type' => 'hero'];
            $order[] = 's'.$i;
        }
        File::put($dir.'/templates/index.json', json_encode(['sections' => $sections, 'order' => $order]));

        $this->expectException(RenderFailedException::class);
        $this->engine()->render($this->theme(), []);
    }

    public function test_broken_section_is_skipped_live_but_fatal_at_upload(): void
    {
        // 'ghost' type has no source file.
        $dir = storage_path(self::BASE.'/1');
        File::put($dir.'/templates/index.json', json_encode([
            'sections' => [
                'ok' => ['type' => 'hero'],
                'bad' => ['type' => 'ghost'],
            ],
            'order' => ['ok', 'bad'],
        ]));

        $live = $this->engine()->render($this->theme(), []);
        $this->assertStringContainsString('Default Heading', $live, 'healthy section still renders');
        $this->assertStringNotContainsString('mb-section-bad', $live, 'broken section skipped');

        $this->expectException(RenderFailedException::class);
        $this->engine()->render($this->theme(), [], [], [], 'upload');
    }

    public function test_block_limit_enforced_at_upload(): void
    {
        $dir = storage_path(self::BASE.'/1');
        $blocks = [];
        $order = [];
        for ($i = 0; $i <= SectionedTemplate::MAX_BLOCKS; $i++) {
            $blocks['b'.$i] = ['type' => 'card'];
            $order[] = 'b'.$i;
        }
        File::put($dir.'/templates/index.json', json_encode([
            'sections' => ['cards' => ['type' => 'card-list', 'blocks' => $blocks, 'block_order' => $order]],
            'order' => ['cards'],
        ]));

        $this->expectException(RenderFailedException::class);
        $this->engine()->render($this->theme(), [], [], [], 'upload');
    }

    public function test_section_settings_validator(): void
    {
        $schema = SectionedTemplate::extractSchema(
            File::get(storage_path(self::BASE.'/1/sections/hero.liquid'))
        );

        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, ['heading' => 'Hi', 'accent' => '#fff']));

        $errors = ThemeCustomizerService::validateSectionSettings($schema, [
            'heading' => 123,          // not a string
            'accent' => 'red',         // not a hex colour
            'nope' => 'x',             // unknown key
        ]);
        $this->assertCount(3, $errors);
    }

    public function test_public_view_renders_sectioned_theme_with_merchant_settings(): void
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:sectiontest@1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        // Merchant settings under the VERSIONLESS key.
        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'mp:sectiontest',
            'settings' => ['sections' => ['main-hero' => ['heading' => 'Live Merchant Heading']]],
        ]);

        $resp = $this->get(route('publicView', $store->slug));

        $resp->assertOk();
        $this->assertStringContainsString('Live Merchant Heading', $resp->getContent());
        $this->assertStringContainsString('mb-section-cards', $resp->getContent());
    }
}
