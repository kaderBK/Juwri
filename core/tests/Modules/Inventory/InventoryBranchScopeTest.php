<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use App\Models\StaffMember;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\StockCount;
use Juwri\Modules\Inventory\Models\WasteLog;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-224: deliveries, stock counts and waste land on the branch they happened at (branch_stock_levels), like sales
 * do; the store-wide figure is untouched. Branch-pinned staff work only in their branch (other branches, store-wide
 * documents and other stores of the owner are 404).
 */
class InventoryBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Branch $a;

    private Branch $b;

    private Ingredient $ing;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
        $plan = TestPlans::create(['name' => 'P '.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1]);
        $this->owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->store = $this->makeStore();
        $this->a = Branch::create(['store_id' => $this->store->id, 'name' => 'A', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $this->b = Branch::create(['store_id' => $this->store->id, 'name' => 'B', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $this->ing = Ingredient::create(['store_id' => $this->store->id, 'name' => 'Rice', 'unit' => 'kg', 'current_stock' => 100, 'par_level' => 0, 'cost_per_unit' => 2]);
    }

    private function makeStore(): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create(['user_id' => $this->owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function bal(Branch $branch): float
    {
        return (float) (BranchStock::query()->where('branch_id', $branch->id)->where('ingredient_id', $this->ing->id)->value('current_stock') ?? 0);
    }

    private function asStaffOf(?Branch $branch, ?Store $store = null): static
    {
        $staff = StaffMember::create(['owner_id' => $this->owner->id, 'store_id' => ($store ?? $this->store)->id, 'branch_id' => $branch?->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1]);
        $this->app['auth']->forgetGuards();

        return $this->actingAs($staff, 'staff')->actingAs($this->owner, 'web')->withSession(['staff_session_id' => $staff->id]);
    }

    private function po(?Branch $branch, float $qty = 20): PurchaseOrder
    {
        $po = PurchaseOrder::create(['store_id' => $this->store->id, 'branch_id' => $branch?->id, 'status' => 'ordered', 'user_id' => $this->owner->id]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $this->ing->id, 'quantity' => $qty, 'unit_cost' => 2, 'line_total' => $qty * 2]);

        return $po;
    }

    public function test_a_delivery_stocks_its_branch_only(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.purchaseOrders.store'), ['store_id' => $this->store->id, 'branch_id' => $this->a->id])->assertRedirect();
        $po = PurchaseOrder::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame($this->a->id, (int) $po->branch_id);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $this->ing->id, 'quantity' => 20, 'unit_cost' => 2, 'line_total' => 40]);

        $this->actingAs($this->owner)->post(route('inventory.purchaseOrders.receive', $po->id))->assertRedirect();
        $this->assertSame(20.0, $this->bal($this->a));
        $this->assertSame(0.0, $this->bal($this->b));
        $this->assertSame(100.0, (float) $this->ing->fresh()->current_stock, 'store-wide stock untouched');

        $lines = $this->po($this->b, 10);
        (new ReceivingService)->receive($lines, [['item_id' => $lines->items()->value('id'), 'qty_received' => 7]]);
        $this->assertSame(7.0, $this->bal($this->b), 'line receiving too');
        $this->assertSame(20.0, $this->bal($this->a));
    }

    public function test_a_store_wide_delivery_still_stocks_the_store(): void
    {
        InventoryService::receivePurchaseOrder($this->po(null));
        $this->assertSame(120.0, (float) $this->ing->fresh()->current_stock);
        $this->assertSame(0, BranchStock::query()->count());
    }

    public function test_a_branch_count_starts_from_and_corrects_that_branch(): void
    {
        InventoryService::recordMovement($this->ing->id, 'purchase', 30, ['branch_id' => $this->a->id]);
        InventoryService::recordMovement($this->ing->id, 'purchase', 50, ['branch_id' => $this->b->id]);

        $this->actingAs($this->owner)->post(route('inventory.counts.store'), ['store_id' => $this->store->id, 'branch_id' => $this->a->id])->assertRedirect();
        $count = StockCount::query()->firstOrFail();
        $this->assertSame($this->a->id, (int) $count->branch_id);
        $item = $count->items()->firstOrFail();
        $this->assertSame(30.0, (float) $item->theoretical_qty, 'the branch balance, not the store-wide 100');

        $this->actingAs($this->owner)->put(route('inventory.counts.save', $count->id), ['counted' => [$item->id => 26]]);
        $this->actingAs($this->owner)->post(route('inventory.counts.close', $count->id))->assertRedirect();
        $this->assertSame(26.0, $this->bal($this->a));
        $this->assertSame(50.0, $this->bal($this->b));
        $this->assertSame(100.0, (float) $this->ing->fresh()->current_stock);
    }

    public function test_waste_comes_off_its_branch_and_the_report_shows_per_branch_balances(): void
    {
        InventoryService::recordMovement($this->ing->id, 'purchase', 30, ['branch_id' => $this->a->id]);
        InventoryService::recordMovement($this->ing->id, 'purchase', 50, ['branch_id' => $this->b->id]);
        $this->actingAs($this->owner)->post(route('inventory.waste.store'), ['ingredient_id' => $this->ing->id, 'quantity' => 4, 'reason' => 'spoilage', 'branch_id' => $this->b->id])->assertRedirect();
        $this->assertSame($this->b->id, (int) WasteLog::query()->value('branch_id'));
        $this->assertSame(46.0, $this->bal($this->b));
        $this->assertSame(30.0, $this->bal($this->a));
        $this->assertSame(100.0, (float) $this->ing->fresh()->current_stock);

        $a = $this->actingAs($this->owner)->get(route('inventory.reports.index', ['store_id' => $this->store->id, 'branch_id' => $this->a->id]))->assertOk();
        $this->assertSame(60.0, (float) $a->viewData('report')['stockValue'], 'A: 30 kg x 2.00');
        $this->assertSame(0.0, (float) $a->viewData('report')['wasteCost30'], 'B\'s waste is not A\'s');
        $b = $this->actingAs($this->owner)->get(route('inventory.reports.index', ['store_id' => $this->store->id, 'branch_id' => $this->b->id]))->assertOk();
        $this->assertSame(92.0, (float) $b->viewData('report')['stockValue']);
        $this->assertSame(8.0, (float) $b->viewData('report')['wasteCost30']);
    }

    public function test_a_branch_of_another_store_is_404(): void
    {
        $other = $this->makeStore();
        $foreign = Branch::create(['store_id' => $other->id, 'name' => 'X', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $this->actingAs($this->owner)->post(route('inventory.purchaseOrders.store'), ['store_id' => $this->store->id, 'branch_id' => $foreign->id])->assertNotFound();
        $this->actingAs($this->owner)->post(route('inventory.counts.store'), ['store_id' => $this->store->id, 'branch_id' => $foreign->id])->assertNotFound();
        $this->actingAs($this->owner)->post(route('inventory.waste.store'), ['ingredient_id' => $this->ing->id, 'quantity' => 1, 'reason' => 'spoilage', 'branch_id' => $foreign->id])->assertNotFound();
        $this->assertSame(0, PurchaseOrder::query()->count() + StockCount::query()->count() + WasteLog::query()->count());
    }

    public function test_branch_pinned_staff_cannot_touch_another_branch(): void
    {
        $poB = $this->po($this->b);
        $poStore = $this->po(null);
        $countB = StockCount::create(['store_id' => $this->store->id, 'branch_id' => $this->b->id, 'status' => 'open', 'user_id' => $this->owner->id]);

        $this->asStaffOf($this->a)->post(route('inventory.purchaseOrders.receive', $poB->id))->assertNotFound();
        $this->asStaffOf($this->a)->post(route('inventory.purchaseOrders.receive', $poStore->id))->assertNotFound();
        $this->asStaffOf($this->a)->get(route('inventory.purchaseOrders.show', $poB->id))->assertNotFound();
        $this->asStaffOf($this->a)->post(route('inventory.counts.close', $countB->id))->assertNotFound();
        $this->asStaffOf($this->a)->post(route('inventory.purchaseOrders.store'), ['store_id' => $this->store->id, 'branch_id' => $this->b->id])->assertNotFound();
        $this->asStaffOf($this->a)->post(route('inventory.waste.store'), ['ingredient_id' => $this->ing->id, 'quantity' => 1, 'reason' => 'spoilage', 'branch_id' => $this->b->id])->assertNotFound();
        $this->asStaffOf($this->a)->post(route('inventory.ingredients.adjust', $this->ing->id), ['direction' => 'in', 'quantity' => 5, 'branch_id' => $this->b->id])->assertNotFound();
        $this->assertSame('ordered', $poB->fresh()->status);
        $this->assertSame('open', $countB->fresh()->status);
        $this->assertSame(0.0, $this->bal($this->b));

        // with no branch named, pinned staff work in their own branch, never store-wide
        $this->asStaffOf($this->a)->post(route('inventory.waste.store'), ['ingredient_id' => $this->ing->id, 'quantity' => 1, 'reason' => 'spoilage'])->assertRedirect();
        $this->assertSame($this->a->id, (int) WasteLog::query()->value('branch_id'));
        $this->asStaffOf($this->a)->post(route('inventory.ingredients.adjust', $this->ing->id), ['direction' => 'in', 'quantity' => 5])->assertRedirect();
        $this->assertSame(4.0, $this->bal($this->a));
        $this->assertSame(100.0, (float) $this->ing->fresh()->current_stock);

        $list = $this->asStaffOf($this->a)->get(route('inventory.purchaseOrders.index', ['store_id' => $this->store->id]))->assertOk();
        $this->assertSame([], $list->viewData('orders')->pluck('id')->all(), 'neither B\'s nor store-wide orders');
    }

    public function test_staff_of_one_store_cannot_reach_another_store_of_the_owner(): void
    {
        $other = $this->makeStore();
        $ing = Ingredient::create(['store_id' => $other->id, 'name' => 'Oil', 'unit' => 'l', 'current_stock' => 10, 'par_level' => 0, 'cost_per_unit' => 1]);
        $this->asStaffOf(null)->post(route('inventory.waste.store'), ['ingredient_id' => $ing->id, 'quantity' => 1, 'reason' => 'spoilage'])->assertNotFound();
        $this->asStaffOf(null)->post(route('inventory.purchaseOrders.store'), ['store_id' => $other->id])->assertForbidden();
        $this->assertSame(10.0, (float) $ing->fresh()->current_stock);
    }
}
