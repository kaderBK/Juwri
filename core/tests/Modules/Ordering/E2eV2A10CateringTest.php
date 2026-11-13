<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\CateringRequest;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\Catering\CateringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * Platform E2E v2 — A10 catering request (service level).
 *
 * The public catering route is gated by the GLOBAL `catering` kill-switch, which
 * ships DARK — enabling it platform-wide is an app-wide change this run must not
 * make, so the public HTTP request is BLOCKED-OWNER (see ISS-V-A-002). The
 * a-reservations-waitlist journey covers the reservation + waitlist customer
 * surfaces live; this pins the catering DEPTH via CateringService (feature
 * toggled TEST-LOCALLY, not in the shared env):
 *   • the request is refused when the feature/toggle is off (the gate);
 *   • with it on, a valid future request is created in the initial `requested`
 *     state;
 *   • an event that violates the store's lead-time is rejected (the terminal /
 *     precondition guard).
 */
class E2eV2A10CateringTest extends TestCase
{
    use RefreshDatabase;

    private function store(bool $enabledOption = true): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(100000, 999999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        if ($enabledOption) {
            StoreSettings::set($store->id, 'catering_enabled', '1');
        }

        return $store;
    }

    private function payload(string $eventAt): array
    {
        return ['name' => 'E2E Caterer', 'phone' => '+966500000013', 'event_at' => $eventAt, 'headcount' => 40];
    }

    public function test_catering_request_is_gated_when_feature_off(): void
    {
        config(['settings.admin_allow_catering' => false]); // dark
        $svc = app(CateringService::class);
        $store = $this->store();

        $this->expectException(ValidationException::class);
        $svc->createRequest($store->id, $this->payload(now()->addDays(14)->toDateTimeString()));
    }

    public function test_catering_request_creates_when_enabled_and_guards_lead_time(): void
    {
        config(['settings.admin_allow_catering' => true]); // entitlement (test-local)
        $svc = app(CateringService::class);
        $store = $this->store();
        // Require 7 days' notice.
        StoreSettings::set($store->id, 'catering_min_lead_days', '7');

        // A valid, far-enough-out request is created in the initial state.
        $req = $svc->createRequest($store->id, $this->payload(now()->addDays(14)->toDateTimeString()));
        $this->assertInstanceOf(CateringRequest::class, $req);
        $this->assertSame('requested', $req->status, 'a new catering request starts in the requested state');
        $this->assertSame(40, (int) $req->headcount);
        $this->assertSame(1, CateringRequest::where('store_id', $store->id)->count());

        // Terminal/precondition guard: an event inside the lead window is rejected,
        // and no second request row is written.
        try {
            $svc->createRequest($store->id, $this->payload(now()->addDays(2)->toDateTimeString()));
            $this->fail('an event inside the lead time must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('event_at', $e->errors());
        }
        $this->assertSame(1, CateringRequest::where('store_id', $store->id)->count(), 'the rejected request wrote no row');
    }
}
