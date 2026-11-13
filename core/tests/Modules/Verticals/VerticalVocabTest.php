<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use Juwri\Core\Models\Store;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical F0-2 — per-vertical vocabulary.
 *
 * Asserts on raw() (the pre-translation word) so the test is independent of
 * locale data and never writes to lang files. Restaurant keeps today's wording.
 */
class VerticalVocabTest extends TestCase
{
    use RefreshDatabase;

    private function vocab(): VerticalVocab
    {
        return app(VerticalVocab::class);
    }

    public function test_food_keeps_legacy_wording(): void
    {
        $v = $this->vocab();
        $this->assertSame('Dish', $v->raw('restaurant', 'item'));
        $this->assertSame('Menu', $v->raw('restaurant', 'catalog'));
        $this->assertSame('Add to order', $v->raw('restaurant', 'add_to_cart'));
    }

    public function test_goods_relabels_to_product_catalog(): void
    {
        $v = $this->vocab();
        $this->assertSame('Product', $v->raw('retail', 'item'));
        $this->assertSame('Catalog', $v->raw('cosmetic', 'catalog'));
        $this->assertSame('Add to cart', $v->raw('clothing', 'add_to_cart'));
    }

    public function test_services_relabels_to_booking(): void
    {
        $v = $this->vocab();
        $this->assertSame('Service', $v->raw('laundry', 'item'));
        $this->assertSame('Book', $v->raw('laundry', 'add_to_cart'));
        $this->assertSame('Bookings', $v->raw('laundry', 'orders'));
    }

    public function test_unknown_type_falls_back_to_food(): void
    {
        $this->assertSame('Dish', $this->vocab()->raw('nonsense', 'item'));
    }

    public function test_unknown_key_returns_the_key(): void
    {
        $this->assertSame('wibble', $this->vocab()->raw('retail', 'wibble'));
    }

    public function test_for_store_and_map_resolve(): void
    {
        $store = Store::factory()->create(['store_type' => 'retail']);

        $this->assertNotSame('', $this->vocab()->forStore($store, 'item'));

        $map = $this->vocab()->map('retail');
        $this->assertArrayHasKey('catalog', $map);
        $this->assertArrayHasKey('add_to_cart', $map);
    }
}
