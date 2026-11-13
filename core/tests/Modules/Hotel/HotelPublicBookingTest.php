<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Http\Controllers\HotelGuestController;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C1 — the public room-booking page (3 modes: off | request | instant).
 *
 * Lane A owns the HotelBooking/BookingService engine, absent in this worktree, so the
 * controller is class_exists-guarded and these assertions target behaviour that is stable
 * across the merge boundary: the off→404 gate, the GET page (date pickers + guest fields +
 * "opens soon" empty state when the engine has not shipped), request validation, throttle
 * headers and RTL. The request-pending / instant-confirmed / race-full paths are exercised
 * post-merge (orchestrator Chrome walk, SPEC §7.4).
 */
class HotelPublicBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    private function hotelStore(string $mode = 'request'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        if ($mode !== 'off') {
            StoreSettings::set($store->id, 'hotel_online_booking', $mode);
        }

        return $store;
    }

    public function test_booking_off_is_404_for_both_verbs(): void
    {
        $store = $this->hotelStore('off');

        $this->get(route('hotel.guest.book', $store->slug))->assertNotFound();
        $this->post(route('hotel.guest.book.submit', $store->slug), [])->assertNotFound();
    }

    public function test_hotel_mode_off_is_404_even_with_booking_on(): void
    {
        $store = $this->hotelStore('request');
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '0');

        $this->get(route('hotel.guest.book', $store->slug))->assertNotFound();
    }

    public function test_request_mode_renders_the_booking_page(): void
    {
        $store = $this->hotelStore('request');

        $this->get(route('hotel.guest.book', $store->slug))
            ->assertOk()->assertSee('Book a room')->assertSeeHtml('name="check_in"')->assertSeeHtml('name="check_out"')
            // engine absent in-worktree → the graceful empty state, never a 500
            ->assertSee('Online booking opens soon');
    }

    public function test_instant_mode_also_renders(): void
    {
        $store = $this->hotelStore('instant');

        $this->get(route('hotel.guest.book', $store->slug))->assertOk()->assertSee('Book a room');
    }

    public function test_get_carries_throttle_headers(): void
    {
        $store = $this->hotelStore('request');

        $this->get(route('hotel.guest.book', $store->slug))
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 30);
    }

    public function test_submit_rejects_checkout_before_checkin(): void
    {
        $store = $this->hotelStore('request');

        $this->from(route('hotel.guest.book', $store->slug))
            ->post(route('hotel.guest.book.submit', $store->slug), [
                'guest_name' => 'Sam Carter',
                'room_type_id' => 1,
                'check_in' => now()->addDays(3)->toDateString(),
                'check_out' => now()->addDays(2)->toDateString(),
                'adults' => 2,
            ])
            ->assertSessionHasErrors('check_out');
    }

    public function test_submit_requires_guest_name(): void
    {
        $store = $this->hotelStore('request');

        $this->from(route('hotel.guest.book', $store->slug))
            ->post(route('hotel.guest.book.submit', $store->slug), [
                'room_type_id' => 1,
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
            ])
            ->assertSessionHasErrors('guest_name');
    }

    /**
     * HPMS §8-P1 (security): an anon guest must not be able to submit a room_type_id
     * belonging to another store — it would surface that store's room-type name on the
     * token manage page. The submit is refused and no booking is created.
     */
    public function test_submit_rejects_room_type_from_another_store(): void
    {
        $a = $this->hotelStore('request');
        $b = $this->hotelStore('request');
        $foreign = RoomType::create([
            'store_id' => $b->id, 'name' => 'Foreign Suite', 'code' => 'FS',
            'base_rate' => 99, 'max_occupancy' => 2, 'active' => 1, 'sort' => 1,
        ]);

        $this->from(route('hotel.guest.book', $a->slug))
            ->post(route('hotel.guest.book.submit', $a->slug), [
                'guest_name' => 'Mallory',
                'room_type_id' => $foreign->id,
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'adults' => 2,
            ])
            ->assertSessionHas('booking_error');

        $this->assertSame(0, HotelBooking::where('store_id', $a->id)->count());
    }

    public function test_rtl_locale_renders_in_rtl(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');
        $store = $this->hotelStore('request');

        $this->get(route('hotel.guest.book', $store->slug))->assertOk()->assertSeeHtml('dir="rtl"');
    }

    /* ================================================================
     | C2 — manage page + cancel-window matrix + owner alert dispatch.
     | (Lane A's HotelBooking engine is absent here, so the manage page is
     | tested by rendering the blade with a plain booking object and the
     | window / alert logic by invoking the guarded private methods.)
     * ============================================================== */

    /** @return object a booking-shaped object the blade + policy methods accept */
    private function fakeBooking(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1, 'code' => 'ABCD1234', 'status' => 'pending',
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'adults' => 2, 'children' => 1, 'quoted_total' => 300.0,
            'currency' => 'USD', 'guest_name' => 'Sam Carter',
            'room_type_id' => 0, 'rate_plan_id' => 0, 'token' => str_repeat('a', 64),
        ], $overrides);
    }

    /** @param mixed ...$args */
    private function invoke(string $method, ...$args): mixed
    {
        $c = new HotelGuestController;
        $m = new \ReflectionMethod($c, $method);
        $m->setAccessible(true);

        return $m->invoke($c, ...$args);
    }

    public function test_manage_blade_renders_status_and_cancel(): void
    {
        $store = $this->hotelStore('request');
        $booking = $this->fakeBooking(['status' => 'confirmed']);

        $html = view('hotel.booking-manage', [
            'store' => $store, 'booking' => $booking, 'typeName' => 'Deluxe King',
            'currency' => 'USD', 'cancellable' => true,
        ])->render();

        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('ABCD1234', $html);
        $this->assertStringContainsString('Deluxe King', $html);
        $this->assertStringContainsString('Cancel this booking', $html);
    }

    public function test_manage_blade_hides_cancel_when_not_cancellable(): void
    {
        $store = $this->hotelStore('request');
        $booking = $this->fakeBooking(['status' => 'checked_out']);

        $html = view('hotel.booking-manage', [
            'store' => $store, 'booking' => $booking, 'typeName' => 'Room',
            'currency' => 'USD', 'cancellable' => false,
        ])->render();

        $this->assertStringNotContainsString('Cancel this booking', $html);
    }

    public function test_manage_unknown_token_is_404(): void
    {
        $store = $this->hotelStore('request');

        // Engine absent here → 404 (and post-merge an unknown token is still 404).
        $this->get(route('hotel.guest.booking', ['slug' => $store->slug, 'token' => str_repeat('z', 64)]))
            ->assertNotFound();
    }

    public function test_cancel_window_matrix(): void
    {
        // Default plan = refundable, 24h notice. Deadline = check-in 00:00 − 24h.
        $this->assertTrue($this->invoke('bookingCancellable', $this->fakeBooking(['status' => 'pending'])), 'pending, 5 days out → cancellable');
        $this->assertTrue($this->invoke('bookingCancellable', $this->fakeBooking(['status' => 'confirmed'])), 'confirmed, 5 days out → cancellable');
        $this->assertFalse($this->invoke('bookingCancellable', $this->fakeBooking(['status' => 'checked_in'])), 'checked_in → not cancellable');
        $this->assertFalse($this->invoke('bookingCancellable', $this->fakeBooking(['status' => 'cancelled'])), 'already cancelled → not cancellable');
        $this->assertFalse(
            $this->invoke('bookingCancellable', $this->fakeBooking(['status' => 'pending', 'check_in' => now()->toDateString()])),
            'check-in today is past the 24h deadline → not cancellable'
        );
    }

    public function test_alert_dispatch_matrix(): void
    {
        $store = $this->hotelStore('request');

        // Default: notif ON, WhatsApp OFF.
        Bus::fake();
        $this->invoke('dispatchBookingAlerts', $store, $this->fakeBooking(), 'request');
        Bus::assertDispatched(CreateNotificationJob::class, fn ($j) => $j->type === 'hotel_booking');
        Bus::assertNotDispatched(SendWhatsAppJob::class);

        // Notif toggled off.
        Bus::fake();
        StoreSettings::set($store->id, 'hotel_alerts_notif', '0');
        $this->invoke('dispatchBookingAlerts', $store, $this->fakeBooking(), 'request');
        Bus::assertNotDispatched(CreateNotificationJob::class);

        // WhatsApp enabled with a reception number.
        Bus::fake();
        StoreSettings::set($store->id, 'hotel_alerts_whatsapp', '1');
        StoreSettings::set($store->id, 'hotel_settings', json_encode(['contact' => ['whatsapp' => '+1 (555) 010-2222']]));
        $this->invoke('dispatchBookingAlerts', $store, $this->fakeBooking(), 'instant');
        Bus::assertDispatched(SendWhatsAppJob::class, fn ($j) => $j->feature === 'hotel_booking' && trim($j->to) !== '');
    }

    public function test_alert_title_carries_dates_and_range_arrow(): void
    {
        $store = $this->hotelStore('request');
        Bus::fake();

        $this->invoke('dispatchBookingAlerts', $store, $this->fakeBooking(['check_in' => '2026-09-01', 'check_out' => '2026-09-03']), 'request');

        Bus::assertDispatched(CreateNotificationJob::class, function ($j) {
            return str_contains($j->title, '2026-09-01') && str_contains($j->title, '2026-09-03') && str_contains($j->title, '→');
        });
    }
}
