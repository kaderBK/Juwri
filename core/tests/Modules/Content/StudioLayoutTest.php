<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Models\ThemeSettingVersion;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\StoreLayouts;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.2: a store's own layout document (sections, order, blocks)
 * replaces the theme's index.json at render, is validated against the theme's
 * section schemas before it is stored, and travels through draft → publish →
 * history → revert with the customizer settings. Also: the customizer's saved
 * section order (`sections._order`) is finally honoured.
 */
class StudioLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/layouttest';

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
        File::put($dir.'/templates/index.json', json_encode([
            'sections' => [
                'hero' => ['type' => 'hero'],
                'list' => ['type' => 'card-list', 'blocks' => ['b1' => ['type' => 'card', 'settings' => ['label' => 'Tpl']]]],
            ],
            'order' => ['hero', 'list'],
        ]));
        File::put($dir.'/sections/hero.liquid',
            '<h1>{{ section.settings.heading | escape }}</h1>'."\n".
            '{% schema %}{"name":"Hero","settings":[{"id":"heading","type":"text","default":"Default"},{"id":"size","type":"select","options":["s","m","l"],"default":"m"}]}{% endschema %}');
        File::put($dir.'/sections/card-list.liquid',
            '{% for block in section.blocks %}[{{ block.id }}:{{ block.settings.label | escape }}]{% endfor %}'."\n".
            '{% schema %}{"name":"Cards","blocks":[{"type":"card","settings":[{"id":"label","type":"text","default":"Card"}]}]}{% endschema %}');
        File::put($dir.'/sections/note.liquid', '<p>note</p>');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function theme(): MarketplaceTheme
    {
        return MarketplaceThemeResolver::resolve('mp:layouttest@1');
    }

    private function engine(): SectionedTemplate
    {
        return new SectionedTemplate(new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient));
    }

    private function ids(string $html): array
    {
        preg_match_all('/id="mb-section-([^"]+)"/', $html, $m);

        return $m[1];
    }

    private function store(): Store
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:layouttest@1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }

    public function test_normalize_cleans_structure_and_completes_order(): void
    {
        $layout = LayoutDocument::normalize([
            'sections' => [
                'a' => ['type' => 'hero', 'settings' => ['heading' => 'x']],
                '../bad' => ['type' => 'hero'],
                'b' => ['type' => '../etc'],
                'c' => ['type' => 'card-list', 'blocks' => [
                    'k1' => ['type' => 'card'], 'k2' => ['type' => 'card'], 'x y' => ['type' => 'card'],
                ], 'block_order' => ['k2', 'nope', 'k2']],
                'd' => ['type' => 'note', 'disabled' => true],
            ],
            'order' => ['c', 'ghost', 'c'],
        ]);

        $this->assertSame(['a', 'c', 'd'], array_keys($layout['sections']));
        $this->assertSame(['c', 'a', 'd'], $layout['order'], 'listed ids first, forgotten ids appended');
        $this->assertSame(['k2', 'k1'], $layout['sections']['c']['block_order']);
        $this->assertTrue($layout['sections']['d']['disabled']);
        $this->assertSame(1, $layout['schema']);

        $tooMany = [];
        for ($i = 0; $i <= SectionedTemplate::MAX_SECTIONS; $i++) {
            $tooMany['s'.$i] = ['type' => 'note'];
        }
        $this->expectException(\InvalidArgumentException::class);
        LayoutDocument::normalize(['sections' => $tooMany]);
    }

    public function test_validate_checks_types_settings_and_blocks_against_schemas(): void
    {
        $layout = LayoutDocument::normalize(['sections' => [
            'ok' => ['type' => 'hero', 'settings' => ['heading' => 'Hi', 'size' => 'l']],
            'bad' => ['type' => 'hero', 'settings' => ['heading' => ['<script>'], 'size' => 'xl', 'nope' => 1]],
            'ghost' => ['type' => 'missing-type'],
            'list' => ['type' => 'card-list', 'blocks' => [
                'b1' => ['type' => 'card', 'settings' => ['label' => 5]],
                'b2' => ['type' => 'banner'],
            ]],
        ]]);

        $errors = LayoutDocument::validate($this->theme(), $layout);

        $this->assertEqualsCanonicalizing([
            'sections.bad.heading', 'sections.bad.size', 'sections.bad.nope',
            'sections.ghost.type',
            'sections.list.blocks.b1.label', 'sections.list.blocks.b2.type',
        ], array_keys($errors));
    }

    public function test_layout_replaces_the_template_at_render(): void
    {
        $layout = LayoutDocument::normalize([
            'sections' => [
                'intro' => ['type' => 'note'],
                'list' => ['type' => 'card-list', 'blocks' => [
                    'x' => ['type' => 'card', 'settings' => ['label' => 'Mine']],
                    'y' => ['type' => 'card'],
                ], 'block_order' => ['y', 'x']],
                'top' => ['type' => 'hero', 'settings' => ['heading' => 'Store Hero']],
                'off' => ['type' => 'hero', 'disabled' => true],
            ],
            'order' => ['top', 'intro', 'list', 'off'],
        ]);

        $out = $this->engine()->render($this->theme(), [], [], [], 'live', $layout);

        $this->assertSame(['top', 'intro', 'list'], $this->ids($out));
        $this->assertStringContainsString('<h1>Store Hero</h1>', $out);
        $this->assertStringContainsString('[y:Card][x:Mine]', $out, 'store blocks, order and block defaults');
        $this->assertStringNotContainsString('Tpl', $out, 'template blocks are not used');

        // Merchant section values still apply on top of the layout.
        $out2 = $this->engine()->render($this->theme(), [], [], ['top' => ['heading' => 'Override']], 'live', $layout);
        $this->assertStringContainsString('<h1>Override</h1>', $out2);
    }

    public function test_customizer_section_order_is_honoured_without_a_layout(): void
    {
        $merchant = ThemeCustomizerService::sectionValues(['sections._order' => ['list', 'ghost']]);
        $this->assertSame(['list', 'ghost'], $merchant['_order']);

        $out = $this->engine()->render($this->theme(), [], [], $merchant);
        $this->assertSame(['list', 'hero'], $this->ids($out));

        // A layout carries its own order; `_order` is ignored then.
        $layout = LayoutDocument::normalize(['sections' => ['hero' => ['type' => 'hero'], 'list' => ['type' => 'card-list']], 'order' => ['hero', 'list']]);
        $out2 = $this->engine()->render($this->theme(), [], [], $merchant, 'live', $layout);
        $this->assertSame(['hero', 'list'], $this->ids($out2));
    }

    public function test_draft_publish_discard_and_revert_carry_the_layout(): void
    {
        $store = $this->store();
        $theme = $this->theme();

        try {
            StoreLayouts::saveDraft($store, $theme, ['sections' => ['h' => ['type' => 'hero', 'settings' => ['size' => 'huge']]]]);
            $this->fail('invalid layout stored');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sections.h.size', $e->errors());
        }
        $this->assertNull(StoreLayouts::forStore($store->id, $theme, draft: true));

        $v1 = ['sections' => ['h' => ['type' => 'hero', 'settings' => ['heading' => 'One']]], 'order' => ['h']];
        StoreLayouts::saveDraft($store, $theme, $v1);

        $this->assertNull(StoreLayouts::forStore($store->id, $theme), 'draft is not live');
        $this->assertSame('One', StoreLayouts::forStore($store->id, $theme, draft: true)['sections']['h']['settings']['heading']);

        ThemeCustomizerService::publish($store, 'mp:layouttest@1');
        $this->assertSame('One', StoreLayouts::forStore($store->id, $theme)['sections']['h']['settings']['heading']);
        $first = ThemeSettingVersion::where('store_id', $store->id)->latest('id')->first();
        $this->assertSame('One', $first->layout['sections']['h']['settings']['heading'], 'publish snapshots the layout');

        StoreLayouts::saveDraft($store, $theme, ['sections' => ['h' => ['type' => 'hero', 'settings' => ['heading' => 'Two']]]]);
        ThemeCustomizerService::discardDraft($store, 'mp:layouttest@1');
        $this->assertSame('One', StoreLayouts::forStore($store->id, $theme, draft: true)['sections']['h']['settings']['heading'], 'discard drops the layout draft');

        StoreLayouts::saveDraft($store, $theme, ['sections' => ['h' => ['type' => 'hero', 'settings' => ['heading' => 'Two']]]]);
        ThemeCustomizerService::publish($store, 'mp:layouttest@1');
        $this->assertSame('Two', StoreLayouts::forStore($store->id, $theme)['sections']['h']['settings']['heading']);

        ThemeCustomizerService::revertToVersion($store, 'mp:layouttest@1', $first->id);
        $this->assertSame('One', StoreLayouts::forStore($store->id, $theme)['sections']['h']['settings']['heading'], 'revert restores the layout');
        $this->assertSame('mp:layouttest', ThemeSetting::where('store_id', $store->id)->value('template'), 'versionless key');
    }

    public function test_public_storefront_renders_the_published_layout(): void
    {
        $store = $this->store();
        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'mp:layouttest',
            'settings' => [],
            'layout' => LayoutDocument::normalize([
                'sections' => ['n' => ['type' => 'note'], 'h' => ['type' => 'hero', 'settings' => ['heading' => 'Live Layout']]],
                'order' => ['h', 'n'],
            ]),
            'layout_draft' => LayoutDocument::normalize(['sections' => ['d' => ['type' => 'hero', 'settings' => ['heading' => 'Secret Draft']]]]),
        ]);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('Live Layout', $html);
        $this->assertStringNotContainsString('Secret Draft', $html, 'drafts never reach customers');
        $this->assertSame(['h', 'n'], $this->ids($html));
    }

    public function test_commerce_runtime_is_emitted_once_across_sections(): void
    {
        $dir = storage_path(self::BASE.'/1');
        File::put($dir.'/sections/buy.liquid', '<p data-buy>{% commerce %}</p>');
        $layout = LayoutDocument::normalize([
            'sections' => ['a' => ['type' => 'buy'], 'b' => ['type' => 'buy'], 'c' => ['type' => 'buy']],
            'order' => ['a', 'b', 'c'],
        ]);

        $out = $this->engine()->render($this->theme(), [], [
            'commerce_config' => ['storeId' => 1],
            'commerce_runtime_url' => '/assets/commerce-runtime/v1/commerce.js',
            'csp_nonce' => 'n',
        ], [], 'live', $layout);

        $this->assertSame(3, substr_count($out, 'data-buy'));
        $this->assertSame(1, substr_count($out, 'window.MB_COMMERCE='), 'one runtime per page, not per section');
    }
}
