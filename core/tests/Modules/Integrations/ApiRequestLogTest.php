<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiRequestLog;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS E11 — API request logs (middleware + prune + viewer) and v1 push-register
 * for Bearer clients.
 */
class ApiRequestLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function ownerToken(User $owner): array
    {
        $raw = Str::random(40);
        $t = ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'restaurants:read']]);

        return [$raw, $t];
    }

    public function test_owner_api_request_is_logged(): void
    {
        $owner = $this->owner();
        [$raw, $token] = $this->ownerToken($owner);

        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$raw])->assertOk();

        $log = ApiRequestLog::first();
        $this->assertNotNull($log);
        $this->assertSame($token->id, (int) $log->token_id);
        $this->assertSame('GET', $log->method);
        $this->assertStringContainsString('api/v1/stores', $log->path);
        $this->assertSame(200, (int) $log->status);
        $this->assertNotNull($log->duration_ms);
    }

    public function test_prune_removes_logs_older_than_seven_days(): void
    {
        ApiRequestLog::create(['token_id' => 1, 'method' => 'GET', 'path' => 'old', 'status' => 200, 'created_at' => now()->subDays(10)]);
        ApiRequestLog::create(['token_id' => 1, 'method' => 'GET', 'path' => 'recent', 'status' => 200, 'created_at' => now()->subDay()]);

        $this->artisan('api:prune-request-logs')->assertExitCode(0);

        $this->assertDatabaseMissing('api_request_logs', ['path' => 'old']);
        $this->assertDatabaseHas('api_request_logs', ['path' => 'recent']);
    }

    public function test_viewer_renders_and_filters(): void
    {
        $owner = $this->owner();
        [, $token] = $this->ownerToken($owner);
        ApiRequestLog::create(['token_id' => $token->id, 'method' => 'GET', 'path' => 'api/v1/stores', 'status' => 200, 'created_at' => now()]);

        $html = $this->actingAs($owner)->withoutMiddleware()->get(route('api.requestLogs'))->assertOk()->getContent();
        $this->assertStringContainsString('api/v1/stores', $html);
    }

    // ── v1 push-register (Bearer) ─────────────────────────────────────────────

    private function makeStaff(User $owner, Store $store): StaffMember
    {
        return StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'S', 'role' => 'manager', 'email' => 'st'.Str::random(5).'@x.com', 'password' => Hash::make('x'), 'active' => 1]);
    }

    private function store(User $owner): Store
    {
        $t = 'S'.Str::random(5);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1']);
    }

    public function test_staff_can_register_and_unregister_device_via_bearer(): void
    {
        config(['settings.admin_allow_mobile_app_push' => true]);
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->makeStaff($owner, $store);
        $raw = StaffToken::issue($staff);

        $this->postJson('/api/v1/staff/push/register-device', ['token' => 'device-abc', 'platform' => 'fcm'], ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', ['store_id' => $store->id, 'staff_id' => $staff->id]);

        $this->postJson('/api/v1/staff/push/unregister-device', ['token' => 'device-abc'], ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->assertJson(['ok' => true, 'removed' => true]);

        $this->assertSame(0, PushSubscription::where('store_id', $store->id)->count());
    }
}
