<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\PosShift;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D2 — drawer handoff + the pos_drawer_open grant: the grant matrix, opening a
 * shift is gated on it, and a handoff closes+opens atomically with the counted
 * cash carried as the next float and the chain linked.
 */
class DrawerHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role, array $perms = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
            'permissions' => $perms,
        ]);
    }

    public function test_grant_matrix_defaults(): void
    {
        $r = $this->storeFixture();
        $this->assertTrue($this->staff($r, 'manager')->hasPermission('pos_drawer_open'));
        $this->assertTrue($this->staff($r, 'cashier')->hasPermission('pos_drawer_open'));
        $this->assertFalse($this->staff($r, 'waiter')->hasPermission('pos_drawer_open'));
        $this->assertFalse($this->staff($r, 'kitchen')->hasPermission('pos_drawer_open'));
    }

    public function test_opening_a_shift_requires_the_drawer_grant(): void
    {
        $r = $this->storeFixture();
        // A waiter has `pos` (passes context) but NOT pos_drawer_open.
        $waiter = $this->staff($r, 'waiter');

        $this->actingAs($waiter, 'staff')
            ->postJson(route('pos.shift.open', ['store' => $r->id]), ['opening_float' => 50])
            ->assertStatus(403);

        $this->assertSame(0, PosShift::where('store_id', $r->id)->count());
    }

    public function test_manager_opens_a_shift(): void
    {
        $r = $this->storeFixture();
        $m = $this->staff($r, 'manager');

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.shift.open', ['store' => $r->id]), ['opening_float' => 100])
            ->assertStatus(201);

        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
    }

    public function test_handoff_closes_and_opens_atomically_carrying_the_float(): void
    {
        $r = $this->storeFixture();
        $m = $this->staff($r, 'manager');
        $next = $this->staff($r, 'cashier');

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.shift.open', ['store' => $r->id]), ['opening_float' => 100])
            ->assertStatus(201);
        $first = PosShift::where('store_id', $r->id)->firstOrFail();

        $res = $this->actingAs($m, 'staff')
            ->postJson(route('pos.shift.handoff', ['store' => $r->id]), [
                'to_staff_id' => $next->id, 'counted_cash' => 137.50, 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(201);

        // The first shift is now closed with its counted cash recorded.
        $first->refresh();
        $this->assertSame('closed', $first->status);
        $this->assertEqualsWithDelta(137.50, (float) $first->counted_cash, 0.001);

        // A fresh shift is open for the next cashier, float = counted cash, chained.
        $new = PosShift::findOrFail($res->json('shift_id'));
        $this->assertSame('open', $new->status);
        $this->assertSame((int) $next->id, (int) $new->staff_id);
        $this->assertEqualsWithDelta(137.50, (float) $new->opening_float, 0.001);
        $this->assertSame((int) $first->id, (int) $new->handoff_from_shift_id);

        // Exactly one open shift on the drawer at any time.
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
    }

    public function test_handoff_is_idempotent_on_repeat_key(): void
    {
        $r = $this->storeFixture();
        $m = $this->staff($r, 'manager');
        $next = $this->staff($r, 'cashier');

        $this->actingAs($m, 'staff')
            ->postJson(route('pos.shift.open', ['store' => $r->id]), ['opening_float' => 100])
            ->assertStatus(201);

        $key = (string) Str::uuid();
        $body = ['to_staff_id' => $next->id, 'counted_cash' => 90, 'idempotency_key' => $key];
        $a = $this->actingAs($m, 'staff')->postJson(route('pos.shift.handoff', ['store' => $r->id]), $body)->assertStatus(201);
        $b = $this->actingAs($m, 'staff')->postJson(route('pos.shift.handoff', ['store' => $r->id]), $body)->assertStatus(201);

        $this->assertSame($a->json('shift_id'), $b->json('shift_id'));
        // One close, one open — the replay did not close the new shift too.
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'closed')->count());
    }

    public function test_handoff_requires_the_drawer_grant(): void
    {
        $r = $this->storeFixture();
        $m = $this->staff($r, 'manager');
        $next = $this->staff($r, 'cashier');
        // Open the shift as a granted manager first (direct, no HTTP user swap).
        PosService::openShift($r, $m, null, 100);

        // A waiter (has pos, lacks pos_drawer_open) cannot hand it over.
        $waiter = $this->staff($r, 'waiter');
        $this->actingAs($waiter, 'staff')
            ->postJson(route('pos.shift.handoff', ['store' => $r->id]), [
                'to_staff_id' => $next->id, 'counted_cash' => 90, 'idempotency_key' => (string) Str::uuid(),
            ])->assertStatus(403);

        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(0, PosShift::where('store_id', $r->id)->where('status', 'closed')->count());
    }
}
