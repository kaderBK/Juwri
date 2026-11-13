<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * BIOLINK-TPL T1-5 (A4): menu_item / featured_dish blocks resolve config.item_id to
 * the live item (name, store-formatted price, image, storefront deep link) at render.
 * Owner-typed fields win; a deleted/foreign item leaves the fallback text and never
 * emits a broken ?item= link.
 */
class BioLinkItemResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function itemOn(Store $store, array $attrs = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create(array_merge([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Truffle Burger', 'price' => 12.00, 'active' => 1,
        ], $attrs));
    }

    public function test_menu_item_resolves_name_price_and_deep_link(): void
    {
        $store = Store::factory()->create();
        $item = $this->itemOn($store);
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu_item', 'label' => null, 'url' => null,
            'config' => ['item_id' => (string) $item->id],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        $this->assertSame('Truffle Burger', $row['label']);            // filled from the item
        $this->assertStringContainsString('12', $row['config']['price_label']);
        $this->assertStringContainsString('$', $row['config']['price_label']);
        $this->assertStringContainsString('?item='.$item->id, (string) $row['url']);
        $this->assertStringContainsString($store->slug, (string) $row['url']);
    }

    public function test_featured_dish_resolves_title_price_image_and_link(): void
    {
        $store = Store::factory()->create();
        $item = $this->itemOn($store, ['image' => 'burger.jpg']);
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'featured_dish', 'url' => null,
            'config' => ['item_id' => (string) $item->id],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        $this->assertSame('Truffle Burger', $row['config']['title']);
        $this->assertStringContainsString('12', $row['config']['price_label']);
        $this->assertStringContainsString('burger.jpg', $row['config']['image']);
        $this->assertStringContainsString('?item='.$item->id, (string) $row['url']);
    }

    public function test_owner_typed_fields_win_over_the_item(): void
    {
        $store = Store::factory()->create();
        $item = $this->itemOn($store);
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu_item', 'label' => 'Our signature',
            'url' => 'https://owner.example/special',
            'config' => ['item_id' => (string) $item->id, 'price_label' => 'from $9'],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        $this->assertSame('Our signature', $row['label']);                  // not overwritten
        $this->assertSame('from $9', $row['config']['price_label']);        // not overwritten
        $this->assertSame('https://owner.example/special', $row['url']);    // owner link kept
    }

    public function test_deleted_item_leaves_fallback_and_no_broken_link(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'featured_dish', 'label' => 'Today',
            'config' => ['item_id' => '999999', 'title' => 'Manual title'],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        // Fallback text preserved; no ?item= deep link fabricated for a dead item.
        $this->assertSame('Manual title', $row['config']['title']);
        $this->assertStringNotContainsString('?item=', (string) ($row['url'] ?? ''));
    }

    public function test_foreign_store_item_is_not_resolved(): void
    {
        $store = Store::factory()->create();
        $otherPost = Store::factory()->create();
        $foreignItem = $this->itemOn($otherPost);

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu_item', 'label' => null,
            'config' => ['item_id' => (string) $foreignItem->id],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        // Cross-tenant item id resolves to nothing (never leaks another store's item).
        $this->assertNull($row['label']);
        $this->assertStringNotContainsString('?item=', (string) ($row['url'] ?? ''));
    }

    public function test_manual_mode_without_item_id_is_untouched(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu_item', 'label' => 'Hand-typed',
            'url' => 'https://x.example', 'config' => ['price_label' => '$5'],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        $this->assertSame('Hand-typed', $row['label']);
        $this->assertSame('$5', $row['config']['price_label']);
        $this->assertSame('https://x.example', $row['url']);
    }
}
