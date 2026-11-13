<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Two owner-dashboard endpoints resolved a row by a globally-unique natural key / a
 * client-supplied store_id without fencing it to the caller's tenant:
 *  - PhoneAgentController::save keyed a PhoneLine upsert on (provider, number) alone, so a
 *    store could steal another store's registered number.
 *  - SettingsController::testIntegration trusted store_id, substituting the victim's
 *    stored secret into a live billed call (a cred-validity oracle + quota burn).
 */
class OwnerCrossTenantUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
            AddDashboardCspHeaders::class,
            EnsureStaffDashboardPermission::class,
            IsDemoMode::class,
            EnsureFeatureEnabled::class,
        ]);
    }

    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    public function test_owner_cannot_steal_another_stores_phone_line(): void
    {
        [$ownerA, $postA] = $this->owner();
        [, $postB] = $this->owner();

        $line = PhoneLine::create([
            'store_id' => $postB->id, 'provider' => 'twilio',
            'number' => '+15551234567', 'greeting' => 'B greeting', 'active' => 1,
        ]);

        $this->actingAs($ownerA)->post(route('stores.phoneagent.save'), [
            'store_id' => $postA->id, // attacker's own store (passes the 403 gate)
            'provider' => 'twilio',
            'number' => '+15551234567',     // victim B's registered number
            'greeting' => 'stolen',
        ]);

        $fresh = $line->fresh();
        $this->assertSame((int) $postB->id, (int) $fresh->store_id, 'line must stay with store B');
        $this->assertSame('B greeting', $fresh->greeting, 'victim config must be untouched');
    }

    public function test_owner_cannot_test_another_stores_integration_secret(): void
    {
        Http::fake(); // any live provider call would be caught here

        [$ownerA] = $this->owner();
        [, $postB] = $this->owner();

        Credential::create([
            'store_id' => $postB->id, 'feature' => 'ai', 'provider' => 'openai',
            'secrets' => ['api_key' => 'sk-victim-secret'], 'active' => 1,
        ]);

        $this->actingAs($ownerA)->postJson(route('settings.integrations.test'), [
            'store_id' => $postB->id, // victim
            'feature' => 'ai',
            'provider' => 'openai',
            'secrets' => ['api_key' => '••••'], // masked → would substitute B's real key
        ])->assertNotFound();

        Http::assertNothingSent();
    }
}
