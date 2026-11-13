<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The plan restaurant limit must be enforced on the store() POST that inserts,
 * not only on the create() GET form. A capped plan that is already at its limit
 * cannot create another restaurant by POSTing straight to restaurants.store.
 */
class StoreCreateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function userOnCappedPlan(int $limit): User
    {
        $plan = TestPlans::create([
            'name' => 'Capped', 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 200,
            'settings' => (object) ['restaurant_limit' => $limit], 'status' => 1,
        ]);

        return User::factory()->create([
            'status' => 1, 'email_verified_at' => now(), 'group_id' => (string) $plan->id,
        ]);
    }

    public function test_store_refuses_a_new_restaurant_at_the_plan_limit(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $user = $this->userOnCappedPlan(1);
        Store::factory()->create(['user_id' => $user->id]); // already at the cap of 1

        $res = $this->actingAs($user)->post(route('stores.store'), [
            'color' => '#111111', 'title' => 'Second', 'description' => 'x', 'address' => 'x',
            'store_template' => 'default',
        ]);

        // The limit gate runs BEFORE validation and flags spotlight_limit; without
        // the fix the request would instead fall through to (image) validation and
        // never set this — so this assertion pins the gate specifically.
        $res->assertSessionHas('spotlight_limit');
        $this->assertSame(1, Store::where('user_id', $user->id)->count(),
            'A capped plan created a second restaurant by POSTing directly to store().');
    }

    public function test_store_gate_does_not_block_below_the_limit(): void
    {
        $user = $this->userOnCappedPlan(3);
        Store::factory()->create(['user_id' => $user->id]); // 1 of 3

        // Below the cap: the request passes the limit gate and only fails later on
        // the missing required images — so the limit gate itself did NOT block it.
        $res = $this->actingAs($user)->from('/create')->post(route('stores.store'), [
            'color' => '#111111', 'title' => 'Second', 'description' => 'x', 'address' => 'x',
            'store_template' => 'default',
        ]);

        $res->assertSessionMissing('spotlight_limit');
    }
}
