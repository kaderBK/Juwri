<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\StaffMember;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\TestCase;

/**
 * A8 (integrations pack): staff busy-mode + product snooze for Deliverect, over the
 * StaffTokenAuth API, gated on aggregator_deliverect.
 */
class DeliverectStaffApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Store, 2: AggregatorConnection} */
    private function setup_staff(): array
    {
        config(['settings.admin_allow_aggregator_deliverect' => true]);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'S',
            'role' => 'manager', 'email' => 's'.Str::random(5).'@ex.com', 'password' => Hash::make('x'), 'active' => 1,
        ]);
        $raw = Str::random(48);
        StaffToken::create(['staff_id' => $staff->id, 'token' => hash('sha256', $raw), 'name' => 'pwa']);
        $conn = AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $store->id, 'external_store_id' => 'CHLINK-1', 'status' => 'active',
        ]);

        return [$raw, $store, $conn];
    }

    public function test_busy_toggle_persists_and_reads_back(): void
    {
        [$raw, , $conn] = $this->setup_staff();
        $h = ['Authorization' => 'Bearer '.$raw];

        $this->postJson('/api/v1/staff/deliverect/busy', ['busy' => true], $h)
            ->assertOk()->assertJsonPath('busy', true);
        $this->assertTrue($conn->fresh()->settings['busy']);

        $this->getJson('/api/v1/staff/deliverect/status', $h)->assertOk()->assertJsonPath('busy', true);

        $this->postJson('/api/v1/staff/deliverect/busy', ['busy' => false], $h)->assertOk()->assertJsonPath('busy', false);
        $this->assertFalse($conn->fresh()->settings['busy']);
    }

    public function test_snooze_toggles_a_plu(): void
    {
        [$raw, , $conn] = $this->setup_staff();
        $h = ['Authorization' => 'Bearer '.$raw];

        $this->postJson('/api/v1/staff/deliverect/snooze', ['menu_id' => 42, 'snoozed' => true], $h)
            ->assertOk()->assertJsonPath('snoozed', true);
        $this->assertTrue($conn->fresh()->settings['snoozed_plus']['42']);

        // PHP casts the numeric string PLU key back to int in array_keys.
        $this->getJson('/api/v1/staff/deliverect/status', $h)->assertOk()->assertJsonPath('snoozed_plus', [42]);

        // menu_id + snoozed are required.
        $this->postJson('/api/v1/staff/deliverect/snooze', ['menu_id' => 42], $h)->assertStatus(422);
    }

    public function test_requires_a_staff_token(): void
    {
        $this->setup_staff();
        $this->getJson('/api/v1/staff/deliverect/status')->assertStatus(401);
    }

    public function test_feature_off_returns_404(): void
    {
        [$raw] = $this->setup_staff();
        config(['settings.admin_allow_aggregator_deliverect' => false]);
        FeatureAccess::flush();

        $this->getJson('/api/v1/staff/deliverect/status', ['Authorization' => 'Bearer '.$raw])->assertStatus(404);
    }
}
