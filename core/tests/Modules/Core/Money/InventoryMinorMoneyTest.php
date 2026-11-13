<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Ingredient;
use App\Models\StoreOption;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierPayment;
use Juwri\Modules\Inventory\Models\WasteLog;
use Juwri\Modules\Inventory\Services\Inventory\SupplierBalanceService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 8a: Inventory money in `*_minor` — currency stamping (store / purchase order / supplier's store),
 * documents keep their currency after a store-currency change while catalogue rows (ingredients) follow it when
 * edited, ISO-exponent rounding (KWD fils, JPY whole yen), per-row SQL sums, legacy archive rows.
 */
class InventoryMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'm-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    public function test_documents_keep_their_currency_and_catalogue_rows_follow_the_store(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('KWD');
        // Amount before store_id: the row still takes the store's currency (fils kept).
        $ing = Ingredient::create(['cost_per_unit' => 1.234, 'store_id' => $store->id, 'name' => 'Flour']);
        $this->assertSame(['KWD', 1234], [$ing->currency_code, $ing->cost_per_unit_minor]);
        $this->assertSame('1.234', $ing->fresh()->cost_per_unit);

        $po = PurchaseOrder::create(['store_id' => $store->id, 'status' => 'draft']);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 2, 'unit_cost' => 0.505, 'line_total' => 1.01,
        ]);
        $po->recalcTotal();
        $this->assertSame(['KWD', 'KWD'], [$po->fresh()->currency_code, $line->fresh()->currency_code]);
        $this->assertSame([505, 1010, 1010], [$line->fresh()->unit_cost_minor, $line->fresh()->line_total_minor, $po->fresh()->total_minor]);
        $this->assertSame(['unit_cost_minor' => 505, 'line_total_minor' => 1010, 'actual_unit_cost_minor' => null],
            array_intersect_key($line->fresh()->toArray(), array_flip(['unit_cost_minor', 'line_total_minor', 'actual_unit_cost_minor'])));

        // The store switches to USD: documents keep KWD; a NEW line of the old order still follows its order.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $line2 = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 1, 'unit_cost' => 0.25, 'line_total' => 0.25]);
        $this->assertSame(['KWD', 250], [$line2->currency_code, $line2->unit_cost_minor]);
        $po->fresh()->update(['total' => 1.26]);
        $this->assertSame(['KWD', 1260, '1.260'], [$po->fresh()->currency_code, $po->fresh()->total_minor, $po->fresh()->total]);

        // The ingredient (catalogue) moves to USD when its cost is edited; the number is what the owner typed.
        $ing->fresh()->update(['cost_per_unit' => 2.5]);
        $this->assertSame(['USD', 250, '2.50'], [$ing->fresh()->currency_code, $ing->fresh()->cost_per_unit_minor, $ing->fresh()->cost_per_unit]);
        // A movement / waste row is stamped with the store's current currency.
        $mv = InventoryService::recordMovement($ing->id, 'purchase', 4, ['unit_cost' => 2.5]);
        $waste = InventoryService::logWaste($ing->id, 1, 'spoilage');
        $this->assertSame(['USD', 250], [$mv->currency_code, $mv->unit_cost_minor]);
        $this->assertSame(['USD', 250], [$waste->currency_code, $waste->cost_minor]);
    }

    public function test_iso_exponent_rounding_and_supplier_payments(): void
    {
        config(['settings.currency_code' => 'USD']);
        $jpy = $this->store('JPY');
        $ing = Ingredient::create(['store_id' => $jpy->id, 'name' => 'Rice', 'cost_per_unit' => 12.6]);
        $this->assertSame([13, '13'], [$ing->fresh()->cost_per_unit_minor, $ing->fresh()->cost_per_unit]);

        $kwd = $this->store('KWD');
        $supplier = Supplier::create(['store_id' => $kwd->id, 'name' => 'S', 'active' => true]);
        $p = SupplierPayment::create(['supplier_id' => $supplier->id, 'amount' => 1.234]);
        $this->assertSame(['KWD', 1234, 1.234], [$p->currency_code, $p->amount_minor, $p->fresh()->amount]);
        SupplierPayment::create(['supplier_id' => $supplier->id, 'amount' => 0.5]);
        $this->assertSame(1.734, SupplierBalanceService::paymentsTotal($supplier), 'per-row exponent SUM, at the KWD exponent (bug 6; before: 1.73)');
        $this->assertSame(1734, (int) DB::table('supplier_payments')->where('supplier_id', $supplier->id)->sum('amount_minor'));
    }

    public function test_waste_sums_add_each_row_at_its_exponent(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('OMR');
        $ing = Ingredient::create(['store_id' => $store->id, 'name' => 'Oil', 'current_stock' => 10, 'cost_per_unit' => 0.5]);
        InventoryService::logWaste($ing->id, 1, 'spoilage');
        InventoryService::logWaste($ing->id, 3, 'spoilage');
        $this->assertSame([500, 1500], WasteLog::orderBy('id')->pluck('cost_minor')->map(fn ($v) => (int) $v)->all());
        $sum = (float) WasteLog::where('store_id', $store->id)->sum(DB::raw(WasteLog::minorMoneySql('cost')));
        $this->assertEqualsWithDelta(2.0, $sum, 0.00001);
    }

    public function test_legacy_rows(): void
    {
        $this->assertSame(['cost_per_unit_minor' => 275, 'currency_code' => 'OMR'],
            LegacyMoneyColumns::upgradeRow('ingredients', ['cost_per_unit' => '0.275'], 'OMR'));
        $this->assertSame(['currency_code' => 'USD', 'unit_cost_minor' => 320, 'line_total_minor' => 3200, 'actual_unit_cost_minor' => null],
            LegacyMoneyColumns::upgradeRow('purchase_order_items', ['currency_code' => 'USD', 'unit_cost' => '3.20', 'line_total' => '32.00', 'actual_unit_cost' => null], 'KWD'));
        $this->assertSame(['total_minor' => 36000, 'currency_code' => 'KWD'], LegacyMoneyColumns::upgradeRow('purchase_orders', ['total' => 36], 'KWD'));
        $this->assertSame(['unit_cost_minor' => null, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('stock_movements', ['unit_cost' => null], 'USD'));
        $this->assertSame(['total_minor' => 713, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('supplier_invoices', ['total' => '7.13'], 'USD'));
        $this->assertSame(['amount_minor' => 12375, 'currency_code' => 'OMR'], LegacyMoneyColumns::upgradeRow('supplier_payments', ['amount' => 12.375], 'OMR'));
        $this->assertSame(['cost_minor' => 1200, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('waste_logs', ['cost' => '12.00'], 'USD'));
        // A newer archive carries the minor column: kept as it is.
        $this->assertSame(['cost_minor' => 99, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('waste_logs', ['cost' => '12.00', 'cost_minor' => 99, 'currency_code' => 'USD'], 'KWD'));
    }
}
