<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Tables\Services\TableSessionService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T3 — the POS approval queue for verification mode `staff_approve`.
 *
 * Approve hands a parked order to the KDS; reject cancels it. Neither may ever
 * touch an order that was not parked in the first place, and neither may cross a
 * tenant boundary.
 */
class PosApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_table_otp' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0:Store,1:StaffMember} */
    private function storeFixture(string $mode = TableSessionService::MODE_STAFF_APPROVE): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, TableSessionService::OPTION_MODE, $mode);
        FeatureAccess::flush();

        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'active' => 1,
        ]);

        return [$r, $staff];
    }

    private function parkedOrder(Store $r): Order
    {
        $o = new Order;
        $o->forceFill([
            'store_id' => $r->id, 'type' => 'on-table', 'table_number' => 4,
            'status' => Order::STATUS_PENDING_APPROVAL, 'is_paid' => 0,
            'source' => 'online', 'customer_name' => 'Guest', 'created_at' => now(),
        ])->save();

        return $o;
    }

    public function test_the_queue_lists_parked_orders_and_reports_the_mode(): void
    {
        [$r, $staff] = $this->storeFixture();
        $parked = $this->parkedOrder($r);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.approvals', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('mode', TableSessionService::MODE_STAFF_APPROVE)
            ->assertJsonPath('orders.0.id', $parked->id)
            ->assertJsonPath('orders.0.table_number', 4);
    }

    public function test_the_queue_reports_off_when_the_store_is_not_using_staff_approval(): void
    {
        [$r, $staff] = $this->storeFixture(TableSessionService::MODE_OFF);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.approvals', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('mode', TableSessionService::MODE_OFF)
            ->assertJsonPath('orders', []);
    }

    public function test_approving_hands_the_order_to_the_kitchen(): void
    {
        [$r, $staff] = $this->storeFixture();
        $order = $this->parkedOrder($r);

        // Before: invisible to every live staff queue.
        $this->assertSame(0, Order::live()->where('store_id', $r->id)->count());

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.approve', ['id' => $order->id, 'store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('status', 'new');

        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame(1, Order::live()->where('store_id', $r->id)->count());
    }

    public function test_rejecting_cancels_the_order_and_stamps_it(): void
    {
        [$r, $staff] = $this->storeFixture();
        $order = $this->parkedOrder($r);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.reject', ['id' => $order->id, 'store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_an_order_that_is_not_parked_cannot_be_approved_or_rejected(): void
    {
        [$r, $staff] = $this->storeFixture();
        $order = $this->parkedOrder($r);
        $order->status = 'preparing';
        $order->save();

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.approve', ['id' => $order->id, 'store' => $r->id]))
            ->assertStatus(422);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.reject', ['id' => $order->id, 'store' => $r->id]))
            ->assertStatus(422);

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_another_tenants_order_is_not_reachable(): void
    {
        [$mine, $staff] = $this->storeFixture();
        [$theirs] = $this->storeFixture();
        $foreign = $this->parkedOrder($theirs);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.approve', ['id' => $foreign->id, 'store' => $mine->id]))
            ->assertNotFound();

        $this->assertSame(Order::STATUS_PENDING_APPROVAL, $foreign->fresh()->status);
    }
}
