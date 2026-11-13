<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3: quick-switch lock unlock. Reuses the staff-PIN credential; a PIN swaps the
 * session staff actor. Adversarial: cross-restaurant PIN, non-POS staff, wrong PIN
 * throttle, owner password path.
 */
class PosLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(?User $owner = null, string $ownerPw = 'secret123'): Store
    {
        if (! $owner) {
            $plan = TestPlans::create([
                'name' => 'P '.Str::random(5), 'description' => 'T',
                'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
                'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
                'status' => 1,
            ]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'password' => Hash::make($ownerPw)]);
        }
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $pin, string $role = 'cashier', bool $active = true): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => $active ? 1 : 0, 'pin' => $pin,
        ]);
    }

    public function test_valid_pin_swaps_staff_actor(): void
    {
        $r = $this->storeFixture();
        $onDuty = $this->staff($r, '1111');
        $incoming = $this->staff($r, '2222');

        $this->actingAs($onDuty, 'staff')
            ->postJson(route('pos.unlock', ['store' => $r->id]), ['pin' => '2222'])
            ->assertOk()
            ->assertJsonPath('actor.name', $incoming->name);

        // Session actor is now the incoming staff.
        $this->assertSame($incoming->id, auth('staff')->id());
    }

    public function test_pin_scoped_to_register_restaurant(): void
    {
        $r = $this->storeFixture();
        $r2 = $this->storeFixture();
        $onDuty = $this->staff($r, '1111');
        $this->staff($r2, '9999'); // a PIN that exists only at another restaurant

        $this->actingAs($onDuty, 'staff')
            ->postJson(route('pos.unlock', ['store' => $r->id]), ['pin' => '9999'])
            ->assertStatus(401);
    }

    public function test_non_pos_staff_pin_rejected(): void
    {
        $r = $this->storeFixture();
        $onDuty = $this->staff($r, '1111');
        // 'kitchen' role → no pos permission (host role map). Give it a PIN.
        $this->staff($r, '3333', 'kitchen');

        $this->actingAs($onDuty, 'staff')
            ->postJson(route('pos.unlock', ['store' => $r->id]), ['pin' => '3333'])
            ->assertStatus(401);
    }

    public function test_wrong_pin_throttled(): void
    {
        $r = $this->storeFixture();
        $onDuty = $this->staff($r, '1111');

        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->actingAs($onDuty, 'staff')
                ->postJson(route('pos.unlock', ['store' => $r->id]), ['pin' => '0000'])
                ->getStatusCode();
        }
        $this->assertContains(429, $statuses); // throttle:10,1 trips
    }

    public function test_owner_unlocks_with_password(): void
    {
        $r = $this->storeFixture(ownerPw: 'secret123');

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.unlock', ['store' => $r->id]), ['password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('actor.role', 'owner');

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.unlock', ['store' => $r->id]), ['password' => 'wrong'])
            ->assertStatus(401);
    }
}
