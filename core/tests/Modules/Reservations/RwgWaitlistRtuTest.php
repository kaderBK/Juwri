<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Modules\Reservations\Jobs\PushRwgWaitlistRtuJob;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Juwri\Core\Models\User;
use App\Models\WaitlistEntry;
use Juwri\Modules\Reservations\Services\Rwg\Contracts\RwgWaitlistRtuClient;
use Juwri\Modules\Reservations\Services\Rwg\FakeRwgWaitlistRtuClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane D (D4): RwG real-time-update dispatch + SMS opt-out fix.
 */
class RwgWaitlistRtuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Production-shaped layout context (stdClass via ViewServiceProvider), not a
        // hand-rolled double — keeps ViewComposerContractTest's cap intact.
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
        ]);

        // RwG live by default in these tests; individual dark tests flip it off.
        config(['services.reserve_with_google.enabled' => true]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'Plan ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(Store $r, string $source, ?string $phone = null, string $status = 'waiting'): WaitlistEntry
    {
        return WaitlistEntry::create([
            'store_id' => $r->id, 'name' => 'G' . Str::random(3), 'phone' => $phone,
            'party_size' => 2, 'status' => $status, 'source' => $source, 'quoted_wait' => 20,
            'rwg_entry_id' => $source === 'google' ? 'e-' . Str::random(8) : null,
        ]);
    }

    /** Google entry notify → SERVICE_READY RTU dispatched. */
    public function test_google_notify_pushes_service_ready(): void
    {
        Queue::fake();
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $e = $this->entry($r, 'google', '+16505551111');

        $this->actingAs($owner)->post(route('waitlist.notify', [$r->id, $e->id]))->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $e->id, 'status' => 'notified']);
        Queue::assertPushed(PushRwgWaitlistRtuJob::class, fn ($j) => $j->entryId === $e->id && $j->googleState === 'SERVICE_READY');
    }

    /** SMS opt-out fix: opted-out GOOGLE entry still flips + pushes (state authoritative). */
    public function test_opted_out_google_entry_still_flips_and_pushes(): void
    {
        Queue::fake();
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        SmsOptout::create(['phone' => '+16505552222', 'store_id' => null, 'source' => 'reply']);
        $e = $this->entry($r, 'google', '+16505552222');

        $this->actingAs($owner)->post(route('waitlist.notify', [$r->id, $e->id]))->assertRedirect();

        // Flipped despite opt-out; no SMS sent; RTU pushed.
        $this->assertDatabaseHas('waitlist_entries', ['id' => $e->id, 'status' => 'notified']);
        $this->assertDatabaseMissing('sms_logs', ['to' => '+16505552222', 'feature' => 'waitlist']);
        Queue::assertPushed(PushRwgWaitlistRtuJob::class, fn ($j) => $j->entryId === $e->id && $j->googleState === 'SERVICE_READY');
    }

    /** Regression: opted-out STORE entry stays waiting, no RTU. */
    public function test_opted_out_store_entry_stays_waiting_no_rtu(): void
    {
        Queue::fake();
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        SmsOptout::create(['phone' => '+16505553333', 'store_id' => null, 'source' => 'reply']);
        $e = $this->entry($r, 'store', '+16505553333');

        $this->actingAs($owner)->post(route('waitlist.notify', [$r->id, $e->id]))->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $e->id, 'status' => 'waiting']);
        Queue::assertNotPushed(PushRwgWaitlistRtuJob::class);
    }

    /** Store-sourced transitions never push RTU. */
    public function test_store_sourced_notify_does_not_push(): void
    {
        Queue::fake();
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $e = $this->entry($r, 'store', '+16505554444');

        $this->actingAs($owner)->post(route('waitlist.notify', [$r->id, $e->id]))->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $e->id, 'status' => 'notified']);
        Queue::assertNotPushed(PushRwgWaitlistRtuJob::class);
    }

    /** Seat transition pushes SEATED for a Google entry. */
    public function test_google_seat_pushes_seated(): void
    {
        Queue::fake();
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $e = $this->entry($r, 'google', null, 'notified');

        $this->actingAs($owner)->post(route('waitlist.seat', [$r->id, $e->id]))->assertRedirect();

        Queue::assertPushed(PushRwgWaitlistRtuJob::class, fn ($j) => $j->entryId === $e->id && $j->googleState === 'SEATED');
    }

    /** Dark: no RTU dispatched when RwG creds absent. */
    public function test_no_rtu_when_dark(): void
    {
        Queue::fake();
        config(['services.reserve_with_google.enabled' => false]);
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $e = $this->entry($r, 'google', null);

        $this->actingAs($owner)->post(route('waitlist.notify', [$r->id, $e->id]))->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $e->id, 'status' => 'notified']);
        Queue::assertNotPushed(PushRwgWaitlistRtuJob::class);
    }

    /** Job dark-guard: no client call when disabled; pushes when enabled. */
    public function test_rtu_job_dark_guard(): void
    {
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $e = $this->entry($r, 'google', null);

        $spy = new FakeRwgWaitlistRtuClient();
        $this->app->instance(RwgWaitlistRtuClient::class, $spy);

        config(['services.reserve_with_google.enabled' => false]);
        new PushRwgWaitlistRtuJob($e->id, 'SERVICE_READY')->handle();
        $this->assertCount(0, $spy->pushed);

        config(['services.reserve_with_google.enabled' => true]);
        new PushRwgWaitlistRtuJob($e->id, 'SERVICE_READY')->handle();
        $this->assertCount(1, $spy->pushed);
        $this->assertSame('SERVICE_READY', $spy->pushed[0]['state']);
    }
}
