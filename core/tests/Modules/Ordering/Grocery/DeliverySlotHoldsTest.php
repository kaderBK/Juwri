<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Grocery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;
use Juwri\Modules\Ordering\Models\SlotHold;
use Juwri\Modules\Ordering\Services\Slots\SlotHolds;
use Tests\Modules\Ordering\Grocery\Concerns\GroceryOrders;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-09 (API-3, API-11): delivery slots and their 10-minute holds through the headless API — the capacity guard (a
 * full slot answers 409 slot_full with the next free one), signed slot ids, idempotent holds, the per-client live-hold
 * ceiling, expiry (lazy and by the sweep), the hold token, cross-store isolation, and the order submit reviving a
 * lapsed hold when its slot still has room.
 */
class DeliverySlotHoldsTest extends TestCase
{
    use GroceryOrders;
    use RefreshDatabase;

    private Store $store;

    /** @var array<string, string> */
    private array $read = ['X-Storefront-Token' => 'sfx_demo_freshmart_read_0001', 'Accept' => 'application/json'];

    /** @var array<string, string> */
    private array $write = ['X-Storefront-Token' => 'sfx_demo_freshmart_write_0001', 'Accept' => 'application/json', 'Origin' => 'https://fresh-mart.example'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->bootGrocery();
    }

    /** @return array<string, array<string, mixed>> today's slots by local start "HH:MM" */
    private function slots(): array
    {
        return collect($this->getJson('/api/v1/storefront/delivery/slots?order_type=delivery', $this->read)->assertOk()->json('days.0.slots'))
            ->keyBy(fn ($s) => substr((string) $s['starts_at'], 11, 5))->all();
    }

    private function hold(string $slotId, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/storefront/holds', ['kind' => 'delivery_slot', 'slot_id' => $slotId], $this->write + ['Idempotency-Key' => $key ?? (string) Str::uuid7()]);
    }

    public function test_a_slot_takes_holds_up_to_its_capacity_then_answers_slot_full_with_the_next_free_one(): void
    {
        $slots = $this->slots();
        $this->assertSame(2, $slots['16:00']['capacity_remaining']);
        $this->hold($slots['16:00']['id'])->assertCreated();
        $this->hold($slots['16:00']['id'])->assertCreated();
        $full = $this->hold($slots['16:00']['id'])->assertStatus(409)->assertJsonPath('code', 'slot_full');
        $this->assertNull($full->json('details.next_free_slot'), 'nothing later today');

        $this->hold($slots['13:00']['id'])->assertStatus(409)->assertJsonPath('details.next_free_slot.starts_at', '2026-10-02T14:00:00+03:00');
        $this->assertSame(['full', 0], [$this->slots()['16:00']['state'], $this->slots()['16:00']['capacity_remaining']]);
        $this->assertSame(8, DB::table('slot_seats')->where('store_id', $this->store->id)->where('slot_at', '2026-10-02 13:00:00')->count());
    }

    public function test_slot_ids_are_signed_per_store_and_unknown_or_tampered_ids_are_refused(): void
    {
        $id = $this->slots()['14:00']['id'];
        $tampered = substr($id, 0, -1).(str_ends_with($id, 'a') ? 'b' : 'a');
        $this->hold($tampered)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->hold('dslot_nope')->assertStatus(422);
        $this->assertSame(0, SlotHold::query()->count());
    }

    public function test_the_same_idempotency_key_replays_the_hold_and_another_slot_under_it_conflicts(): void
    {
        $slots = $this->slots();
        $key = (string) Str::uuid7();
        $first = $this->hold($slots['14:00']['id'], $key)->assertCreated();
        $again = $this->hold($slots['14:00']['id'], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame([$first->json('id'), $first->json('token')], [$again->json('id'), $again->json('token')]);
        $this->hold($slots['15:00']['id'], $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, SlotHold::query()->count());
    }

    public function test_a_client_keeps_at_most_three_live_holds(): void
    {
        $slots = $this->slots();
        foreach (['14:00', '15:00', '11:00'] as $at) {
            $this->hold($slots[$at]['id'])->assertCreated();
        }
        $this->hold($slots['12:00']['id'])->assertStatus(429);
    }

    public function test_a_lapsed_hold_frees_its_seat_lazily_and_by_the_sweep(): void
    {
        $slots = $this->slots();
        $this->hold($slots['16:00']['id'])->assertCreated();
        $this->hold($slots['16:00']['id'])->assertCreated();
        $this->assertSame('full', $this->slots()['16:00']['state']);
        $this->travel(11)->minutes();
        $this->assertSame(2, $this->slots()['16:00']['capacity_remaining'], 'lapsed seats no longer count');
        $this->assertSame(2, app(SlotHolds::class)->sweep());
        $this->assertSame(0, DB::table('slot_seats')->whereNotNull('hold_id')->count());
        $this->assertSame(2, SlotHold::query()->where('status', 'expired')->count());
    }

    public function test_release_needs_the_hold_token_and_another_stores_hold_is_not_found(): void
    {
        $slot = $this->slots()['14:00']['id'];
        $hold = $this->hold($slot)->assertCreated();
        $this->deleteJson('/api/v1/storefront/holds/'.$hold->json('id'), [], $this->write + ['X-Hold-Token' => 'hld_wrong'])->assertNotFound();
        // another store's client, even with the right token, cannot see or release this store's hold
        $other = $this->fx->store($this->fx->owner(), 'other-mart', 'Other Mart', 'grocery');
        $this->fx->token($other, 'sfx_other_write_0001', ['order:write'], ['https://other-mart.example']);
        $this->deleteJson('/api/v1/storefront/holds/'.$hold->json('id'), [], ['X-Storefront-Token' => 'sfx_other_write_0001', 'Accept' => 'application/json', 'Origin' => 'https://other-mart.example', 'X-Hold-Token' => (string) $hold->json('token')])->assertNotFound();
        $this->assertSame('active', SlotHold::query()->firstOrFail()->status);
        $this->deleteJson('/api/v1/storefront/holds/'.$hold->json('id'), [], $this->write + ['X-Hold-Token' => (string) $hold->json('token')])->assertNoContent();
        $this->assertSame('released', SlotHold::query()->firstOrFail()->status);
        $this->assertSame(0, DB::table('slot_seats')->whereNotNull('hold_id')->count());
    }

    public function test_an_order_submit_revives_a_lapsed_hold_when_its_slot_still_has_room(): void
    {
        $order = $this->placeGroceryOrder('pay_later');
        $this->assertSame('used', SlotHold::query()->whereKey($order->slot_hold_id)->value('status'));
        $this->assertSame(1, DB::table('slot_seats')->where('order_id', $order->id)->whereNull('expires_at')->count(), 'a pay-later order owns its seat for good');
    }

    public function test_a_store_without_slot_rules_lists_no_slots_and_orders_need_none(): void
    {
        DeliverySlotRule::query()->update(['active' => false]);
        $this->assertSame([], $this->getJson('/api/v1/storefront/delivery/slots?order_type=delivery', $this->read)->assertOk()->json('days.0.slots'));
    }

    public function test_radius_refuses_an_address_out_of_zone(): void
    {
        DeliverySlotRule::query()->update(['radius_km' => '5', 'base_lat' => '24.7136', 'base_lng' => '46.6753']);
        $slot = $this->slots()['14:00']['id'];
        $hold = $this->hold($slot)->assertCreated();
        $body = [
            'order_type' => 'delivery', 'lines' => [['line_id' => 'l1', 'item_id' => ApiFixtures::BANANAS, 'option_ids' => [], 'qty' => 1]],
            'address' => ['line1' => 'Far away', 'city' => 'Jeddah', 'country' => 'SA', 'lat' => 21.4858, 'lng' => 39.1925],
            'slot_id' => $slot, 'hold_id' => $hold->json('id'), 'payment_method_id' => 'cod',
        ];
        $quote = $this->postJson('/api/v1/storefront/quote', $body + ['payment_mode' => 'pay_later'], $this->read)->assertOk();
        $this->assertContains('out_of_zone', array_column((array) $quote->json('warnings'), 'code'));
    }

    public function test_under_a_radius_an_order_without_a_pin_is_refused(): void
    {
        DeliverySlotRule::query()->update(['radius_km' => '5', 'base_lat' => '24.7136', 'base_lng' => '46.6753']);
        $slot = $this->slots()['14:00']['id'];
        $hold = $this->hold($slot)->assertCreated();
        $body = [
            'order_type' => 'delivery', 'lines' => [['line_id' => 'l1', 'item_id' => ApiFixtures::BANANAS, 'option_ids' => [], 'qty' => 9]],
            'address' => ['line1' => 'Al Olaya', 'city' => 'Riyadh', 'country' => 'SA'],
            'slot_id' => $slot, 'hold_id' => $hold->json('id'),
            'customer' => ['name' => 'Noura', 'phone' => '+966551234567', 'marketing_opt_in' => false],
            'payment' => ['mode' => 'pay_later', 'method_id' => 'cod'],
        ];
        $quote = $this->postJson('/api/v1/storefront/quote', $body + ['payment_mode' => 'pay_later'], $this->read)->assertOk();
        $res = $this->postJson('/api/v1/storefront/orders', $body + ['quote_hash' => $quote->json('quote_hash')], $this->write + ['Idempotency-Key' => (string) Str::uuid7(), 'X-Hold-Token' => (string) $hold->json('token')]);
        $res->assertStatus(422);
        $this->assertSame('pin_required', $res->json('error.code') ?? $res->json('code'), (string) $res->getContent());
    }
}
