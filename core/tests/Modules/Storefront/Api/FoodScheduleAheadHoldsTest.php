<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Models\SlotHold;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Ordering\Services\Slots\SlotHolds;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * Lane C (P3-06b, kit/api README §7 food, API-11): food schedule-ahead slots and their 10-minute holds. The cafe's
 * pre-order times (SchedulingService) are listed as `pslot_` slots, their capacity counts scheduled orders AND held
 * seats on every path (headless holds, the web / POS order writers), an order placed with a hold is scheduled at the
 * slot without counting it twice, ASAP stays possible, and the kit's `pickup` is the cafe's takeaway while a store with
 * `in_store` keeps that mapping.
 */
class FoodScheduleAheadHoldsTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    private Store $store;

    /** @var array<string, string> */
    private array $read = ['X-Storefront-Token' => 'sfx_demo_bloom_read_0001', 'Accept' => 'application/json'];

    /** @var array<string, string> */
    private array $write = ['X-Storefront-Token' => 'sfx_demo_bloom_write_0001', 'Accept' => 'application/json', 'Origin' => 'https://bloom-coffee.example'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 06:45:00', 'UTC')); // 09:45 in Riyadh
        $this->fx = new ApiFixtures;
        $this->store = $this->fx->bloom();
        $this->fx->bloomPreorders($this->store);
    }

    /** @return array<string, array<string, mixed>> today's pickup slots by local start "HH:MM" */
    private function slots(string $orderType = 'pickup'): array
    {
        return collect($this->getJson('/api/v1/storefront/delivery/slots?order_type='.$orderType.'&days=1', $this->read)->assertOk()->json('days.0.slots'))
            ->keyBy(fn ($s) => substr((string) $s['starts_at'], 11, 5))->all();
    }

    private function hold(string $slotId): TestResponse
    {
        return $this->postJson('/api/v1/storefront/holds', ['kind' => 'delivery_slot', 'slot_id' => $slotId], $this->write + ['Idempotency-Key' => (string) Str::uuid7()]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [['line_id' => 'ln_1', 'item_id' => '019ee02e-6f00-7a68-86ae-170d6823078c', 'qty' => 1, 'option_ids' => []]];
    }

    /** @param  array<string, mixed>  $extra */
    private function quote(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/storefront/quote', ['order_type' => 'pickup', 'lines' => $this->lines()] + $extra, $this->read)->assertOk();
    }

    public function test_the_kit_pickup_is_the_cafes_takeaway_and_in_store_keeps_its_mapping(): void
    {
        $this->assertSame('takeaway', OrderTypeKeys::toCoreFor('pickup', ['on-table', 'takeaway']));
        $this->assertSame('in_store', OrderTypeKeys::toCoreFor('pickup', ['in_store', 'takeaway']));
        $this->assertSame('in_store', OrderTypeKeys::toCore('pickup'), 'the plain map is unchanged');
        $this->assertNull(OrderTypeKeys::toCoreFor('pickup', ['on-table', 'delivery']));
        $this->assertNull(OrderTypeKeys::toCoreFor('delivery', ['takeaway']));
        $this->assertSame('takeaway', OrderTypeKeys::toCoreFor('takeaway', ['takeaway']));

        // a cafe quote for `pickup` is a takeaway quote: same total as `takeaway`
        $this->assertSame(
            $this->postJson('/api/v1/storefront/quote', ['order_type' => 'takeaway', 'lines' => $this->lines()], $this->read)->json('quote.total.minor'),
            $this->quote()->json('quote.total.minor'),
        );
        $this->assertNotContains('slot', $this->quote()->json('requires'), 'a food order may still be as soon as possible');
    }

    public function test_slots_count_scheduled_orders_and_holds_and_a_full_slot_answers_slot_full_with_the_next_free_one(): void
    {
        $slots = $this->slots();
        $this->assertStringStartsWith('pslot_', $slots['10:15']['id']);
        $this->assertSame([0, 2, 6, 8], [$slots['10:00']['capacity_remaining'], $slots['10:15']['capacity_remaining'], $slots['10:30']['capacity_remaining'], $slots['10:45']['capacity_remaining']]);

        $this->hold($slots['10:15']['id'])->assertCreated();
        $this->hold($slots['10:15']['id'])->assertCreated();
        $full = $this->hold($slots['10:15']['id'])->assertStatus(409)->assertJsonPath('code', 'slot_full');
        $this->assertSame('2026-10-02T10:30:00+03:00', $full->json('details.next_free_slot.starts_at'));
        $this->assertSame(['full', 0], [$this->slots()['10:15']['state'], $this->slots()['10:15']['capacity_remaining']]);
        $this->hold($slots['10:00']['id'])->assertStatus(409)->assertJsonPath('code', 'slot_full');

        // the web / POS order writers see the held seats too
        $scheduling = app(SchedulingService::class);
        $this->assertTrue($scheduling->slotIsFull((int) $this->store->id, null, Carbon::parse('2026-10-02 10:15', 'Asia/Riyadh')));
        try {
            $scheduling->validate((int) $this->store->id, null, 'takeaway', '2026-10-02 10:15');
            $this->fail('a slot filled by holds is full for a scheduled web order');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('full', $e->getMessage());
        }

        // an expired hold gives its seat back
        $this->travel(11)->minutes();
        app(SlotHolds::class)->sweep();
        $this->assertSame(2, $this->slots()['10:15']['capacity_remaining'], 'the lapsed holds gave their seats back');
    }

    public function test_an_order_with_a_hold_is_scheduled_at_the_slot_and_counted_once(): void
    {
        $slot = $this->slots()['10:30'];
        $hold = $this->hold($slot['id'])->assertCreated();
        $this->assertSame(5, $this->slots()['10:30']['capacity_remaining']);

        $quote = $this->quote(['slot_id' => $slot['id'], 'hold_id' => $hold->json('id')]);
        $placed = $this->postJson('/api/v1/storefront/orders', [
            'order_type' => 'pickup', 'lines' => $this->lines(), 'slot_id' => $slot['id'], 'hold_id' => $hold->json('id'),
            'quote_hash' => $quote->json('quote_hash'), 'customer' => ['name' => 'Noura', 'phone' => '+966551234567'],
            'payment' => ['mode' => 'pay_later', 'method_id' => 'counter'],
        ], $this->write + ['Idempotency-Key' => (string) Str::uuid7(), 'X-Hold-Token' => (string) $hold->json('token')]);
        $placed->assertCreated();

        $order = Order::query()->where('track_token', $placed->json('order.track_token'))->firstOrFail();
        $this->assertSame('takeaway', $order->type);
        $this->assertSame('2026-10-02 10:30:00', $order->scheduled_at?->format('Y-m-d H:i:s'), 'scheduled at the slot (store time)');
        $this->assertNotNull($order->fire_at);
        $this->assertSame('used', SlotHold::query()->where('public_id', $hold->json('id'))->value('status'));
        $this->assertSame(1, DB::table('slot_seats')->where('order_id', $order->id)->count());
        $this->assertSame(5, $this->slots()['10:30']['capacity_remaining'], 'the hold became the order: still one place taken');

        // cancelling the order gives the place back
        $order->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame(6, $this->slots()['10:30']['capacity_remaining']);
    }

    public function test_slot_ids_are_signed_per_store_and_a_rule_store_never_takes_a_pslot(): void
    {
        $id = $this->slots()['10:45']['id'];
        $tampered = substr($id, 0, -1).(str_ends_with($id, 'a') ? 'b' : 'a');
        $this->hold($tampered)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        // another store's (same shape) slot id is unknown here
        $other = $this->fx->saffron();
        StoreSettings::setMany((int) $other->id, ['timezone' => 'Asia/Riyadh', 'allow_order_scheduling' => true, 'scheduling_slot_capacity' => 2]);
        $this->assertNull(app(SlotHolds::class)->findSlot($other, null, $id));
        $this->assertSame(0, SlotHold::query()->count());

        // pre-orders switched off: no slots, holds refused
        StoreSettings::set((int) $this->store->id, 'allow_order_scheduling', false);
        $this->assertSame([], $this->slots());
        $this->hold($id)->assertStatus(422);
    }
}
