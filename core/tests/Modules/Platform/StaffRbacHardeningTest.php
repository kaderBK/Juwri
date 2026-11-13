<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * RBAC hardening — function-level authorization holes reachable because a staff
 * dashboard session logs the web guard in AS THE OWNER (StaffLoginController). The
 * EnsureStaffDashboardPermission gate (default-deny) is kept ON — it is under test.
 *
 * Covers: paid-order deletion (a cash-out sibling of refund/void that was gated only
 * by 'orders'), owner-money store purchases + partner-secret rotation on ungated
 * `auth` routes, and cross-branch KDS bump on the staff app.
 */
class StaffRbacHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // EnsureStaffDashboardPermission is deliberately NOT disabled — it is the gate under test.
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function staff(Store $store, string $role, array $extra = []): StaffMember
    {
        return StaffMember::create(array_merge([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ], $extra));
    }

    /** Staff dashboard session: web guard = owner, staff guard = staff, staff_session_id set. */
    private function asStaff(User $owner, StaffMember $staff): self
    {
        // The web guard is set LAST so it stays the default guard, as in a real staff dashboard session (the
        // owner is the request user); the reverse order only worked while staff id == owner id.
        return $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
            ->withSession(['staff_session_id' => $staff->id]);
    }

    private function order(Store $store, bool $paid, ?int $branchId = null): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 20, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'branch_id' => $branchId, 'type' => 'takeaway',
            'status' => 'pending', 'is_paid' => $paid ? 1 : 0, 'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'status' => 'queued']);

        return $order->fresh();
    }

    // ── deleteOrder: paid-order deletion is a refund-grade money action ───────────

    public function test_cashier_cannot_delete_a_paid_order(): void
    {
        [$owner, $store] = $this->store();
        $order = $this->order($store, paid: true);

        $this->asStaff($owner, $this->staff($store, 'cashier'))
            ->postJson(route('stores.deleteOrder', $order->id))
            ->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $order->id]); // the paid sale survives
    }

    public function test_cashier_can_still_delete_an_unpaid_order(): void
    {
        [$owner, $store] = $this->store();
        $order = $this->order($store, paid: false);

        $this->asStaff($owner, $this->staff($store, 'cashier'))
            ->postJson(route('stores.deleteOrder', $order->id))
            ->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_manager_can_delete_a_paid_order(): void
    {
        [$owner, $store] = $this->store();
        $order = $this->order($store, paid: true);

        $this->asStaff($owner, $this->staff($store, 'manager')) // manager holds pos_refund
            ->postJson(route('stores.deleteOrder', $order->id))
            ->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_owner_can_delete_a_paid_order(): void
    {
        [$owner, $store] = $this->store();
        $order = $this->order($store, paid: true);

        $this->actingAs($owner, 'web') // no staff session → dashboard_allows() bypasses
            ->postJson(route('stores.deleteOrder', $order->id))
            ->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    // ── owner-money / owner-account routes outside the main dashboard group ───────

    public function test_staff_cannot_spend_the_owners_money_on_a_store_purchase(): void
    {
        [$owner, $store] = $this->store();

        // kitchen = the LEAST-privileged role; still logs the web guard in as owner.
        $this->asStaff($owner, $this->staff($store, 'kitchen'))
            ->postJson(route('user.store.buy.app.card', 1), ['payment_method' => 1])
            ->assertStatus(403);
    }

    public function test_staff_cannot_rotate_the_owners_partner_app_secret(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [$owner, $store] = $this->store();

        $this->asStaff($owner, $this->staff($store, 'kitchen'))
            ->postJson(route('partner.apps.rotate-secret', 1))
            ->assertStatus(403);
    }

    // ── cross-branch KDS bump (staff app, guard 'staff') ─────────────────────────

    public function test_kitchen_staff_cannot_bump_another_branchs_ticket(): void
    {
        [$owner, $store] = $this->store();
        $branchA = Branch::create(['store_id' => $store->id, 'name' => 'A']); // first → default
        $branchB = Branch::create(['store_id' => $store->id, 'name' => 'B']);

        // Cook pinned to branch B; a ticket sits in branch A.
        $cook = $this->staff($store, 'kitchen', ['branch_id' => $branchB->id]);
        $orderA = $this->order($store, paid: false, branchId: $branchA->id);
        $itemA = $orderA->items->first();

        $this->actingAs($cook, 'staff')
            ->postJson(route('staffapp.kds.bump', $itemA->id))
            ->assertStatus(403);

        $this->assertSame('queued', $itemA->fresh()->status, 'the other branch ticket is untouched');
    }

    public function test_kitchen_staff_can_bump_their_own_branchs_ticket(): void
    {
        [$owner, $store] = $this->store();
        $branchA = Branch::create(['store_id' => $store->id, 'name' => 'A']);
        $branchB = Branch::create(['store_id' => $store->id, 'name' => 'B']);

        $cook = $this->staff($store, 'kitchen', ['branch_id' => $branchB->id]);
        $orderB = $this->order($store, paid: false, branchId: $branchB->id);
        $itemB = $orderB->items->first();

        $this->actingAs($cook, 'staff')
            ->postJson(route('staffapp.kds.bump', $itemB->id))
            ->assertStatus(200);

        $this->assertNotSame('queued', $itemB->fresh()->status, 'own-branch ticket advanced');
    }
}
