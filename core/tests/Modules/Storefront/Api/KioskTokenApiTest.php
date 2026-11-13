<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * Lane C (kit/api API-13, API-15, API-24d): kiosk device tokens on the headless API — the three gates (plan key
 * `kiosk_api`, platform feature `kiosk`, the store's `kiosk_enabled`), `GET /kiosk` for kiosk tokens only, orders and
 * bookings tagged `source=kiosk` server-side, no delivery at a kiosk, and the walk-in exemption: today only, no deposit,
 * no guest phone check for making the booking (changing one at a kiosk with the rule on stays refused).
 */
class KioskTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    /** @var array<string, string> */
    private array $kiosk = ['X-Storefront-Token' => 'sfx_demo_sidra_kiosk_0001', 'Accept' => 'application/json', 'Origin' => 'https://sidra-salon.example'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 08:01:00', 'UTC')); // Fri 11:01 in Riyadh
        $this->fx = new ApiFixtures;
    }

    private function slotAt(string $date, string $localStart): string
    {
        $slots = $this->getJson('/api/v1/storefront/booking/slots?'.http_build_query(['service_ids' => [ApiFixtures::SVC_HAIRCUT], 'staff_id' => ApiFixtures::STAFF_MAHA, 'date' => $date]), $this->kiosk)->assertOk();
        $slot = collect($slots->json('slots'))->firstWhere('starts_at', $localStart);
        $this->assertNotNull($slot, "a slot at {$localStart}");

        return (string) $slot['id'];
    }

    private function hold(string $slotId): TestResponse
    {
        return $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $slotId, 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], $this->kiosk + ['Idempotency-Key' => (string) Str::uuid7()]);
    }

    public function test_the_kiosk_gates_and_get_kiosk_for_kiosk_tokens_only(): void
    {
        $store = $this->fx->sidraBookings();
        $this->fx->kioskOn($store);
        $this->getJson('/api/v1/storefront/kiosk', $this->kiosk)->assertOk()->assertJsonPath('kiosk.on', true);
        // a read token is not a kiosk
        $this->getJson('/api/v1/storefront/kiosk', ['X-Storefront-Token' => 'sfx_demo_sidra_read_0001', 'Accept' => 'application/json'])->assertForbidden();

        // the owner's switch off: kiosk_not_enabled without details.feature, on every kiosk-token call
        StoreSettings::set((int) $store->id, 'kiosk_enabled', false);
        $off = $this->getJson('/api/v1/storefront/store', $this->kiosk)->assertForbidden()->assertJsonPath('code', 'kiosk_not_enabled');
        $this->assertNull($off->json('details.feature'));
        $this->getJson('/api/v1/storefront/store', ['X-Storefront-Token' => 'sfx_demo_sidra_read_0001', 'Accept' => 'application/json'])->assertOk();

        // the platform feature off: kiosk_not_enabled with details.feature = kiosk
        $this->fx->kioskOn($store, feature: false);
        $this->getJson('/api/v1/storefront/store', $this->kiosk)->assertForbidden()->assertJsonPath('details.feature', 'kiosk');
    }

    public function test_a_kiosk_books_walk_ins_today_only_without_deposit_or_phone_check(): void
    {
        $store = $this->fx->sidraBookings(settings: ['booking_verify_guest_phone' => true]);
        $this->fx->kioskOn($store);

        // another day is refused, and its hold is given back at once
        $this->hold($this->slotAt('2026-10-04', '2026-10-04T13:00:00+03:00'))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, BookingHold::query()->where('status', 'active')->count());

        $hold = $this->hold($this->slotAt('2026-10-02', '2026-10-02T16:30:00+03:00'))->assertCreated();
        $quote = $this->postJson('/api/v1/storefront/quote', ['order_type' => 'walk_in', 'lines' => [['line_id' => 'ln_1', 'item_id' => ApiFixtures::SVC_HAIRCUT, 'qty' => 1]], 'hold_id' => $hold->json('id')], $this->kiosk)->assertOk();
        $this->assertSame([], $quote->json('requires'));
        $this->assertSame(['pay_later'], $quote->json('payment_modes'));

        // a hosted deposit is not offered at a kiosk
        $this->postJson('/api/v1/storefront/bookings', [
            'hold_id' => $hold->json('id'), 'service_ids' => [ApiFixtures::SVC_HAIRCUT], 'quote_hash' => $quote->json('quote_hash'),
            'customer' => ['name' => 'Reem', 'phone' => '+966500000123'], 'payment' => ['mode' => 'hosted', 'return_url' => 'https://sidra-salon.example/done'],
        ], $this->kiosk + ['Idempotency-Key' => (string) Str::uuid7(), 'X-Hold-Token' => (string) $hold->json('token')])->assertStatus(422)->assertJsonPath('code', 'payment_mode_unavailable');

        $booked = $this->postJson('/api/v1/storefront/bookings', [
            'hold_id' => $hold->json('id'), 'service_ids' => [ApiFixtures::SVC_HAIRCUT], 'quote_hash' => $quote->json('quote_hash'),
            'customer' => ['name' => 'Reem', 'phone' => '+966500000123'], 'payment' => ['mode' => 'pay_later', 'method_id' => 'venue'],
            'source' => 'web', // the body cannot set the source
        ], $this->kiosk + ['Idempotency-Key' => (string) Str::uuid7(), 'X-Hold-Token' => (string) $hold->json('token')])->assertCreated();
        $booking = Booking::query()->where('public_id', $booked->json('booking.public_id'))->firstOrFail();
        $this->assertSame(['confirmed', 'kiosk', 0, 'none'], [$booking->status, $booking->source, $booking->deposit_minor, $booking->deposit_status]);
        $this->assertSame('pay_later', $booked->json('payment.status'));

        // changing a booking at a kiosk with the rule on stays refused (API-15 covers making it)
        $this->postJson('/api/v1/storefront/bookings/'.$booked->json('booking.manage_token').'/cancel', [], $this->kiosk + ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertForbidden()->assertJsonPath('code', 'phone_verification_required');
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_walk_in_is_a_kiosk_order_type_and_a_web_token_still_needs_the_verified_phone(): void
    {
        $store = $this->fx->sidraBookings(settings: ['booking_verify_guest_phone' => true]);
        $this->fx->kioskOn($store);
        $read = ['X-Storefront-Token' => 'sfx_demo_sidra_read_0001', 'Accept' => 'application/json'];
        $this->postJson('/api/v1/storefront/quote', ['order_type' => 'walk_in', 'lines' => [['item_id' => ApiFixtures::SVC_HAIRCUT, 'qty' => 1]]], $read)
            ->assertStatus(422)->assertJsonPath('code', 'invalid_order_type');
        $this->assertContains('verified_phone', $this->postJson('/api/v1/storefront/quote', ['order_type' => 'appointment', 'lines' => [['item_id' => ApiFixtures::SVC_HAIRCUT, 'qty' => 1]]], $read)->json('requires'));
    }

    public function test_kiosk_orders_are_tagged_kiosk_and_never_delivery(): void
    {
        $store = $this->fx->saffron();
        $this->fx->kioskOn($store);
        $kiosk = ['X-Storefront-Token' => 'sfx_demo_saffron_kiosk_0001', 'Accept' => 'application/json', 'Origin' => 'https://saffron-house.example'];
        $lines = [['line_id' => 'ln_1', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1, 'option_ids' => []]];

        $this->postJson('/api/v1/storefront/quote', ['order_type' => 'delivery', 'lines' => $lines], $kiosk)->assertStatus(422)->assertJsonPath('code', 'invalid_order_type');
        $quote = $this->postJson('/api/v1/storefront/quote', ['order_type' => 'takeaway', 'lines' => $lines], $kiosk)->assertOk();
        $placed = $this->postJson('/api/v1/storefront/orders', [
            'order_type' => 'takeaway', 'lines' => $lines, 'quote_hash' => $quote->json('quote_hash'),
            'customer' => ['name' => 'Walk-in', 'phone' => '+966500000124'], 'payment' => ['mode' => 'pay_later'],
        ], $kiosk + ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->assertSame('kiosk', Order::query()->where('track_token', $placed->json('order.track_token'))->value('source'));
    }
}
