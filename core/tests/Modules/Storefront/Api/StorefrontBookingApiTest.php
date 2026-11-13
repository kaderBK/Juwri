<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-06b phase D — the headless booking API beyond the kit examples: tenancy / IDOR (a store's token never reaches
 * another store's booking, hold or staff), idempotent holds and bookings, D13, the plain refusals (options, integer
 * ids, too many holds, foreign return_url), pay-at-the-visit bookings, the lapsed deposit window, the client
 * return_url on the hosted deposit page, cancel after the deadline, and the verified-phone rule on cancel.
 */
class StorefrontBookingApiTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 06:31:05', 'UTC')); // Fri 09:31 in Riyadh
        $this->fx = new ApiFixtures;
        $this->store = $this->fx->sidraBookings();
    }

    /** @return array<string, string> */
    private function read(string $token = 'sfx_demo_sidra_read_0001'): array
    {
        return ['X-Storefront-Token' => $token, 'Accept' => 'application/json'];
    }

    /** @return array<string, string> */
    private function write(array $extra = [], string $token = 'sfx_demo_sidra_write_0001', string $origin = 'https://sidra-salon.example'): array
    {
        return $extra + ['X-Storefront-Token' => $token, 'Accept' => 'application/json', 'Origin' => $origin, 'Idempotency-Key' => (string) Str::uuid7()];
    }

    private function slotId(string $date = '2026-10-04', string $start = '2026-10-04T16:30:00+03:00', string $token = 'sfx_demo_sidra_read_0001'): string
    {
        $listing = $this->getJson('/api/v1/storefront/booking/slots?'.http_build_query(['service_ids' => [ApiFixtures::SVC_HAIRCUT], 'staff_id' => ApiFixtures::STAFF_MAHA, 'date' => $date]), $this->read($token))->assertOk();

        return (string) collect($listing->json('slots'))->firstWhere('starts_at', $start)['id'];
    }

    private function hold(?string $slot = null, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $slot ?? $this->slotId(), 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], $this->write($headers));
    }

    private function quoteHash(?string $holdId = null): string
    {
        return (string) $this->postJson('/api/v1/storefront/quote', array_filter([
            'order_type' => 'appointment', 'lines' => [['item_id' => ApiFixtures::SVC_HAIRCUT, 'qty' => 1]], 'hold_id' => $holdId,
        ]), $this->read())->assertOk()->json('quote_hash');
    }

    /** @return array<string, mixed> */
    private function bookingBody(TestResponse $hold, string $mode = 'hosted', string $returnUrl = 'https://sidra-salon.example/booking/return'): array
    {
        return [
            'hold_id' => (string) $hold->json('id'), 'service_ids' => [ApiFixtures::SVC_HAIRCUT], 'quote_hash' => $this->quoteHash((string) $hold->json('id')),
            'customer' => ['name' => 'Noura', 'phone' => '+966551234567'],
            'payment' => array_filter(['mode' => $mode, 'return_url' => $mode === 'hosted' ? $returnUrl : null]),
        ];
    }

    private function book(?TestResponse $hold = null, string $mode = 'hosted'): TestResponse
    {
        $hold ??= $this->hold()->assertCreated();

        return $this->postJson('/api/v1/storefront/bookings', $this->bookingBody($hold, $mode), $this->write(['X-Hold-Token' => (string) $hold->json('token')]));
    }

    private function other(): Store
    {
        $owner = $this->fx->owner();
        $other = $this->fx->store($owner, 'other-salon', 'Other Salon', 'services');
        $this->fx->token($other, 'sfx_other_read_0001', ['menu:read']);
        $this->fx->token($other, 'sfx_other_write_0001', ['order:write'], ['https://other-salon.example']);

        return $other;
    }

    public function test_another_stores_token_never_reaches_this_stores_booking_hold_or_staff(): void
    {
        $created = $this->book()->assertCreated();
        $manage = (string) $created->json('booking.manage_token');
        $this->other();
        $foreign = ['X-Storefront-Token' => 'sfx_other_write_0001', 'Accept' => 'application/json', 'Origin' => 'https://other-salon.example'];

        $this->getJson('/api/v1/storefront/bookings/'.$manage, $this->read('sfx_other_read_0001'))->assertNotFound();
        $this->postJson('/api/v1/storefront/bookings/'.$manage.'/cancel', [], $foreign + ['Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->postJson('/api/v1/storefront/bookings/'.$manage.'/reschedule', ['hold_id' => (string) Str::uuid7()], $foreign + ['Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->getJson('/api/v1/storefront/booking/slots?date=2026-10-04&service_ids[]='.ApiFixtures::SVC_HAIRCUT, $this->read('sfx_other_read_0001'))->assertStatus(409); // its own store has no hours (D13)

        // this store's hold and slot are unknown to the other store
        $hold = $this->hold($this->slotId('2026-10-05', '2026-10-05T16:30:00+03:00'))->assertCreated();
        $this->deleteJson('/api/v1/storefront/holds/'.$hold->json('id'), [], $foreign + ['X-Hold-Token' => (string) $hold->json('token')])->assertNotFound();
        $this->assertSame('active', DB::table('booking_holds')->where('public_id', $hold->json('id'))->value('status'));
        // a tampered manage token (the signature) is refused
        $this->getJson('/api/v1/storefront/bookings/'.substr($manage, 0, -1).(str_ends_with($manage, 'a') ? 'b' : 'a'), $this->read())->assertNotFound();
        $this->assertSame('pending_payment', Booking::query()->firstOrFail()->status);
    }

    public function test_a_slot_of_another_stores_staff_is_refused(): void
    {
        $other = $this->other();
        $this->fx->owner(); // keep ids apart
        $service = BookableService::query()->create(['store_id' => (int) $other->id, 'name' => 'Cut', 'duration_min' => 30, 'price_minor' => 5000, 'currency' => 'SAR', 'deposit_type' => 'none', 'deposit_value' => 0, 'venue' => true, 'at_home' => false, 'active' => true]);
        $staff = BookingStaff::query()->create(['store_id' => (int) $other->id, 'name' => 'Huda', 'active' => true, 'position' => 0]);
        $staff->services()->syncWithPivotValues([$service->id], ['store_id' => (int) $other->id, 'account_id' => (int) $other->account_id]);
        foreach ([0, 1, 2, 3, 4, 5, 6] as $day) {
            BookingHour::query()->create(['store_id' => (int) $other->id, 'staff_id' => null, 'weekday' => $day, 'kind' => 'open', 'starts_local' => '09:00', 'ends_local' => '22:00']);
        }
        $theirSlot = 'bks_'.str_replace('-', '', (string) $staff->fresh()->public_id).'_v'.base_convert((string) Carbon::parse('2026-10-04 10:00:00', 'UTC')->getTimestamp(), 10, 36);

        $this->hold($theirSlot)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        // and another store's service id is not a service here
        $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $this->slotId(), 'service_ids' => [(string) $service->fresh()->public_id]], $this->write())
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, DB::table('booking_holds')->count());
    }

    public function test_holds_and_bookings_are_idempotent_per_key(): void
    {
        $key = (string) Str::uuid7();
        $slot = $this->slotId();
        $first = $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $slot, 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], $this->write(['Idempotency-Key' => $key]))->assertCreated();
        $again = $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $slot, 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], $this->write(['Idempotency-Key' => $key]));
        $this->assertSame(201, $again->getStatusCode(), (string) $again->getContent());
        $this->assertSame('true', $again->headers->get('Idempotent-Replayed'));
        $this->assertSame([$first->json('id'), $first->json('token')], [$again->json('id'), $again->json('token')]);
        $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $this->slotId('2026-10-05', '2026-10-05T16:30:00+03:00'), 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], $this->write(['Idempotency-Key' => $key]))
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, DB::table('booking_holds')->count());

        $bookingKey = (string) Str::uuid7();
        $body = $this->bookingBody($first);
        $headers = $this->write(['Idempotency-Key' => $bookingKey, 'X-Hold-Token' => (string) $first->json('token')]);
        $created = $this->postJson('/api/v1/storefront/bookings', $body, $headers)->assertCreated()->assertJsonPath('replayed', false);
        $replayed = $this->postJson('/api/v1/storefront/bookings', $body, $headers)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame('true', $replayed->headers->get('Idempotent-Replayed'));
        $this->assertSame($created->json('booking.public_id'), $replayed->json('booking.public_id'));
        $this->postJson('/api/v1/storefront/bookings', ['notes' => 'other body'] + $body, $headers)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Booking::query()->count());
    }

    public function test_bookings_open_soon_without_hours_d13(): void
    {
        BookingHour::query()->where('store_id', $this->store->id)->delete();
        $this->getJson('/api/v1/storefront/booking/days?service_ids[]='.ApiFixtures::SVC_HAIRCUT, $this->read())->assertStatus(409)->assertJsonPath('code', 'bookings_not_open');
        $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => 'bks_any_v'.base_convert('1791124200', 10, 36), 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], $this->write())
            ->assertStatus(409)->assertJsonPath('code', 'bookings_not_open');
    }

    public function test_plain_refusals_options_integer_ids_too_many_holds(): void
    {
        $this->getJson('/api/v1/storefront/booking/slots?date=2026-10-04&service_ids[]='.ApiFixtures::SVC_HAIRCUT.'&option_ids[]='.Str::uuid7(), $this->read())
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $id = (string) BookableService::query()->where('store_id', $this->store->id)->value('id');
        $this->getJson('/api/v1/storefront/booking/slots?date=2026-10-04&service_ids[]='.$id, $this->read())
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed'); // integer ids never come in

        foreach (['16:30', '18:00', '19:30'] as $time) {
            $this->hold($this->slotId('2026-10-04', '2026-10-04T'.$time.':00+03:00'))->assertCreated();
        }
        $limited = $this->hold($this->slotId('2026-10-05', '2026-10-05T16:30:00+03:00'))->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertTrue($limited->headers->has('Retry-After'));
    }

    public function test_a_service_without_deposit_is_booked_pay_later_and_confirmed(): void
    {
        BookableService::query()->where('store_id', $this->store->id)->update(['deposit_type' => 'none', 'deposit_value' => 0]);
        $hold = $this->hold()->assertCreated();
        $quote = $this->postJson('/api/v1/storefront/quote', ['order_type' => 'appointment', 'lines' => [['item_id' => ApiFixtures::SVC_HAIRCUT, 'qty' => 1]], 'hold_id' => $hold->json('id'), 'payment_mode' => 'hosted'], $this->read())->assertOk();
        $this->assertSame(['pay_later'], $quote->json('payment_modes'));
        $this->assertSame(['payment_mode_unavailable'], array_column($quote->json('warnings'), 'code'));
        $this->assertSame(['subtotal'], array_column($quote->json('quote.rows'), 'kind'));

        $this->postJson('/api/v1/storefront/bookings', $this->bookingBody($hold, 'hosted'), $this->write(['X-Hold-Token' => (string) $hold->json('token')]))
            ->assertStatus(422)->assertJsonPath('code', 'payment_mode_unavailable');
        $created = $this->postJson('/api/v1/storefront/bookings', $this->bookingBody($hold, 'pay_later'), $this->write(['X-Hold-Token' => (string) $hold->json('token')]))->assertCreated();
        $this->assertSame(['confirmed', 'pay_later'], [$created->json('booking.status'), $created->json('payment.status')]);
    }

    public function test_the_deposit_window_lapses_and_the_client_return_url_reaches_the_hosted_page(): void
    {
        $hold = $this->hold()->assertCreated();
        $this->postJson('/api/v1/storefront/bookings', $this->bookingBody($hold, 'hosted', 'https://evil.example/x'), $this->write(['X-Hold-Token' => (string) $hold->json('token')]))
            ->assertStatus(422)->assertJsonPath('code', 'return_url_not_allowed');
        $created = $this->book($hold)->assertCreated();
        $this->assertStringContainsString('/appointments/m/', (string) $created->json('payment.next_action.url'));

        $booking = Booking::query()->firstOrFail();
        $txn = app(DepositService::class)->start($booking, 'stripe');
        $this->assertSame('https://sidra-salon.example/booking/return', $txn->details->client_return_url);
        $this->assertSame('https://sidra-salon.example/booking/return', Settlement::cancelUrl($txn), 'the customer goes back to the client app');

        $this->travel(16)->minutes();
        $this->getJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token'), $this->read())->assertOk()
            ->assertJsonPath('booking.status', 'cancelled')->assertJsonPath('payment.status', 'failed')->assertJsonPath('payment.next_action', null);
        $this->assertSame(0, DB::table('booking_slot_cells')->count(), 'the time is free again');
    }

    public function test_cancel_after_the_deadline_keeps_the_deposit_and_says_so(): void
    {
        StoreSettings::set((int) $this->store->id, 'booking_deposit_refunds', true);
        $created = $this->book()->assertCreated();
        $booking = Booking::query()->firstOrFail();
        Settlement::settleForBuyer(app(DepositService::class)->start($booking, 'stripe')->fresh(), 'stripe', 'pi_keep', claim: true);
        $show = $this->getJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token'), $this->read())->assertOk();
        $this->assertStringContainsString('After that the deposit is kept.', (string) $show->json('policy_note'));

        $this->travelTo(Carbon::parse('2026-10-03 14:00:00', 'UTC')); // the deadline was 13:30 UTC
        $this->postJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token').'/reschedule', ['hold_id' => (string) Str::uuid7()], $this->write())
            ->assertStatus(409)->assertJsonPath('code', 'past_deadline');
        $cancelled = $this->postJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token').'/cancel', [], $this->write())->assertOk();
        $this->assertSame(['cancelled', 'kept'], [$cancelled->json('booking.status'), $cancelled->json('payment.status')]);
        $this->assertStringContainsString('not refunded', (string) $cancelled->json('policy_note'));
        $this->postJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token').'/cancel', [], $this->write())
            ->assertStatus(409)->assertJsonPath('code', 'booking_not_active');
    }

    public function test_deposit_refunds_off_the_policy_says_deposits_are_not_refunded(): void
    {
        $created = $this->book()->assertCreated();
        Settlement::settleForBuyer(app(DepositService::class)->start(Booking::query()->firstOrFail(), 'stripe')->fresh(), 'stripe', 'pi_off', claim: true);
        $show = $this->getJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token'), $this->read())->assertOk();
        $this->assertStringStartsWith('Deposits are not refunded.', (string) $show->json('policy_note'));
        $cancelled = $this->postJson('/api/v1/storefront/bookings/'.$created->json('booking.manage_token').'/cancel', [], $this->write())->assertOk();
        $this->assertSame('kept', $cancelled->json('payment.status'));
    }

    public function test_with_verify_guest_phone_cancel_needs_the_bookings_own_verified_phone(): void
    {
        $created = $this->book()->assertCreated();
        StoreSettings::set((int) $this->store->id, 'booking_verify_guest_phone', true);
        $path = '/api/v1/storefront/bookings/'.$created->json('booking.manage_token').'/cancel';
        $this->postJson($path, [], $this->write())->assertForbidden()->assertJsonPath('code', 'phone_verification_required');

        $stranger = Customer::query()->create(['store_id' => $this->store->id, 'name' => 'Other', 'phone' => '+966500000000']);
        $stranger->forceFill(['phone_verified_at' => now()])->save();
        $raw = CustomerToken::issue($stranger, 'test');
        $this->postJson($path, [], $this->write(['Authorization' => 'Bearer '.$raw]))->assertForbidden();
        $this->assertSame('pending_payment', Booking::query()->firstOrFail()->status);
    }
}
