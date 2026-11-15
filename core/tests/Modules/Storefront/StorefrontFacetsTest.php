<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\Rendering\LiquidRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\ItemDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\StorefrontFacets;
use Juwri\Modules\Storefront\Storefront\SwatchColor;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The catalogue facet panel — size, colour, price, plus clothing variant
 * labels and card-level quick-add vs variant-sheet guards.
 */
class StorefrontFacetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_the_axis_is_found_by_the_dimension_name(): void
    {
        $this->assertSame(['size' => 'Size', 'color' => 'Color'],
            StorefrontFacets::axes(['Size' => 'M', 'Color' => 'Red']));

        $this->assertSame(['size' => 'SIZE', 'color' => 'Colour'],
            StorefrontFacets::axes(['SIZE' => 'XL', 'Colour' => 'Green']));

        $this->assertSame(['size' => null, 'color' => null],
            StorefrontFacets::axes(['Style' => 'Classic']));
    }

    public function test_a_variant_carries_the_facet_values_of_its_axis(): void
    {
        $this->assertSame(['size' => 'M', 'color' => 'Red'],
            StorefrontFacets::values(['Size' => 'M', 'Color' => 'Red']));

        $this->assertSame(['size' => 'S'],
            StorefrontFacets::values(['Size' => 'S']));
    }

    public function test_the_swatch_colour_follows_one_rule_for_the_platform(): void
    {
        $this->assertSame('#2563eb', SwatchColor::for('#2563eb'));
        $this->assertSame('#111111', SwatchColor::for('Black'));
        $this->assertSame('#1e3a8a', SwatchColor::for('Navy Blue'));
        $this->assertSame('#2563eb', SwatchColor::for('Royal Blue'));
        $this->assertSame('#ffffff', SwatchColor::for('Off White'));

        $first = SwatchColor::for('Cinnamon');
        $this->assertNotNull($first);
        $this->assertSame($first, SwatchColor::for('Cinnamon'));
        $this->assertNull(SwatchColor::for(null));
    }

    public function test_a_goods_store_ships_its_facets_and_its_variant_values(): void
    {
        [$store, $hoodie] = $this->goodsStore();

        $cfg = (new CommerceRuntime)->config($this->vars($store, [$hoodie]));

        $this->assertSame(['S', 'M'], $cfg['facets']['sizes'], 'the sizes on offer');
        $this->assertSame(
            [['value' => 'Black', 'hex' => '#111111'], ['value' => 'Red', 'hex' => '#d33333']],
            $cfg['facets']['colors'],
            'the colours on offer, with their swatch'
        );
        $this->assertEqualsWithDelta(20.0, (float) $cfg['facets']['priceMin'], 0.001);
        $this->assertEqualsWithDelta(45.0, (float) $cfg['facets']['priceMax'], 0.001);

        $variants = $cfg['items'][(int) $hoodie->id]['variants'];
        $this->assertSame('S', $variants[0]['size']);
        $this->assertSame('Black', $variants[0]['color']);
        $this->assertSame('M', $variants[1]['size']);
        $this->assertSame('Red', $variants[1]['color']);
    }

    public function test_a_restaurant_has_no_facets_to_filter_by(): void
    {
        $store = $this->makeStore(null);
        $item = $this->menu(1, 'Burger', 12.0);
        $item->setRelation('variants', collect([$this->variant(1, 12.0, ['Size' => 'Regular'])]));

        $cfg = (new CommerceRuntime)->config($this->vars($store, [$item]));

        $this->assertSame([], $cfg['facets'], 'a restaurant has nothing to filter by');
        $this->assertArrayNotHasKey('size', $cfg['items'][1]['variants'][0],
            'a restaurant keeps its variant rows exactly as they were');
    }

    public function test_the_library_grid_offers_the_filters_button_to_a_goods_store(): void
    {
        [$store] = $this->goodsStore();
        $html = $this->renderGrid($store);

        $this->assertStringContainsString('data-mb-filters-open', $html,
            'a goods storefront gets the Filters button');
        $this->assertStringContainsString('data-mb-filters-badge', $html);
    }

    public function test_a_food_store_gets_no_filters_button(): void
    {
        $store = $this->makeStore(null);
        $html = $this->renderGrid($store);

        $this->assertStringNotContainsString('data-mb-filters-open', $html,
            'a restaurant has nothing to filter by, so no button');
    }

    public function test_a_named_colour_and_size_become_the_variant_label(): void
    {
        $item = new Menu(['name' => 'Shirt', 'price' => 42]);
        $variant = new MenuVariant([
            'price' => 42,
            'options' => ['Color' => 'White', 'Size' => 'S'],
        ]);
        $item->setRelation('variants', collect([$variant]));
        $item->setRelation('variantOptions', collect());

        $this->assertSame('White · S', (new ItemDrop($item))->variants()[0]->name());
    }

    public function test_product_cards_open_sheet_when_item_has_variants(): void
    {
        [$store, $hoodie] = $this->goodsStore();
        $plain = $this->menu(2, 'Canvas Tote', 15.0);
        $html = $this->renderGrid($store, [$hoodie, $plain]);

        $this->assertStringContainsString('data-mb-open="1"', $html);
        $this->assertStringContainsString('data-mb-add="2"', $html);
    }

    /**
     * @return array{0: Store, 1: Menu}
     */
    private function goodsStore(): array
    {
        $store = $this->makeStore('clothing');

        $hoodie = $this->menu(1, 'Merino Hoodie', 20.0);
        $hoodie->setRelation('variants', collect([
            $this->variant(11, 20.0, ['Size' => 'S', 'Color' => 'Black']),
            $this->variant(12, 45.0, ['Size' => 'M', 'Color' => 'Red']),
        ]));

        return [$store, $hoodie];
    }

    private function makeStore(?string $storeType): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'facet-'.Str::lower(Str::random(8)), 'color' => '#7c5cff',
            'title' => 'Facet Store', 'sub_title' => 'Shop', 'description' => 'Clothes',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ], $storeType === null ? [] : ['store_type' => $storeType]));
        StoreSettings::set($store->id, 'allow_order', 1);
        StoreSettings::set($store->id, 'currency_code', 'USD');
        StoreSettings::set($store->id, 'default_language', 'en');

        return $store;
    }

    private function menu(int $id, string $name, float $price): Menu
    {
        $m = new Menu(['price' => $price]);
        $m->id = $id;
        $m->name = $name;
        $m->image = 'x.png';
        $m->is_sold_out = false;
        $m->setRelation('variants', collect());
        $m->setRelation('variantOptions', collect());
        $m->setRelation('extras', collect());

        return $m;
    }

    private function variant(int $id, float $price, array $options): MenuVariant
    {
        $v = new MenuVariant(['price' => $price, 'options' => $options]);
        $v->id = $id;

        return $v;
    }

    /** @param  array<int,Menu>  $items */
    private function vars(Store $store, array $items): array
    {
        $demo = DemoStorefront::vars();

        return array_merge($demo, [
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => $items,
            'menuBanners' => collect(), 'branches' => collect(), 'flags' => ['allow_order' => true],
            'extras' => ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => []],
        ]);
    }

    /** @param  array<int,Menu>|null  $items */
    private function renderGrid(Store $store, ?array $items = null): string
    {
        $library = SectionLibrary::forVersion('1');
        $source = (string) $library->sectionSource('product-grid');
        $schema = SectionedTemplate::extractSchema($source);

        $vars = $this->vars($store, $items ?? [$this->menu(1, 'Merino Hoodie', 20.0)]);
        $vars['settings'] = [];
        $ctx = app(ThemeContext::class);
        $globals = $ctx->build($vars);
        $globals['section'] = new SectionDrop('t-grid', 'product-grid',
            array_merge(SectionedTemplate::schemaDefaults($schema), []), []);

        $registers = $ctx->registers($vars) + ['theme_locale_map' => $library->locales('en')];
        $registers += app(CommerceRuntime::class)->registers($vars);

        return app(LiquidRenderer::class)->render($source, $globals, 'upload', $registers, $library->snippets());
    }
}
