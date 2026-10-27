<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane D (D3): Reserve-with-Google waitlist booking server.
 */
class RwgWaitlistBookingServerTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'g-user';
    private const PASS = 'g-pass';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        config([
            'services.reserve_with_google.enabled'    => true,
            'services.reserve_with_google.basic_user' => self::USER,
            'services.reserve_with_google.basic_pass' => self::PASS,
        ]);

        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    private function makeStore(bool $waitlistOn = true): Store
    {
        $plan = TestPlans::create([
            'name' => 'Plan ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Restaurant ' . Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($waitlistOn) {
            StoreSettings::set($store->id, 'rwg_waitlist_enabled', '1');
        }
        return $store;
    }

    /** @return array<string,string> */
    private function auth(string $user = self::USER, string $pass = self::PASS): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("$user:$pass")];
    }

    private function merchant(Store $store): string
    {
        return 'store-' . $store->id;
    }

    // ── auth / dark ──────────────────────────────────────────────────────────

    public function test_missing_credentials_is_401(): void
    {
        $store = $this->makeStore();
        $this->postJson('/rwg/v3/BatchGetWaitEstimates', ['merchant_id' => $this->merchant($store), 'party_size' => [2]])
            ->assertStatus(401);
    }

    public function test_wrong_credentials_is_401(): void
    {
        $store = $this->makeStore();
        $this->withHeaders($this->auth('x', 'y'))
            ->postJson('/rwg/v3/BatchGetWaitEstimates', ['merchant_id' => $this->merchant($store), 'party_size' => [2]])
            ->assertStatus(401);
    }

    public function test_dark_when_disabled_is_503(): void
    {
        config(['services.reserve_with_google.enabled' => false]);
        $store = $this->makeStore();
        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/BatchGetWaitEstimates', ['merchant_id' => $this->merchant($store), 'party_size' => [2]])
            ->assertStatus(503);
    }

    // ── methods ────────────────────────────────────────────────────────────

    public function test_batch_get_wait_estimates_shape(): void
    {
        $store = $this->makeStore();
        WaitlistEntry::create(['store_id' => $store->id, 'name' => 'A', 'party_size' => 2, 'status' => 'waiting', 'quoted_wait' => 20]);

        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/BatchGetWaitEstimates', ['merchant_id' => $this->merchant($store), 'service_id' => 'waitlist', 'party_size' => [2, 4]])
            ->assertOk()
            ->assertJsonPath('waitlist_status', 'OPEN')
            ->assertJsonPath('wait_estimate.0.party_size', 2)
            ->assertJsonPath('wait_estimate.0.wait_length.parties_ahead_count', 1);
    }

    public function test_unknown_merchant_is_404(): void
    {
        $this->makeStore();
        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/BatchGetWaitEstimates', ['merchant_id' => 'store-999999', 'party_size' => [2]])
            ->assertStatus(404);
    }

    public function test_batch_estimates_404_when_waitlist_disabled(): void
    {
        // F2: a store that never opted its RwG waitlist in must not advertise estimates.
        $store = $this->makeStore(false);
        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/BatchGetWaitEstimates', ['merchant_id' => $this->merchant($store), 'party_size' => [2]])
            ->assertStatus(404);
    }

    public function test_create_rejects_oversized_idempotency_token(): void
    {
        // M3: an over-64-char token must be a clean 400, never a data-too-long 500.
        $store = $this->makeStore();
        $this->withHeaders($this->auth())->postJson('/rwg/v3/CreateWaitlistEntry', [
            'idempotency_token' => str_repeat('x', 65), 'merchant_id' => $this->merchant($store),
            'party_size' => 2, 'user_information' => ['given_name' => 'A'],
        ])->assertStatus(400);
    }

    public function test_create_waitlist_entry_persists_google_sourced(): void
    {
        $store = $this->makeStore();

        $res = $this->withHeaders($this->auth())->postJson('/rwg/v3/CreateWaitlistEntry', [
            'idempotency_token' => 'tok-abc-123',
            'merchant_id'       => $this->merchant($store),
            'service_id'        => 'waitlist',
            'party_size'        => 3,
            'user_information'  => ['given_name' => 'Sam', 'family_name' => 'Lee', 'telephone' => '+16505550123'],
        ]);

        $res->assertOk()->assertJsonPath('waitlist_entry_id', 'tok-abc-123');
        $this->assertDatabaseHas('waitlist_entries', [
            'store_id' => $store->id, 'rwg_entry_id' => 'tok-abc-123',
            'source' => 'google', 'status' => 'waiting', 'name' => 'Sam Lee',
            'phone' => '+16505550123', 'party_size' => 3,
        ]);
        // quoted_wait computed like add(): position 1 × 20.
        $this->assertSame(20, (int) WaitlistEntry::where('rwg_entry_id', 'tok-abc-123')->value('quoted_wait'));
    }

    public function test_create_is_idempotent_on_retry(): void
    {
        $store = $this->makeStore();
        $payload = [
            'idempotency_token' => 'tok-dup', 'merchant_id' => $this->merchant($store),
            'party_size' => 2, 'user_information' => ['given_name' => 'A', 'family_name' => 'B'],
        ];

        $first  = $this->withHeaders($this->auth())->postJson('/rwg/v3/CreateWaitlistEntry', $payload);
        $second = $this->withHeaders($this->auth())->postJson('/rwg/v3/CreateWaitlistEntry', $payload);

        $first->assertOk()->assertJsonPath('waitlist_entry_id', 'tok-dup');
        $second->assertOk()->assertJsonPath('waitlist_entry_id', 'tok-dup');
        $this->assertSame(1, WaitlistEntry::where('rwg_entry_id', 'tok-dup')->count());
    }

    public function test_create_rejected_when_waitlist_closed(): void
    {
        $store = $this->makeStore(waitlistOn: false);

        $this->withHeaders($this->auth())->postJson('/rwg/v3/CreateWaitlistEntry', [
            'idempotency_token' => 'tok-closed', 'merchant_id' => $this->merchant($store),
            'party_size' => 2, 'user_information' => ['given_name' => 'A'],
        ])->assertOk()->assertJsonPath('waitlist_business_logic_failure.cause', 'WAITLIST_CLOSED');

        $this->assertDatabaseMissing('waitlist_entries', ['rwg_entry_id' => 'tok-closed']);
    }

    public function test_create_rejected_above_max_party(): void
    {
        $store = $this->makeStore();
        StoreSettings::set($store->id, 'waitlist_max_party', '6');

        $this->withHeaders($this->auth())->postJson('/rwg/v3/CreateWaitlistEntry', [
            'idempotency_token' => 'tok-big', 'merchant_id' => $this->merchant($store),
            'party_size' => 10, 'user_information' => ['given_name' => 'A'],
        ])->assertOk()->assertJsonPath('waitlist_business_logic_failure.cause', 'ABOVE_MAX_PARTY_SIZE');
    }

    public function test_get_waitlist_entry_returns_state(): void
    {
        $store = $this->makeStore();
        WaitlistEntry::create(['store_id' => $store->id, 'rwg_entry_id' => 'tok-get', 'name' => 'G', 'party_size' => 2, 'status' => 'waiting', 'source' => 'google', 'quoted_wait' => 20]);

        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/GetWaitlistEntry', ['waitlist_entry_id' => 'tok-get'])
            ->assertOk()
            ->assertJsonPath('waitlist_entry.waitlist_entry_state', 'WAITING');
    }

    public function test_get_unknown_entry_is_404(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/GetWaitlistEntry', ['waitlist_entry_id' => 'nope'])
            ->assertStatus(404);
    }

    public function test_delete_waitlist_entry_sets_left(): void
    {
        $store = $this->makeStore();
        WaitlistEntry::create(['store_id' => $store->id, 'rwg_entry_id' => 'tok-del', 'name' => 'G', 'party_size' => 2, 'status' => 'waiting', 'source' => 'google', 'quoted_wait' => 20]);

        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/DeleteWaitlistEntry', ['waitlist_entry_id' => 'tok-del'])
            ->assertOk();

        $this->assertDatabaseHas('waitlist_entries', ['rwg_entry_id' => 'tok-del', 'status' => 'left']);
    }

    public function test_delete_unknown_entry_is_idempotent_200(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/rwg/v3/DeleteWaitlistEntry', ['waitlist_entry_id' => 'ghost'])
            ->assertOk();
    }
}
