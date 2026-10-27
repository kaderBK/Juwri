<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * H7 — cross-tenant isolation probes. Owner A must never read or mutate owner B's
 * resources by guessing an id. Controller-level owner scoping (owner-filtered
 * findOrFail / explicit user_id checks) is the enforcement; these probes prove it
 * holds on the highest-risk by-id routes and pin it so it cannot silently regress.
 */
class ProbeCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    /** A full tenant: user + restaurant + branch + staff + order. */
    private function tenant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = Branch::create(['store_id' => $r->id, 'name' => 'Main', 'position' => 0]);
        $staff = StaffMember::create([
            'owner_id' => $user->id, 'store_id' => $r->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'customer_name' => 'J', 'status' => 'pending',
            'track_token' => Str::random(32), 'created_at' => now(),
        ]);

        return compact('user', 'r', 'branch', 'staff', 'order');
    }

    public function test_owner_cannot_change_another_owners_order_status(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->post(route('stores.updateOrderStatus', $b['order']->id), ['status' => 'completed'])
            ->assertStatus(403);

        // The victim's order is untouched.
        $this->assertSame('pending', $b['order']->fresh()->status);
    }

    public function test_owner_cannot_update_or_delete_another_owners_branch(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->put(route('branches.update', $b['branch']->id), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->actingAs($a['user'])
            ->delete(route('branches.destroy', $b['branch']->id))
            ->assertNotFound();

        $this->assertDatabaseHas('branches', ['id' => $b['branch']->id, 'name' => 'Main']);
    }

    public function test_owner_cannot_update_or_delete_another_owners_staff(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->put(route('staff.update', $b['staff']->id), ['name' => 'X', 'role' => 'manager'])
            ->assertNotFound();

        $this->actingAs($a['user'])
            ->delete(route('staff.destroy', $b['staff']->id))
            ->assertNotFound();

        $this->assertDatabaseHas('staff_members', ['id' => $b['staff']->id]);
    }

    public function test_owner_cannot_open_another_owners_split_bill(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->get(route('orders.split.show', $b['order']->id))
            ->assertNotFound();
    }
}
