<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use Juwri\Core\Models\Store;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical F0-1 — the vertical discriminator + registry.
 *
 * Guards the seam every lane builds on: legacy stores stay 'restaurant' with the
 * food attribute set, and each vertical exposes a stable capability shape.
 */
class VerticalRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function reg(): VerticalRegistry
    {
        return app(VerticalRegistry::class);
    }

    public function test_new_store_defaults_to_restaurant(): void
    {
        $store = Store::factory()->create();

        $this->assertSame('restaurant', $store->fresh()->store_type);
        $this->assertTrue($store->isFoodVertical());
        $this->assertSame('food', $store->verticalConfig()['group']);
    }

    public function test_registry_knows_the_six_new_verticals(): void
    {
        foreach (['retail', 'cosmetic', 'clothing', 'carparts', 'laundry', 'pharmacy'] as $type) {
            $this->assertTrue($this->reg()->exists($type), "$type should be registered");
        }
    }

    public function test_food_attrs_are_restaurant_only(): void
    {
        $this->assertTrue($this->reg()->isFood('restaurant'));

        foreach (['retail', 'cosmetic', 'clothing', 'carparts', 'laundry', 'pharmacy'] as $type) {
            $this->assertFalse($this->reg()->isFood($type), "$type must not expose food attrs");
        }
    }

    public function test_capabilities_map_per_vertical(): void
    {
        $r = $this->reg();

        // Goods verticals carry a variant matrix.
        $this->assertTrue($r->has('clothing', 'variant_goods'));
        // Only car parts declare fitment.
        $this->assertTrue($r->has('carparts', 'fitment'));
        $this->assertFalse($r->has('cosmetic', 'fitment'));
        // Pharmacy (OTC) age-gates; expiry-tracked.
        $this->assertTrue($r->has('pharmacy', 'age_gate'));
        $this->assertTrue($r->has('pharmacy', 'expiry'));
        // Laundry is a service vertical.
        $this->assertTrue($r->has('laundry', 'service'));
    }

    public function test_restaurant_fulfillment_keeps_legacy_literals(): void
    {
        $this->assertSame(
            ['on-table', 'takeaway', 'delivery', 'room_service'],
            $this->reg()->fulfillmentModes('restaurant')
        );
    }

    public function test_unknown_type_falls_back_to_default(): void
    {
        $this->assertTrue($this->reg()->isFood('nonsense-vertical'));
        $this->assertSame(
            $this->reg()->config('restaurant'),
            $this->reg()->config('nonsense-vertical')
        );
    }

    public function test_scope_of_vertical_filters(): void
    {
        Store::factory()->create(); // restaurant (default)
        Store::factory()->create(['store_type' => 'retail']);
        Store::factory()->create(['store_type' => 'retail']);

        $this->assertSame(2, Store::query()->ofVertical('retail')->count());
        $this->assertSame(1, Store::query()->ofVertical('restaurant')->count());
    }
}
