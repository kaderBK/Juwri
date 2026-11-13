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
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-4: the existing schema-driven customizer extended to marketplace themes —
 * mp-aware schema in the UI's own shape (zero blade changes), validation
 * against per-section {% schema %}, versionless persistence, and the render
 * side honouring published values + visibility toggles.
 */
class MarketplaceCustomizerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/custest';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $dir = storage_path(self::BASE.'/1');
        File::ensureDirectoryExists($dir.'/templates');
        File::ensureDirectoryExists($dir.'/sections');
        File::ensureDirectoryExists($dir.'/config');

        File::put($dir.'/templates/index.json', json_encode([
            'sections' => [
                'hero' => ['type' => 'hero'],
                'promo' => ['type' => 'hero', 'settings' => ['heading' => 'Promo Default']],
            ],
            'order' => ['hero', 'promo'],
        ]));
        File::put($dir.'/sections/hero.liquid',
            '<h1 data-sid="{{ section.id }}">{{ section.settings.heading }}</h1>'."\n".
            '{% schema %}{"name":"Hero","settings":[{"id":"heading","type":"text","label":"Heading","default":"Hi"},{"id":"accent","type":"color","label":"Accent","default":"#3fd463"}]}{% endschema %}');
        File::put($dir.'/config/settings_schema.json', json_encode([
            ['name' => 'Brand', 'settings' => [
                ['id' => 'brand_color', 'type' => 'color', 'label' => 'Brand colour', 'default' => '#112233'],
            ]],
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function makeOwnerAndRestaurant(): array
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:custest@1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return [$owner, $store];
    }

    public function test_marketplace_schema_is_emitted_in_the_ui_shape(): void
    {
        $schema = ThemeCustomizerService::schemaFor('mp:custest@1');

        $groups = collect($schema['settings']);
        $this->assertTrue($groups->contains(fn ($g) => $g['group'] === 'Brand'), 'theme-level group present');
        $heroGroup = $groups->first(fn ($g) => str_contains($g['group'], 'hero'));
        $this->assertNotNull($heroGroup, 'section group present');
        $ids = array_column($heroGroup['fields'], 'id');
        $this->assertContains('sections.hero.heading', $ids, 'namespaced field ids');
        $this->assertSame(['hero', 'promo'], array_column($schema['sections'], 'id'), 'visibility toggles');
    }

    public function test_page_and_local_selector_share_dashboard_store_context(): void
    {
        [$owner, $first] = $this->makeOwnerAndRestaurant();
        $second = $first->replicate();
        $second->title = 'Second Store';
        $second->slug = 'second-store-'.Str::lower(Str::random(5));
        $second->save();
        StoreOption::updateStoreOption($second->id, 'store_template', 'mp:custest@1');

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $first->id])
            ->get(route('theme.customizer.page', ['store' => $second->id]));

        $response->assertOk()->assertViewHas('activeId', $second->id)->assertSeeHtml('name="context_action" value="store"')->assertSeeHtml('event.target.form.submit()');
        $this->assertSame($second->id, session('dashboard_store_id'));

        $this->withSession(['dashboard_store_id' => $first->id])
            ->get(route('theme.customizer.page'))
            ->assertOk()
            ->assertViewHas('activeId', $first->id);
    }

    public function test_settings_key_is_versionless_for_marketplace(): void
    {
        $this->assertSame('mp:custest', ThemeCustomizerService::settingsKey('mp:custest@1'));
        $this->assertSame('crave', ThemeCustomizerService::settingsKey('crave'));
    }

    public function test_owner_flow_draft_validation_and_versionless_publish(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();
        $this->actingAs($owner);

        // Schema endpoint serves the marketplace schema.
        $this->get('theme-customizer/'.$store->id)
            ->assertOk()
            ->assertJsonPath('template', 'mp:custest@1')
            ->assertJsonPath('schema.marketplace', true);

        // Invalid colour for a section field is rejected.
        $this->postJson('theme-customizer/'.$store->id.'/draft', [
            'values' => ['sections.hero.accent' => 'not-a-colour'],
        ])->assertStatus(422);

        // Valid values save; publish persists under the VERSIONLESS key.
        $this->postJson('theme-customizer/'.$store->id.'/draft', [
            'values' => ['sections.hero.heading' => 'Owner Heading', 'sections.promo' => false, 'brand_color' => '#abcdef'],
        ])->assertOk();
        $this->postJson('theme-customizer/'.$store->id.'/publish')->assertOk();

        $ts = ThemeSetting::where('store_id', $store->id)->where('template', 'mp:custest')->first();
        $this->assertNotNull($ts, 'settings stored under mp:custest (no @version)');
        $this->assertSame('Owner Heading', $ts->settings['sections.hero.heading']);
    }

    public function test_published_values_and_toggles_apply_on_the_storefront(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();
        $this->actingAs($owner);
        $this->postJson('theme-customizer/'.$store->id.'/draft', [
            'values' => ['sections.hero.heading' => 'Owner Heading', 'sections.promo' => false],
        ])->assertOk();
        $this->postJson('theme-customizer/'.$store->id.'/publish')->assertOk();
        auth()->logout();

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('Owner Heading', $html, 'published section setting applied');
        $this->assertStringContainsString('mb-section-hero', $html);
        $this->assertStringNotContainsString('mb-section-promo', $html, 'toggled-off section hidden');
        $this->assertStringNotContainsString('Promo Default', $html);
    }

    public function test_section_values_adapter_translates_flat_and_nested(): void
    {
        $out = ThemeCustomizerService::sectionValues([
            'brand_color' => '#fff',
            'sections.hero.heading' => 'H',
            'sections.promo' => false,
            'sections' => ['legacy' => ['a' => 1]],
        ]);

        $this->assertSame('H', $out['hero']['heading']);
        $this->assertTrue($out['promo']['_disabled']);
        $this->assertSame(1, $out['legacy']['a']);
        $this->assertArrayNotHasKey('brand_color', $out);
    }
}
