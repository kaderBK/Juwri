<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Modules\Reservations\Jobs\PushRwgBookingUpdateJob;
use Juwri\Core\Models\Store;
use App\Models\Reservation;
use Juwri\Core\Models\User;
use Juwri\Modules\Reservations\Services\Rwg\RwgRealtimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — C6 real-time updates (RTU).
 *
 * The Reservation MODEL observer dispatches an RTU push on any status transition of
 * a Google-sourced booking — including writes outside the controller — and never for
 * store-native rows. The client is DARK (zero HTTP) until configured.
 */
class RwgRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R' . Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function googleBooking(Store $store, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'store_id' => $store->id, 'name' => 'G', 'party_size' => 2,
            'reserved_at' => now()->addDay()->toDateTimeString(), 'status' => $status,
            'token' => Str::random(40), 'rwg_booking_id' => 'g-' . Str::random(10), 'source' => 'google',
        ]);
    }

    private function storeBooking(Store $store): Reservation
    {
        return Reservation::create([
            'store_id' => $store->id, 'name' => 'S', 'party_size' => 2,
            'reserved_at' => now()->addDay()->toDateTimeString(), 'status' => 'confirmed',
            'token' => Str::random(40), // no rwg_booking_id → store-native
        ]);
    }

    public function test_status_change_on_google_booking_dispatches_rtu(): void
    {
        $store = $this->store();
        $booking = $this->googleBooking($store, 'confirmed');

        Queue::fake();
        $booking->update(['status' => 'cancelled']);

        Queue::assertPushed(PushRwgBookingUpdateJob::class,
            fn (PushRwgBookingUpdateJob $j) => $j->reservationId === $booking->id);
    }

    public function test_store_native_booking_does_not_dispatch(): void
    {
        $store = $this->store();
        $booking = $this->storeBooking($store);

        Queue::fake();
        $booking->update(['status' => 'seated']);

        Queue::assertNotPushed(PushRwgBookingUpdateJob::class);
    }

    public function test_creating_a_google_booking_does_not_push_on_create(): void
    {
        $store = $this->store();

        Queue::fake();
        $this->googleBooking($store, 'confirmed'); // created, not updated

        Queue::assertNotPushed(PushRwgBookingUpdateJob::class);
    }

    public function test_non_status_change_does_not_dispatch(): void
    {
        $store = $this->store();
        $booking = $this->googleBooking($store);

        Queue::fake();
        $booking->update(['notes' => 'window seat']); // status unchanged

        Queue::assertNotPushed(PushRwgBookingUpdateJob::class);
    }

    /**
     * The observer must fire for writes OUTSIDE the controller (the whole reason it
     * lives on the model). ReservationDepositService::markPaid flips status via a bare
     * model save(); we mirror that here and assert the RTU still dispatches.
     */
    public function test_observer_catches_out_of_controller_writer(): void
    {
        $store = $this->store();
        $booking = $this->googleBooking($store, 'pending');

        Queue::fake();
        // Mirror ReservationDepositService::markPaid()'s out-of-controller flip.
        $booking->status = 'confirmed';
        $booking->save();

        Queue::assertPushed(PushRwgBookingUpdateJob::class);
    }

    public function test_job_is_dark_no_http_when_unconfigured(): void
    {
        $store = $this->store();
        $booking = $this->googleBooking($store);
        config(['services.reserve_with_google.enabled' => false]);

        Http::fake();
        new PushRwgBookingUpdateJob($booking->id)->handle(app(RwgRealtimeClient::class));

        Http::assertNothingSent();
    }

    public function test_client_enabled_gate(): void
    {
        // Even with an endpoint, no SA file ⇒ disabled.
        config([
            'services.reserve_with_google.enabled' => true,
            'services.reserve_with_google.rtu_endpoint' => 'https://rtu.example.test/notify',
            'services.reserve_with_google.service_account_json' => '',
        ]);
        $this->assertFalse(app(RwgRealtimeClient::class)->enabled());
    }

    public function test_job_pushes_http_when_fully_configured(): void
    {
        $store = $this->store();
        $booking = $this->googleBooking($store, 'confirmed');

        // Generate a throwaway RSA key so the RS256 JWT actually signs.
        $keyRes = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyRes, $privateKey);
        $saDir = storage_path('framework/testing');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($saDir);
        $saPath = $saDir . '/rwg-sa-' . Str::random(6) . '.json';
        file_put_contents($saPath, json_encode([
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key'  => $privateKey,
            'project_id'   => 'test-project',
        ]));

        config([
            'services.reserve_with_google.enabled' => true,
            'services.reserve_with_google.rtu_endpoint' => 'https://rtu.example.test/notify',
            'services.reserve_with_google.service_account_json' => $saPath,
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600], 200),
            'rtu.example.test/*'      => Http::response([], 200),
        ]);

        new PushRwgBookingUpdateJob($booking->id)->handle(app(RwgRealtimeClient::class));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'rtu.example.test')
            && $req['booking']['booking_id'] === $booking->rwg_booking_id
            && $req['booking']['status'] === 'CONFIRMED');

        @unlink($saPath);
    }
}
