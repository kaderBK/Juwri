<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T2-1: the register's customer search — staff-auth, tenant-scoped, returns
 * store-credit balance + loyalty points, and never leaks another tenant's diners.
 */
class PosCustomersTest extends TestCase
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

    private function storeFixture(?User $owner = null): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = $owner ?: User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$r, $staff];
    }

    public function test_search_returns_wallet_and_loyalty_and_matches_name(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = Customer::create(['name' => 'Alice Diner', 'store_id' => $r->id, 'phone' => '5551234']);
        CustomerWalletService::credit($c, 25.0, 'test', 'test:'.$c->id);
        LoyaltyAccount::forceCreate(['customer_id' => $c->id, 'store_id' => $r->id, 'balance' => 80, 'lifetime_earned' => 80, 'lifetime_redeemed' => 0]);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.customers', ['store' => $r->id, 'q' => 'Alice']))
            ->assertOk()
            ->assertJsonPath('0.id', $c->id)
            ->assertJsonPath('0.wallet', 25)
            ->assertJsonPath('0.loyalty_points', 80);
    }

    public function test_search_matches_phone(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = Customer::create(['name' => 'Bob', 'store_id' => $r->id, 'phone' => '5559999']);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.customers', ['store' => $r->id, 'q' => '9999']))
            ->assertOk()
            ->assertJsonPath('0.id', $c->id);
    }

    public function test_inline_create_persists_and_returns_row(): void
    {
        [$r, $staff] = $this->storeFixture();

        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.customers.store', ['store' => $r->id]), ['name' => 'New Diner', 'phone' => '5550001'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'New Diner')
            ->assertJsonPath('wallet', 0)
            ->assertJsonPath('loyalty_points', 0);

        $this->assertDatabaseHas('customers', [
            'id' => $res->json('id'), 'store_id' => $r->id, 'phone' => '5550001', 'source' => 'pos',
        ]);
    }

    public function test_inline_create_rejects_duplicate_phone_per_restaurant(): void
    {
        [$r, $staff] = $this->storeFixture();
        Customer::create(['name' => 'First', 'store_id' => $r->id, 'phone' => '5550002']);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.customers.store', ['store' => $r->id]), ['name' => 'Dup', 'phone' => '5550002'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_same_phone_allowed_across_tenants(): void
    {
        [$rA, $staffA] = $this->storeFixture();
        Customer::create(['name' => 'A', 'store_id' => $rA->id, 'phone' => '5550003']);
        [$rB, $staffB] = $this->storeFixture();

        // Same phone is fine under a different restaurant (uniqueness is per tenant).
        $this->actingAs($staffB, 'staff')
            ->postJson(route('pos.customers.store', ['store' => $rB->id]), ['name' => 'B', 'phone' => '5550003'])
            ->assertStatus(201);
    }

    public function test_search_is_tenant_isolated(): void
    {
        [$rA, $staffA] = $this->storeFixture();
        Customer::create(['name' => 'Aonly', 'store_id' => $rA->id, 'phone' => '111']);
        [$rB, $staffB] = $this->storeFixture();

        $this->actingAs($staffB, 'staff')
            ->getJson(route('pos.customers', ['store' => $rB->id, 'q' => 'Aonly']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_update_edits_customer_and_enforces_tenant_and_dup_phone(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = Customer::create(['name' => 'Old Name', 'store_id' => $r->id, 'phone' => '5551000']);
        Customer::create(['name' => 'Other', 'store_id' => $r->id, 'phone' => '5551001']);

        // Edit name+phone.
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.customers.update', ['id' => $c->id, 'store' => $r->id]),
                ['name' => 'New Name', 'phone' => '5551002'])
            ->assertOk()->assertJsonPath('name', 'New Name');
        $this->assertSame('5551002', $c->fresh()->phone);

        // Keeping own phone is fine (ignore self in the unique rule).
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.customers.update', ['id' => $c->id, 'store' => $r->id]),
                ['name' => 'New Name', 'phone' => '5551002'])
            ->assertOk();

        // Taking another customer's phone → 422.
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.customers.update', ['id' => $c->id, 'store' => $r->id]),
                ['name' => 'New Name', 'phone' => '5551001'])
            ->assertStatus(422);

    }

    public function test_update_is_tenant_isolated(): void
    {
        [$rA] = $this->storeFixture();
        $c = Customer::create(['name' => 'Victim', 'store_id' => $rA->id, 'phone' => '5553000']);
        [$rB, $staffB] = $this->storeFixture();

        $this->actingAs($staffB, 'staff')
            ->postJson(route('pos.customers.update', ['id' => $c->id, 'store' => $rB->id]),
                ['name' => 'Hijack'])
            ->assertStatus(404);
        $this->assertSame('Victim', $c->fresh()->name);
    }

    public function test_customer_orders_history_is_scoped(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = Customer::create(['name' => 'Hist', 'store_id' => $r->id, 'phone' => '5552000']);
        $mine = new Order;
        $mine->forceFill(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new', 'is_paid' => 1, 'source' => 'pos', 'customer_id' => $c->id, 'created_at' => now()])->save();
        $other = new Order; // same restaurant, different customer
        $other->forceFill(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new', 'is_paid' => 0, 'source' => 'pos', 'created_at' => now()])->save();

        $res = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.customers.orders', ['id' => $c->id, 'store' => $r->id]))
            ->assertOk();

        $this->assertSame([$mine->id], collect($res->json())->pluck('id')->all());
    }
}
