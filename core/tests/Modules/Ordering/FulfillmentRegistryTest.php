<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical F0-3 — fulfillment modes + orders.type enum→string (G6).
 *
 * Confirms legacy food modes survive unchanged and new modes are usable, and
 * that a non-food order type persists (the enum no longer rejects it).
 */
class FulfillmentRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function reg(): FulfillmentRegistry
    {
        return app(FulfillmentRegistry::class);
    }

    public function test_legacy_food_modes_are_still_valid(): void
    {
        foreach (['on-table', 'takeaway', 'delivery', 'room_service'] as $mode) {
            $this->assertTrue($this->reg()->isValid($mode), "$mode must stay valid");
        }
    }

    public function test_new_multivertical_modes_are_valid(): void
    {
        foreach (['ship', 'pickup_slot', 'appointment', 'in_store'] as $mode) {
            $this->assertTrue($this->reg()->isValid($mode), "$mode should be valid");
        }
    }

    public function test_modes_for_store_type_are_scoped_to_the_vertical(): void
    {
        $this->assertSame(
            ['on-table', 'takeaway', 'delivery', 'room_service'],
            $this->reg()->modesFor('restaurant')
        );
        $this->assertSame(['ship', 'pickup_slot', 'in_store'], $this->reg()->modesFor('retail'));
        $this->assertSame(['pickup_slot', 'delivery', 'appointment'], $this->reg()->modesFor('laundry'));
    }

    public function test_address_and_slot_requirements(): void
    {
        $r = $this->reg();
        $this->assertTrue($r->needsAddress('delivery'));
        $this->assertTrue($r->needsAddress('ship'));
        $this->assertFalse($r->needsAddress('pickup_slot'));
        $this->assertTrue($r->needsSlot('pickup_slot'));
        $this->assertTrue($r->needsSlot('appointment'));
        $this->assertFalse($r->needsSlot('ship'));
    }

    public function test_order_persists_a_non_food_type(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);

        $order = Order::factory()->create([
            'store_id' => $store->id,
            'type' => 'ship',
        ]);

        $this->assertSame('ship', $order->fresh()->type);
    }
}
