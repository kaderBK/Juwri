<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\TestCase;

/**
 * Wave-0 vertical-theme work: proves two things about the on-disk Liquid
 * packages, not a hand-written fragment —
 *
 *  1. `bazaar` is a real, complete theme: a manifest + entrypoint +
 *     `{% commerce %}` wiring + the data-mb-* binding contract crave itself
 *     relies on (open/add), same as ThemeContextDropTest proves for the Drop
 *     layer.
 *  2. Both `bazaar` and the updated `starter` are genuinely VERTICAL-AWARE:
 *     the SAME package, rendered for a goods store, reads "Products" /
 *     "Catalog" / "Add to cart"; rendered for a restaurant, it reads the
 *     unchanged food words ("Menu" / "Add to order") — because every noun
 *     comes from the `vocab` global (config/verticals.php → VerticalVocab),
 *     never a hardcoded string in the .liquid source.
 *
 * Modeled on ThemeContextDropTest for the Drop/context plumbing; renders the
 * real packaged files (MarketplaceTheme → LiquidRenderer / SectionedTemplate)
 * instead of an inline template string, so a real binding regression here
 * would actually be caught.
 */
class LiquidVerticalThemeTest extends TestCase
{
    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);
    }

    private function theme(string $slug): MarketplaceTheme
    {
        return new MarketplaceTheme($slug, '1', resource_path('liquid-themes/'.$slug.'/1'));
    }

    /**
     * Build the full set of Liquid globals for one store, isolated from the
     * DB (unsaved models) — same shape as ThemeContextDropTest::context().
     */
    private function context(string $storeType): array
    {
        $store = new Store([
            'title' => 'Test Store', 'slug' => 'test-store', 'store_type' => $storeType,
        ]);
        $store->id = 1;

        $category = new MenuCategory(['position' => 1]);
        $category->id = 1;
        $category->name = 'Everyday';

        $item = new Menu(['price' => 10]);
        $item->id = 1;
        $item->name = 'Sample item';
        $item->setRelation('category', $category);
        foreach (['variantOptions', 'variants', 'extras', 'globalGroups'] as $rel) {
            $item->setRelation($rel, collect());
        }

        return (new ThemeContext)->build([
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [1 => $item],
            'flags' => ['allow_order' => true],
        ]);
    }

    /** Render the real bazaar package (single-file template) for one store type. */
    private function renderBazaar(string $storeType): string
    {
        $theme = $this->theme('bazaar');
        $registers = [
            'store_id' => 1,
            'theme_locale_map' => $theme->locales('en'),
        ];

        return $this->renderer()->render(
            $theme->templateSource(),
            $this->context($storeType),
            'live',
            $registers,
            $theme->snippets(),
        );
    }

    /** Render the real starter package (OS 2.0 sectioned template) for one store type. */
    private function renderStarter(string $storeType): string
    {
        $theme = $this->theme('starter');
        $registers = [
            'store_id' => 1,
            'theme_locale_map' => $theme->locales('en'),
        ];
        $sectioned = new SectionedTemplate($this->renderer());

        return $sectioned->render($theme, $this->context($storeType), $registers, [], 'live');
    }

    /* -------------------------------------------------------- package shape -- */

    public function test_bazaar_package_ships_the_required_files_and_manifest(): void
    {
        $dir = resource_path('liquid-themes/bazaar/1');

        $this->assertFileExists($dir.'/theme.json');
        $this->assertFileExists($dir.'/templates/index.liquid');
        $this->assertFileExists($dir.'/snippets/product-card.liquid');
        $this->assertFileExists($dir.'/config/settings_schema.json');
        $this->assertFileExists($dir.'/locales/en.json');
        $this->assertFileExists($dir.'/locales/ar.json');

        $manifest = json_decode((string) file_get_contents($dir.'/theme.json'), true);
        $this->assertSame('bazaar', $manifest['slug'] ?? null);
        $this->assertNotEmpty($manifest['name'] ?? null);
        $this->assertNotEmpty($manifest['version'] ?? null);
        $this->assertSame('liquid', $manifest['source'] ?? null);

        // Both locale bundles must at least decode and share the same key set,
        // or a locale switch would silently fall back to raw English keys.
        $en = json_decode((string) file_get_contents($dir.'/locales/en.json'), true);
        $ar = json_decode((string) file_get_contents($dir.'/locales/ar.json'), true);
        $this->assertIsArray($en);
        $this->assertIsArray($ar);
        $this->assertSame(array_keys($en), array_keys($ar), 'en/ar locale bundles must carry the same keys');
    }

    public function test_bazaar_template_wires_the_commerce_runtime_and_bindings(): void
    {
        $source = (string) file_get_contents(resource_path('liquid-themes/bazaar/1/templates/index.liquid'));
        $card = (string) file_get_contents(resource_path('liquid-themes/bazaar/1/snippets/product-card.liquid'));

        $this->assertStringContainsString('{% commerce %}', $source, 'emits the commerce runtime once per page');
        $this->assertStringContainsString("{% render 'product-card'", $source, 'catalog grid renders the product card snippet');
        $this->assertStringContainsString('data-mb-open="{{ item.id }}"', $card, 'card carries the product deep-link binding');
        $this->assertStringContainsString('data-mb-add="{{ item.id }}"', $card, 'card carries the add-to-cart binding');

        // No food-only surface — a goods/services theme must not gate on
        // dietary attributes or ship a dine-in "call waiter" control.
        $this->assertStringNotContainsString('is_vegan', $source.$card);
        $this->assertStringNotContainsString('is_halal', $source.$card);
        $this->assertStringNotContainsString('is_gluten_free', $source.$card);
        $this->assertStringNotContainsString('call-waiter', $source.$card);
        $this->assertStringNotContainsString('call_waiter', $source.$card);
    }

    /* -------------------------------------------------------- vocab wiring -- */

    public function test_bazaar_reads_goods_vocab_for_a_retail_store(): void
    {
        app()->setLocale('en');
        $html = $this->renderBazaar('retail');

        $this->assertStringContainsString('Catalog', $html, 'vocab.catalog for a goods store');
        $this->assertStringContainsString('Products', $html, 'vocab.items for a goods store');
        $this->assertStringContainsString('Add to cart', $html, 'vocab.add_to_cart for a goods store');
        $this->assertStringNotContainsString('Add to order', $html, 'the food add-to-cart word must not leak into a goods render');
    }

    public function test_bazaar_reads_food_vocab_for_a_restaurant(): void
    {
        app()->setLocale('en');
        $html = $this->renderBazaar('restaurant');

        $this->assertStringContainsString('Menu', $html, 'vocab.catalog for a food store');
        $this->assertStringContainsString('dishes', $html, 'vocab.items for a food store');
        $this->assertStringContainsString('Add to order', $html, 'vocab.add_to_cart for a food store');
        $this->assertStringNotContainsString('Add to cart', $html, 'the goods add-to-cart word must not leak into a food render');
    }

    public function test_bazaar_carries_the_product_binding_for_the_rendered_item(): void
    {
        $html = $this->renderBazaar('retail');

        $this->assertStringContainsString('data-mb-open="1"', $html, 'card opens the product view');
        $this->assertStringContainsString('data-mb-add="1"', $html, 'card adds the item to cart');
    }

    public function test_starter_reads_goods_vocab_for_a_retail_store(): void
    {
        app()->setLocale('en');
        $html = $this->renderStarter('retail');

        $this->assertStringContainsString('Catalog', $html, 'vocab.catalog for a goods store');
        $this->assertStringContainsString('Products', $html, 'vocab.items for a goods store');
        $this->assertStringContainsString('Add to cart', $html, 'vocab.add_to_cart for a goods store');
        $this->assertStringNotContainsString('Add to order', $html);
    }

    public function test_starter_reads_food_vocab_for_a_restaurant(): void
    {
        app()->setLocale('en');
        $html = $this->renderStarter('restaurant');

        $this->assertStringContainsString('Menu', $html, 'vocab.catalog for a food store');
        $this->assertStringContainsString('Add to order', $html, 'vocab.add_to_cart for a food store');
        $this->assertStringNotContainsString('Add to cart', $html);
    }
}
