<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuCategory;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\TestCase;

/**
 * P2-2: the Drop contract is the shared-hosting security boundary. These tests
 * prove a theme sees ONLY the curated presentation fields — never an Eloquent
 * model's other attributes, relations, ids, or credentials — and that the
 * 24-var contract maps and renders through the sandboxed Liquid engine.
 */
class ThemeContextDropTest extends TestCase
{
    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);
    }

    /** Build the full set of Liquid globals from in-memory (unsaved) models. */
    private function context(): array
    {
        $store = new Store([
            'title' => 'Pizza Place', 'slug' => 'pizza', 'sub_title' => 'Best in town',
            'description' => 'desc', 'address' => '1 Main St', 'phone' => '555-1234', 'color' => '#e11',
        ]);
        $store->id = 7;
        $store->main_image = 'logo.png';
        $store->secret_column = 'DO-NOT-LEAK'; // an arbitrary model attribute

        $category = new MenuCategory(['position' => 1]);
        $category->id = 1;
        $category->name = 'Mains';

        $item = new Menu(['price' => 12]);
        $item->id = 1;
        $item->name = 'Burger';
        $item->description = 'Juicy';
        $item->image = 'burger.png';
        $item->cost_price = 4; // an internal attribute that must NOT leak

        // Variant DIMENSION (Size) + a combination row pointing at choice 1 ("Large").
        $dimension = new MenuVariantOption(['title' => 'Size', 'options' => ['Small', 'Large']]);
        $dimension->id = 40;
        $variant = new MenuVariant(['price' => 3, 'options' => [40 => 1]]);
        $variant->id = 1;
        $extra = new MenuExtra(['title' => 'Cheese', 'price' => 1]);
        $extra->id = 1;
        // ADDITIVE global (library) group with per-choice prices.
        $group = new MenuVariantOption(['title' => 'Sauce', 'options' => ['BBQ', 'Garlic'], 'option_prices' => [0, 1.5]]);
        $group->id = 41;
        $group->required = true;
        $group->min_select = 1;
        $group->max_select = 2;

        $item->setRelation('category', $category);
        $item->setRelation('variantOptions', collect([$dimension]));
        $item->setRelation('variants', collect([$variant]));
        $item->setRelation('extras', collect([$extra]));
        $item->setRelation('globalGroups', collect([$group]));

        $customer = new User(['name' => 'Ada', 'email' => 'ada@example.com']);
        $customer->id = 99;

        $lang = new Language(['code' => 'ar', 'name' => 'Arabic', 'direction' => 'rtl']);

        return (new ThemeContext)->build([
            'store' => $store,
            'postOptions' => (object) ['currency_sign' => '$', 'min_order_value' => 5, 'allow_tips' => 1],
            'total_menus' => [1 => $item],
            'customer' => $customer,
            'flags' => ['allow_order' => true, 'takeaway' => false, 'delivery' => true],
            'scans_today' => 42,
            'menu_languages' => collect([$lang]),
            'default_menu_language' => $lang,
        ]);
    }

    public function test_restaurant_exposes_only_presentation_fields(): void
    {
        $out = $this->renderer()->render(
            '{{ restaurant.title }}|{{ restaurant.phone }}|{{ restaurant.color }}',
            $this->context()
        );
        $this->assertSame('Pizza Place|555-1234|#e11', $out);
    }

    public function test_restaurant_does_not_leak_relations_or_unexposed_attributes(): void
    {
        // `user` is an Eloquent relation (→ credentials); `secret_column` is an
        // arbitrary attribute. Neither has a Drop getter → blank in live render.
        $out = $this->renderer()->render(
            'A{{ restaurant.user }}B{{ restaurant.secret_column }}C{{ restaurant.password }}D',
            $this->context()
        );
        $this->assertSame('ABCD', $out);
    }

    public function test_upload_mode_rejects_an_unexposed_attribute(): void
    {
        // At submit time the strict env turns a leak attempt into a hard error
        // the author must fix — it never silently resolves to the model value.
        $this->expectException(RenderFailedException::class);
        $this->renderer()->render('{{ restaurant.user }}', $this->context(), 'upload');
    }

    public function test_customer_exposes_name_and_phone_only(): void
    {
        // Security-critical: no id, no email, nothing beyond name + phone.
        $out = $this->renderer()->render(
            '{{ customer.name }}|{{ customer.email }}|{{ customer.id }}|{{ customer.phone }}',
            $this->context()
        );
        $this->assertSame('Ada|||', $out);
    }

    public function test_menu_maps_categories_items_variants_extras_and_groups(): void
    {
        $tpl = '{% for c in menu.categories %}{{ c.name }}:'
            .'{% for i in c.items %}{{ i.name }}'
            .'{% for v in i.variants %}[{{ v.name }}:{{ v.price }}]{% endfor %}' // P6-266: "<x>" is markup to the output sanitiser
            .'{% for e in i.extras %}+{{ e.name }}{% endfor %}'
            .'{% for g in i.option_groups %}({{ g.name }}/min{{ g.min_select }}){% endfor %}'
            .'{% endfor %}{% endfor %}';
        $out = $this->renderer()->render($tpl, $this->context());
        $this->assertSame('Mains:Burger[Large:3]+Cheese(Sauce/min1)', $out);
    }

    public function test_item_internal_attributes_do_not_leak(): void
    {
        // `cost_price` (food-cost, owner-only) has no ItemDrop getter.
        $tpl = '{% for c in menu.categories %}{% for i in c.items %}[{{ i.cost_price }}]{% endfor %}{% endfor %}';
        $out = $this->renderer()->render($tpl, $this->context());
        $this->assertSame('[]', $out);
    }

    /** B5: items whose category was deleted still reach the theme (synthetic bucket, last). */
    public function test_uncategorised_items_land_in_a_synthetic_bucket_after_real_categories(): void
    {
        app()->setLocale('en'); // deterministic bucket label (it translates per locale)
        $ctx = $this->context();

        // Add an item with NO category relation to the same build input.
        $orphan = new Menu(['price' => 4]);
        $orphan->id = 77;
        $orphan->name = 'Orphan Fries';
        $orphan->setRelation('category', null);
        foreach (['variantOptions', 'variants', 'extras', 'globalGroups'] as $rel) {
            $orphan->setRelation($rel, collect());
        }

        $store = new Store(['title' => 'X', 'slug' => 'x']);
        $store->id = 1;
        $rebuilt = (new ThemeContext)->build([
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [1 => $this->contextItem(), 77 => $orphan],
        ]);

        $out = $this->renderer()->render(
            '{% for c in menu.categories %}[{{ c.name }}:{% for i in c.items %}{{ i.name }},{% endfor %}]{% endfor %}',
            $rebuilt
        );

        $this->assertStringContainsString('Orphan Fries', $out, 'orphan visible');
        $this->assertMatchesRegularExpression('/\[Mains:.*\]\[Other:Orphan Fries,\]$/', $out, 'bucket named Other and sorted last');
    }

    /** The categorised item used by the bucket test (same shape as context()). */
    private function contextItem(): Menu
    {
        $category = new MenuCategory(['position' => 1]);
        $category->id = 1;
        $category->name = 'Mains';
        $item = new Menu(['price' => 12]);
        $item->id = 1;
        $item->name = 'Burger';
        $item->setRelation('category', $category);
        foreach (['variantOptions', 'variants', 'extras', 'globalGroups'] as $rel) {
            $item->setRelation($rel, collect());
        }

        return $item;
    }

    public function test_flags_options_stats_and_localization(): void
    {
        $tpl = '{% if flags.allow_order %}O{% endif %}{% if flags.takeaway %}T{% endif %}'
            .'|{{ options.currency_sign }}|{{ stats.scans_today }}'
            .'|{{ localization.direction }}|{{ localization.current.code }}';
        $out = $this->renderer()->render($tpl, $this->context());
        $this->assertSame('O|$|42|rtl|ar', $out);
    }

    /**
     * Wave-0 F0a: a food store's `vocab` global keeps today's exact words and
     * `restaurant.store_type` reads 'restaurant', so first-party food Liquid
     * themes are byte-identical.
     */
    public function test_vocab_and_store_type_default_to_food(): void
    {
        app()->setLocale('en');
        $tpl = '{{ restaurant.store_type }}|{{ vocab.group }}|{{ vocab.items }}|{{ vocab.add_to_cart }}|{{ vocab.catalog }}';
        $out = $this->renderer()->render($tpl, $this->context());
        // 'dishes' lower-case is the existing en lang value for the food vocab key.
        $this->assertSame('restaurant|food|dishes|Add to order|Menu', $out);
    }

    /**
     * Wave-0 F0a: a goods store's `vocab` global renders the retail nouns, so a
     * vertical Liquid theme reads "Products" / "Add to cart" / "Catalog" instead
     * of the food words, and `restaurant.store_type` carries the discriminator.
     */
    public function test_vocab_reflects_the_store_vertical(): void
    {
        app()->setLocale('en');
        $store = new Store(['title' => 'Shop', 'slug' => 'shop', 'store_type' => 'retail']);
        $store->id = 3;
        $ctx = (new ThemeContext)->build([
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [],
        ]);
        $tpl = '{{ restaurant.store_type }}|{{ vocab.group }}|{{ vocab.items }}|{{ vocab.add_to_cart }}|{{ vocab.catalog }}';
        $out = $this->renderer()->render($tpl, $ctx);
        $this->assertSame('retail|goods|Products|Add to cart|Catalog', $out);
    }
}
