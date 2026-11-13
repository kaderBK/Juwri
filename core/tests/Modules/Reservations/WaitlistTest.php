<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Tests\Support\TestPlans;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]),
            'status' => 1,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'group_id' => (string) $this->makePlan()->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Test',
            'description' => 'Test', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEntry(Store $store, string $status = 'waiting'): WaitlistEntry
    {
        return WaitlistEntry::create([
            'store_id' => $store->id,
            'name' => 'Guest '.Str::random(4),
            'party_size' => 2,
            'status' => $status,
            'quoted_wait' => 20,
        ]);
    }

    /** Add party to waitlist persists */
    public function test_add_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->post(route('waitlist.add', $store->id), [
                'name' => 'Smith Party',
                'party_size' => 4,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', [
            'store_id' => $store->id,
            'name' => 'Smith Party',
            'status' => 'waiting',
        ]);
    }

    /** Tenant isolation: cannot add to another owner's restaurant */
    public function test_add_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $other = $this->makeUser();

        $this->actingAs($other)
            ->post(route('waitlist.add', $store->id), [
                'name' => 'Attacker', 'party_size' => 1,
            ])
            ->assertNotFound(); // ownerRestaurant() → firstOrFail → 404
    }

    /** Notify transitions waiting → notified */
    public function test_notify_transitions_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, 'waiting');

        $this->actingAs($owner)
            ->post(route('waitlist.notify', [$store->id, $entry->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'notified']);
    }

    /** FP6 #32: notifying a party with a phone sends the "table ready" SMS (simulated, no creds). */
    public function test_notify_sends_table_ready_sms(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = WaitlistEntry::create([
            'store_id' => $store->id, 'name' => 'Ready Party', 'phone' => '+16505551234',
            'party_size' => 2, 'status' => 'waiting', 'quoted_wait' => 20,
        ]);

        $this->actingAs($owner)->post(route('waitlist.notify', [$store->id, $entry->id]))->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'notified']);
        $this->assertDatabaseHas('sms_logs', [
            'to' => '+16505551234', 'feature' => 'waitlist', 'status' => 'simulated',
        ]);
    }

    /** FP6 #32: an opted-out party can't be reached — leave them 'waiting' (not falsely notified), no SMS. */
    public function test_notify_optout_leaves_party_waiting(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        SmsOptout::create(['phone' => '+16505550000', 'store_id' => null, 'source' => 'reply']);
        $entry = WaitlistEntry::create([
            'store_id' => $store->id, 'name' => 'OptedOut Party', 'phone' => '+16505550000',
            'party_size' => 2, 'status' => 'waiting', 'quoted_wait' => 20,
        ]);

        $this->actingAs($owner)->post(route('waitlist.notify', [$store->id, $entry->id]))->assertRedirect();

        // No SMS attempted, and the party stays waiting so the host can retry / call the name.
        $this->assertDatabaseMissing('sms_logs', ['to' => '+16505550000', 'feature' => 'waitlist']);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'waiting']);
    }

    /** FP6: a party with no phone is still marked notified (host calls the name) — no SMS row. */
    public function test_notify_without_phone_marks_notified_without_sms(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, 'waiting'); // no phone

        $this->actingAs($owner)->post(route('waitlist.notify', [$store->id, $entry->id]))->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'notified']);
        $this->assertDatabaseMissing('sms_logs', ['feature' => 'waitlist']);
    }

    /** Seat transitions to seated */
    public function test_seat_transitions_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, 'notified');

        $this->actingAs($owner)
            ->post(route('waitlist.seat', [$store->id, $entry->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'seated']);
    }

    /** Leave transitions to left */
    public function test_leave_transitions_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, 'waiting');

        $this->actingAs($owner)
            ->post(route('waitlist.leave', [$store->id, $entry->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'left']);
    }

    /** Tenant isolation on status change: other owner gets 404 */
    public function test_seat_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store);

        $other = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($other);

        // Other owner uses their own restaurant ID but targets owner's entry
        $this->actingAs($other)
            ->post(route('waitlist.seat', [$otherRestaurant->id, $entry->id]))
            ->assertNotFound(); // BL-043: the owner tenant scope hides another account's entry (the controller's abort_if is the second line)
        $this->assertSame($entry->status, $entry->fresh()->status, 'the foreign entry is untouched');
    }

    /** A3: seat() refuses a terminal entry — a 'left' party cannot be flipped to 'seated'. */
    public function test_seat_rejects_a_terminal_entry(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, 'left'); // already terminal

        $this->actingAs($owner)
            ->post(route('waitlist.seat', [$store->id, $entry->id]))
            ->assertStatus(422);

        // Unchanged — no false seat.
        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'left']);
    }

    /** A3: leave() refuses a seated entry — a seated party cannot be re-marked 'left'. */
    public function test_leave_rejects_a_seated_entry(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, 'seated'); // already terminal

        $this->actingAs($owner)
            ->post(route('waitlist.leave', [$store->id, $entry->id]))
            ->assertStatus(422);

        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'seated']);
    }

    /** Public join creates waiting entry */
    public function test_public_join_creates_entry(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        // #80: public join is opt-in per store; without this the endpoint 404s.
        StoreSettings::set($store->id, 'enable_waitlist', '1');

        $this->post(route('publicView.waitlist.join', $store->slug), [
            'name' => 'Walk-In Guest',
            'party_size' => 2,
        ])
            ->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', [
            'store_id' => $store->id,
            'name' => 'Walk-In Guest',
            'status' => 'waiting',
        ]);
    }

    public function test_public_join_is_refused_when_the_store_has_not_opted_in(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        // No enable_waitlist option: the storefront renders no join form, so the
        // endpoint must not accept writes either.

        $this->post(route('publicView.waitlist.join', $store->slug), [
            'name' => 'Uninvited Guest',
            'party_size' => 2,
        ])
            ->assertNotFound();

        $this->assertDatabaseMissing('waitlist_entries', ['store_id' => $store->id]);
    }
}
