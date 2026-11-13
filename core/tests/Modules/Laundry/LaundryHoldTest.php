<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Laundry;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Laundry\Exceptions\LaundryHoldException;
use Juwri\Modules\Laundry\Models\LaundryHold;
use Juwri\Modules\Laundry\Services\Laundry\LaundryHolds;
use Juwri\Modules\Laundry\Services\Laundry\ServiceBookingService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Modules\Laundry\Concerns\LaundryHoldFixtures;
use Tests\TestCase;

/**
 * P3-11 (API-23): one 10-minute hold covers the laundry's pickup slot and its delivery-back slot. The hold, its
 * capacity rules (live holds + placed orders + holds that became orders), the turnaround, release, expiry, and the
 * hold becoming the order's slots at booking.
 */
class LaundryHoldTest extends TestCase
{
    use LaundryHoldFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function holds(): LaundryHolds
    {
        return app(LaundryHolds::class);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (LaundryHoldException $e) {
            return $e->errorCode;
        }

        return 'ok';
    }

    public function test_one_hold_covers_the_pickup_and_the_delivery_back_slot(): void
    {
        $store = $this->laundryStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '16:30'), 'holder-a');

        $hold = $made['hold'];
        $this->assertSame('active', $hold->status);
        $this->assertSame('2026-10-08 10:00:00', $hold->pickup_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-09 16:30:00', $hold->return_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($hold->expires_at->equalTo(now()->addMinutes(10)));
        $this->assertTrue($hold->tokenMatches($made['token']));
        $this->assertSame(hash('sha256', $made['token']), $hold->token_hash, 'only the hash is stored');
        $this->assertSame(1, LaundryHold::query()->count(), 'one row, one token');
    }

    public function test_a_drop_off_order_holds_only_its_pickup_slot(): void
    {
        $store = $this->laundryStore();
        $made = $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, '10:00'), $this->slotId(3, '10:00'));

        $this->assertNull($made['hold']->return_at, 'a drop-off order has no delivery-back time');
    }

    public function test_the_delivery_back_time_is_required_for_a_delivery_order(): void
    {
        $store = $this->laundryStore();

        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), null)));
        $this->assertSame(0, LaundryHold::query()->count());
    }

    public function test_the_last_place_of_a_pickup_slot_goes_to_the_first_hold(): void
    {
        $store = $this->laundryStore(); // capacity 1
        $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), 'a');

        $this->assertSame('slot_taken', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'b')));
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:30'), $this->slotId(2, '12:00'), 'b')));
    }

    public function test_the_last_place_of_a_delivery_back_slot_goes_to_the_first_hold(): void
    {
        $store = $this->laundryStore();
        $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), 'a');

        $this->assertSame('return_slot_taken', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '08:00'), $this->slotId(2, '10:00'), 'b')));
        $this->assertSame(1, LaundryHold::query()->count(), 'the refused hold left nothing behind (its pickup place is free again)');
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '08:00'), $this->slotId(2, '10:30'), 'b')));
    }

    public function test_a_capacity_of_two_lets_two_holds_share_a_slot_and_refuses_the_third(): void
    {
        $store = $this->laundryStore(['scheduling_slot_capacity' => 2]);

        foreach (['a', 'b'] as $holder) {
            $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), $holder)));
        }
        $this->assertSame('slot_taken', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '11:00'), 'c')));
    }

    public function test_unlimited_capacity_never_refuses_a_slot(): void
    {
        $store = $this->laundryStore(['scheduling_slot_capacity' => 0]);

        foreach (['a', 'b', 'c'] as $holder) {
            $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), $holder)));
        }
    }

    public function test_a_placed_order_at_a_slot_takes_its_place_from_the_holds(): void
    {
        $store = $this->laundryStore();
        Order::create(['store_id' => $store->id, 'type' => 'pickup_slot', 'status' => 'new', 'track_token' => 'tok-'.Str::random(8), 'scheduled_at' => '2026-10-08 10:00:00']);

        $this->assertSame('slot_taken', $this->code(fn () => $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, '10:00'), null)));
    }

    public function test_releasing_the_hold_frees_both_slots(): void
    {
        $store = $this->laundryStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), 'a');

        $this->assertTrue($this->holds()->release($made['hold']));
        $this->assertFalse($this->holds()->release($made['hold']), 'releasing twice is a no-op');
        $this->assertSame('released', $made['hold']->fresh()->status);
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), 'b')));
    }

    public function test_an_expired_hold_frees_both_slots_and_is_marked_expired(): void
    {
        $store = $this->laundryStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), 'a');

        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'), 'b')));
        $this->assertSame('expired', $made['hold']->fresh()->status);
    }

    public function test_the_delivery_back_slot_is_at_least_the_turnaround_after_the_pickup(): void
    {
        $store = $this->laundryStore();

        // the default turnaround is 24 hours
        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(1, '20:00'))));
        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '09:30'))));
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'))));

        // the store sets its own turnaround
        StoreSettings::set((int) $store->id, 'laundry_turnaround_hours', 2);
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '14:00'), $this->slotId(1, '16:00'))));
    }

    public function test_the_delivery_back_time_must_be_a_real_slot(): void
    {
        $store = $this->laundryStore(); // 30-minute interval

        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:15'))));
        // beyond the days-ahead horizon (7 days)
        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(9, '10:00'))));
    }

    public function test_a_pickup_too_soon_for_the_lead_time_plus_the_hold_is_refused(): void
    {
        $store = $this->laundryStore(); // lead 30 minutes, hold 10 minutes

        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'pickup_slot', now('UTC')->addMinutes(30)->format('Y-m-d\TH:i'), null)));
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'pickup_slot', $this->slotId(0, '11:00'), null)));
    }

    public function test_a_mode_the_store_does_not_offer_cannot_be_held(): void
    {
        $store = $this->laundryStore();
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '10:00'))));
        $this->assertSame('invalid_request', $this->code(fn () => $this->holds()->hold($store, 'ship', $this->slotId(1, '10:00'), null)));
    }

    public function test_one_holder_has_at_most_three_live_holds(): void
    {
        $store = $this->laundryStore(['scheduling_slot_capacity' => 0]);
        foreach (['10:00', '10:30', '11:00'] as $time) {
            $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, $time), null, 'same');
        }

        $this->assertSame('too_many_holds', $this->code(fn () => $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, '11:30'), null, 'same')));
        $this->assertSame('ok', $this->code(fn () => $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, '11:30'), null, 'other')));
    }

    public function test_a_live_hold_makes_its_pickup_slot_show_full_in_the_scheduling_slots(): void
    {
        $store = $this->laundryStore();
        $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, '10:00'), null);

        $slots = app(SchedulingService::class)->slotsFor((int) $store->id, null, 'pickup_slot', now('UTC')->addDay()->format('Y-m-d'));
        $byTime = collect($slots)->mapWithKeys(fn ($s) => [$s['at']->format('H:i') => $s['full']]);

        $this->assertTrue($byTime['10:00']);
        $this->assertFalse($byTime['10:30']);
    }

    public function test_the_delivery_back_slots_of_a_day_start_after_the_turnaround_and_show_taken_places(): void
    {
        $store = $this->laundryStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'));

        $slots = collect($this->holds()->returnSlots((int) $store->id, now('UTC')->addDays(2)->format('Y-m-d'), $made['hold']->pickup_at));
        $byTime = $slots->mapWithKeys(fn ($s) => [$s['at']->format('H:i') => $s['full']]);

        $this->assertArrayNotHasKey('09:30', $byTime->all(), 'earlier than pickup + 24 h');
        $this->assertSame(false, $byTime['10:00']);
        $this->assertSame(true, $byTime['12:00'], 'taken by the hold');
    }

    // ---- the hold becomes the order's slots --------------------------------------------------------------

    /** @return array{0: Store, 1: string, 2: ServiceBookingService} a store with a cart (3 shirts + 2 kg) */
    private function cartStore(array $options = []): array
    {
        $store = $this->laundryStore($options);
        $shirt = $this->shirtIron($store);
        $wash = $this->washAndFold($store);
        $svc = app(ServiceBookingService::class);
        $token = 'tok-'.Str::random(12);
        $svc->addLine($store, $token, (int) $shirt->id, null, 3);
        $svc->addLine($store, $token, (int) $wash->id, null, 2);

        return [$store, $token, $svc];
    }

    public function test_booking_with_the_hold_places_the_order_at_the_held_pickup_and_keeps_the_delivery_back_slot(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'a');

        $order = $svc->book($store, $token, 'delivery', [
            'customer_name' => 'Sam', 'phone_number' => '555', 'address' => '221B Baker Street',
            'hold' => $made['hold']->public_id, 'hold_token' => $made['token'],
        ]);

        $this->assertSame('delivery', $order->type);
        $this->assertSame('2026-10-08 10:00:00', $order->scheduled_at->format('Y-m-d H:i:s'));
        $hold = $made['hold']->fresh();
        $this->assertSame('used', $hold->status);
        $this->assertSame((int) $order->id, (int) $hold->order_id);
        $this->assertSame((int) $hold->id, (int) $this->holds()->forOrder((int) $order->id)?->id);
        // 3 × 3.50 + 2 kg × 5.00
        $this->assertEqualsWithDelta(20.5, (float) $order->fresh()->total, 0.001);
    }

    public function test_the_delivery_back_place_stays_taken_after_the_order_and_is_freed_when_it_is_cancelled(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'a');
        $order = $svc->book($store, $token, 'delivery', ['customer_name' => 'Sam', 'phone_number' => '555', 'address' => 'x', 'hold' => $made['hold']->public_id, 'hold_token' => $made['token']]);

        $taken = fn () => $this->code(fn () => $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:30'), $this->slotId(2, '12:00'), 'b'));
        $this->assertSame('return_slot_taken', $taken());

        $order->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame('ok', $taken());
    }

    public function test_a_hold_can_be_used_once(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'a');
        $data = ['customer_name' => 'Sam', 'phone_number' => '555', 'address' => 'x', 'hold' => $made['hold']->public_id, 'hold_token' => $made['token']];
        $svc->book($store, $token, 'delivery', $data);

        // a second cart with the same (used) hold
        $other = 'tok-'.Str::random(12);
        $svc->addLine($store, $other, (int) Menu::query()->where('store_id', $store->id)->value('id'), null, 1);
        $this->expectException(LaundryHoldException::class);
        try {
            $svc->book($store, $other, 'delivery', $data);
        } catch (LaundryHoldException $e) {
            $this->assertSame('hold_expired', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_resend_with_the_same_client_key_is_the_same_order_and_needs_no_second_hold(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'a');
        $key = (string) Str::uuid7();
        $data = ['client_order_id' => $key, 'customer_name' => 'Sam', 'phone_number' => '555', 'address' => 'x', 'hold' => $made['hold']->public_id, 'hold_token' => $made['token']];

        $first = $svc->book($store, $token, 'delivery', $data);
        $again = $svc->book($store, $token, 'delivery', $data);

        $this->assertSame((int) $first->id, (int) $again->id);
        $this->assertSame(1, Order::query()->where('store_id', $store->id)->count());
    }

    public function test_a_refused_booking_gives_the_hold_back(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'a');

        try {
            $svc->book($store, $token, 'delivery', ['customer_name' => 'Sam', 'hold' => $made['hold']->public_id, 'hold_token' => $made['token']]); // no address
            $this->fail('a delivery booking needs an address');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address', $e->errors());
        }

        $this->assertSame('active', $made['hold']->fresh()->status, 'the hold was not used up by the refused booking');
        $this->assertSame(0, Order::query()->where('store_id', $store->id)->count());
    }

    public function test_a_wrong_token_an_expired_hold_a_foreign_store_and_another_mode_are_refused(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '12:00'), 'a');
        $book = fn (string $mode, array $hold) => $this->code(fn () => $svc->book($store, $token, $mode, ['customer_name' => 'Sam', 'address' => 'x'] + $hold));

        $this->assertSame('hold_not_found', $book('delivery', ['hold' => $made['hold']->public_id, 'hold_token' => 'nope']));
        $this->assertSame('hold_not_found', $book('delivery', ['hold' => (string) Str::uuid7(), 'hold_token' => $made['token']]));
        $this->assertSame('invalid_request', $book('pickup_slot', ['hold' => $made['hold']->public_id, 'hold_token' => $made['token']]));

        $foreign = $this->laundryStore();
        $this->assertSame('hold_not_found', $this->code(fn () => $svc->book($foreign, $token, 'delivery', ['customer_name' => 'S', 'address' => 'x', 'hold' => $made['hold']->public_id, 'hold_token' => $made['token']])));

        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertSame('hold_expired', $book('delivery', ['hold' => $made['hold']->public_id, 'hold_token' => $made['token']]));
    }

    public function test_a_drop_off_booking_with_the_hold_uses_the_held_slot(): void
    {
        [$store, $token, $svc] = $this->cartStore();
        $made = $this->holds()->hold($store, 'pickup_slot', $this->slotId(1, '10:00'), null, 'a');

        $order = $svc->book($store, $token, 'pickup_slot', ['customer_name' => 'Sam', 'hold' => $made['hold']->public_id, 'hold_token' => $made['token']]);

        $this->assertSame('pickup_slot', $order->type);
        $this->assertSame('2026-10-08 10:00:00', $order->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('used', $made['hold']->fresh()->status);
    }
}
