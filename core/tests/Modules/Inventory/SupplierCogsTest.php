<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Services\Inventory\CogsService;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Tests\TestCase;

/**
 * SYSTEMS-RUN SUP-P2: weighted-avg cost, price alert, variance, reorder, GL.
 */
class SupplierCogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_supplier_suite' => true, 'settings.admin_allow_accounting_gl' => true]);
    }

    private function po(float $stock = 0, float $cost = 2): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        ChartOfAccounts::ensure($r->id);
        $supplier = Supplier::create(['store_id' => $r->id, 'name' => 'S']);
        $ing = Ingredient::create(['store_id' => $r->id, 'name' => 'Flour', 'unit' => 'kg', 'current_stock' => $stock, 'cost_per_unit' => $cost, 'par_level' => 20, 'supplier_id' => $supplier->id, 'active' => 1]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'supplier_id' => $supplier->id, 'status' => 'ordered', 'total' => 40]);
        $item = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 10, 'unit_cost' => 2, 'line_total' => 20]);

        return [$owner, $r, $po, $item, $ing, $supplier];
    }

    public function test_weighted_average_cost(): void
    {
        // Start 10 units @ 2 = 20; receive 10 @ 4 = 40 → WAC = 60/20 = 3.
        [$owner, $r, $po, $item, $ing] = $this->po(10, 2);
        new ReceivingService()->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 10, 'actual_unit_cost' => 4]]);
        $ing->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $ing->current_stock, 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $ing->cost_per_unit, 0.01);
    }

    public function test_price_change_alert(): void
    {
        [$owner, $r, $po, $item, $ing] = $this->po(5, 2);
        StoreOption::updateStoreOption($r->id, 'price_change_alert_pct', '15');
        $res = new ReceivingService()->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 10, 'actual_unit_cost' => 3]]);
        $this->assertNotEmpty($res['price_alerts']); // 3 > 2*1.15
    }

    public function test_receipt_posts_inventory_gl(): void
    {
        [$owner, $r, $po, $item, $ing] = $this->po(0, 2);
        new ReceivingService()->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 10, 'actual_unit_cost' => 2]]);
        $j = GlJournal::where('store_id', $r->id)->where('source_type', 'PurchaseOrder')->first();
        $this->assertNotNull($j);
        $this->assertEqualsWithDelta(20.0, $j->totalDebit(), 0.01);
        $this->assertEqualsWithDelta($j->totalDebit(), $j->totalCredit(), 0.01);
    }

    public function test_variance_and_reorder(): void
    {
        [$owner, $r, $po, $item, $ing] = $this->po(2, 2); // stock 2, par 20 → below par
        $svc = new CogsService;
        $v = $svc->variance(100, 80);
        $this->assertEqualsWithDelta(20.0, $v['variance'], 0.01);
        $this->assertEqualsWithDelta(25.0, $v['variance_pct'], 0.1);

        $reorder = $svc->reorderSuggestions($r->id);
        $this->assertNotEmpty($reorder);
        $this->assertEqualsWithDelta(18.0, $reorder[0]['lines'][0]['suggest_qty'], 0.01); // 20 - 2
    }
}
