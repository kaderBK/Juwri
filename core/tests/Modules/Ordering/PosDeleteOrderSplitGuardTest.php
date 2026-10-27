<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Services\SplitBillService;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * deleteOrder gated a settled sale behind pos_refund via `is_paid`, but a split bill
 * can hold PAID cash shares while the order is still is_paid=0 ('partial'). Deleting
 * such an order removes it (and its check rows) and orphans the already-collected cash
 * off the drawer / Z-report — the same skim the void path refuses. The delete path must
 * refuse too.
 */
class PosDeleteOrderSplitGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
            AddDashboardCspHeaders::class,
            EnsureStaffDashboardPermission::class,
            IsDemoMode::class,
            EnsureFeatureEnabled::class,
        ]);
    }

    /** @return array{0: User, 1: Store, 2: Menu} */
    private function shop(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$owner, $r, $item];
    }

    public function test_delete_is_refused_while_a_split_share_is_already_paid(): void
    {
        [$owner, $r, $item] = $this->shop();
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]], // total 20
        ]);

        // Split, then collect ONE $10 cash share — order stays is_paid=0 ('partial').
        $check = SplitBillService::createEvenSplit($order, 2);
        SplitBillService::markSharePaid($check->payments()->orderBy('share_no')->first(), 'Guest', 'cash');
        $this->assertSame(0, (int) $order->fresh()->is_paid);

        $this->actingAs($owner)
            ->postJson(route('stores.deleteOrder', ['order' => $order->id]))
            ->assertStatus(422);

        $this->assertNotNull(Order::find($order->id), 'order with collected cash must survive');
    }

    public function test_delete_of_an_unpaid_unsplit_order_still_works(): void
    {
        [$owner, $r, $item] = $this->shop();
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAs($owner)
            ->postJson(route('stores.deleteOrder', ['order' => $order->id]))
            ->assertOk();

        $this->assertNull(Order::find($order->id), 'a plain unpaid order still deletes');
    }

    /**
     * Cancelling would otherwise be an equivalent skim: a cancelled partial-split order
     * stays is_paid=0 with no 'split' stamp, so its collected cash is invisible to the
     * drawer just like a void/delete — the fix must close it too or it is bypassable.
     */
    public function test_cancel_is_refused_while_a_split_share_is_already_paid(): void
    {
        [$owner, $r, $item] = $this->shop();
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $check = SplitBillService::createEvenSplit($order, 2);
        SplitBillService::markSharePaid($check->payments()->orderBy('share_no')->first(), 'Guest', 'cash');

        $this->actingAs($owner)
            ->postJson(route('stores.updateOrderStatus', ['order' => $order->id]), ['status' => 'cancelled'])
            ->assertStatus(422);

        $this->assertNotSame('cancelled', Order::find($order->id)->status);
    }

    public function test_non_cancel_status_change_is_unaffected_by_paid_shares(): void
    {
        [$owner, $r, $item] = $this->shop();
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $check = SplitBillService::createEvenSplit($order, 2);
        SplitBillService::markSharePaid($check->payments()->orderBy('share_no')->first(), 'Guest', 'cash');

        // Advancing the kitchen state on a partly-paid split is still fine.
        $this->actingAs($owner)
            ->postJson(route('stores.updateOrderStatus', ['order' => $order->id]), ['status' => 'preparing'])
            ->assertOk();

        $this->assertSame('preparing', Order::find($order->id)->status);
    }
}
