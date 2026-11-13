<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Dashboard driver management: page renders, create issues a one-time token. */
class DashboardDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_create_driver_flashes_one_time_token(): void
    {
        $owner = $this->owner();

        $res = $this->actingAs($owner)
            ->post(route('drivers.store'), ['name' => 'Alex', 'phone' => '+15550001111'])
            ->assertRedirectContains('new_driver_token=');

        $this->assertDatabaseHas('drivers', ['owner_id' => $owner->id, 'name' => 'Alex']);

        // The stored token is the hash of the raw token the drivers page shows once.
        preg_match('#<code class="ui-drivers__token ui-ltr">([^<]+)</code>#', (string) $this->actingAs($owner)->get((string) $res->headers->get('Location'))->getContent(), $m);
        $raw = $m[1] ?? '';
        $this->assertSame(48, strlen($raw));
        $this->assertDatabaseHas('drivers', ['token' => hash('sha256', $raw)]);
    }

    public function test_rotate_and_toggle_and_delete(): void
    {
        $owner = $this->owner();
        $raw = Str::random(48);
        $driver = Driver::create(['owner_id' => $owner->id, 'name' => 'A', 'token' => hash('sha256', $raw), 'active' => true]);

        $this->actingAs($owner)->post(route('drivers.rotate', $driver->id))->assertRedirectContains('new_driver_token='.$driver->id);
        $this->assertNotSame(hash('sha256', $raw), $driver->fresh()->token); // old token dead

        $this->actingAs($owner)->post(route('drivers.toggle', $driver->id));
        $this->assertFalse($driver->fresh()->active);

        $this->actingAs($owner)->delete(route('drivers.destroy', $driver->id));
        $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
    }

    public function test_cannot_manage_another_owners_driver(): void
    {
        $mine = $this->owner();
        $other = $this->owner();
        $raw = Str::random(48);
        $foreign = Driver::create(['owner_id' => $other->id, 'name' => 'X', 'token' => hash('sha256', $raw), 'active' => true]);

        $this->actingAs($mine)->delete(route('drivers.destroy', $foreign->id))->assertNotFound();
    }
}
