<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Creating a restaurant auto-creates exactly one default branch */
    public function test_new_restaurant_gets_default_branch(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->assertEquals(1, Branch::where('store_id', $r->id)->count());
        $this->assertTrue(Branch::where('store_id', $r->id)->where('name', 'like', '%Main%')->exists());
    }

    public function test_branch_crud_and_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('branches.store'), [
            'store_id' => $r->id, 'name' => 'Downtown', 'phone' => '555',
        ])->assertRedirect();
        $branch = Branch::where('store_id', $r->id)->where('name', 'Downtown')->first();
        $this->assertNotNull($branch);

        $this->actingAs($owner)->put(route('branches.update', $branch->id), [
            'name' => 'Downtown 2', 'active' => '1',
        ])->assertRedirect();
        $this->assertEquals('Downtown 2', $branch->fresh()->name);

        // Intruder blocked
        $intruder = $this->makeUser();
        $this->actingAs($intruder)->put(route('branches.update', $branch->id), ['name' => 'x'])->assertNotFound();

        // Delete
        $this->actingAs($owner)->delete(route('branches.destroy', $branch->id))->assertRedirect();
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    /** The final remaining branch cannot be deleted */
    public function test_cannot_delete_last_branch(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $only = Branch::where('store_id', $r->id)->first();

        $this->actingAs($owner)->delete(route('branches.destroy', $only->id))->assertRedirect();
        $this->assertDatabaseHas('branches', ['id' => $only->id]);
    }

    /** Order → branch relation resolves */
    public function test_order_branch_relation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();

        $order = Order::create([
            'store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe',
            'status' => 'pending', 'branch_id' => $branch->id, 'created_at' => now(),
        ]);

        $this->assertEquals($branch->id, $order->branch->id);
        $this->assertTrue($branch->orders->contains($order->id));
    }
}
