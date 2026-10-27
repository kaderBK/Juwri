<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelEventItem;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Services\Inventory\CogsService;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 10a-2 (characterisation): the money rounding of the hotel folio (order charges, settlements,
 * adjustments, room nights, extra services, events, statements — at the folio currency's DISPLAY decimals, capped by
 * the ledger's 2-decimal legs) and of Inventory (waste cost, receiving actual cost + weighted average + the receipt GL
 * journal, COGS purchases / variance / supplier spend) for USD, KWD and JPY stores, with .5 edges exact in binary so
 * PHP 8.3 and 8.4 round() agree. Written BEFORE the bare round() calls moved to the Money layer; values that changed
 * for 3-decimal currencies are marked "before:".
 */
class HotelInventoryRoundingCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.currency_code' => 'USD', 'settings.admin_allow_accounting_gl' => true]);
    }

    private function store(string $currency): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 9, 'hotel' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'h-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function stay(Store $store): HotelStay
    {
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 101, 'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room', 'label' => 'Suite',
        ]);

        return HotelFolioService::checkIn($store->id, $room, 'Sam Carter');
    }

    private function order(Store $store): Order
    {
        return Order::create(['store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending']);
    }

    public function test_folio_rounds_at_the_folio_currency_decimals(): void
    {
        $usd = $this->store('USD');
        $stay = $this->stay($usd);
        HotelFolioService::chargeOrder($this->order($usd), $stay, 10.125);
        $this->assertSame(10.13, HotelFolioService::balance($stay));
        HotelFolioService::settle($stay, 5.125);
        $this->assertSame(5.0, HotelFolioService::balance($stay));
        HotelFolioService::adjust($stay, 0.375, 'Minibar');
        HotelFolioService::postNightlyCharges($stay, [['date' => '2026-10-02', 'price' => 99.875]]);
        $svc = HotelExtraService::create(['store_id' => $usd->id, 'name' => 'Breakfast', 'price' => 12.5, 'tax_rate' => 5]);
        $this->assertSame(13.13, HotelFolioService::postServiceCharge($stay, $svc)['amount'], '12.50 × 1.05 = 13.125');
        $this->assertSame(118.39, HotelFolioService::balance($stay));
        $st = HotelFolioService::statement($stay);
        $this->assertSame([123.52, 5.13, 118.39], [$st['total_charges'], $st['total_payments'], $st['balance']]);
        $this->assertSame([10.13, 0.38, 99.88, 13.13], array_column($st['charges'], 'amount'));

        $kwd = $this->store('KWD');
        $stay = $this->stay($kwd);
        // 3-decimal display rounding (10.0625 → 10.063); the ledger posts the leg at the KWD exponent (bug batch 4 · 1).
        HotelFolioService::chargeOrder($this->order($kwd), $stay, 10.0625);
        HotelFolioService::settle($stay, 5.0625);
        $this->assertSame(5.0, HotelFolioService::balance($stay));
        $st = HotelFolioService::statement($stay);
        // before: [10.06, 5.06, 5.0] — the pre-bug-batch-4 ledger cut every leg to 2 decimals.
        $this->assertSame([10.063, 5.063, 5.0], [$st['total_charges'], $st['total_payments'], $st['balance']]);

        $jpy = $this->store('JPY');
        $stay = $this->stay($jpy);
        HotelFolioService::chargeOrder($this->order($jpy), $stay, 1000.5);
        $this->assertSame(1001.0, HotelFolioService::balance($stay));
        $this->assertSame(1001.0, HotelFolioService::statement($stay)['total_charges']);
    }

    public function test_php_version_independent_float_edges(): void
    {
        // After the move only: 99.995 is 99.99499999… in binary; PHP 8.4 round(99.995, 2) = 99.99 (8.3: 100.00).
        // Money::round reads 15 significant digits → 100.00 on every PHP version.
        $usd = $this->store('USD');
        $stay = $this->stay($usd);
        HotelFolioService::postNightlyCharges($stay, [['date' => '2026-10-02', 'price' => 99.995]]);
        $this->assertSame(100.0, HotelFolioService::balance($stay));
    }

    public function test_standalone_event_items_and_settlement(): void
    {
        $usd = $this->store('USD');
        $event = HotelEvent::create(['store_id' => $usd->id, 'title' => 'Wedding']);
        HotelEventItem::create(['store_id' => $usd->id, 'event_id' => $event->id, 'description' => 'Hall', 'qty' => 2.5, 'unit_price' => 4.25]);
        $this->assertSame(10.63, HotelFolioService::postEventItems($event)['total'], '2.5 × 4.25 = 10.625');
        $this->assertSame(10.63, HotelFolioService::eventBalance($event));
        HotelFolioService::settleEvent($event, 4.125);
        $this->assertSame(6.5, HotelFolioService::eventBalance($event));
        $st = HotelFolioService::eventStatement($event);
        $this->assertSame([10.63, 4.13, 6.5], [$st['total_charges'], $st['total_payments'], $st['balance']]);
    }

    public function test_waste_cost(): void
    {
        foreach ([['USD', 1.25, 0.5, '0.63'], ['KWD', 0.125, 3, '0.375'], ['JPY', 125, 0.5, '63']] as [$currency, $cost, $qty, $expected]) {
            $store = $this->store($currency);
            $ing = Ingredient::create(['store_id' => $store->id, 'name' => 'I'.Str::random(4), 'unit' => 'kg', 'current_stock' => 10, 'cost_per_unit' => $cost]);
            // KWD before: '0.380' (3 × 0.125 rounded to 2 decimals, then stored with fils)
            $this->assertSame($expected, InventoryService::logWaste($ing->id, $qty, 'spoilage')->fresh()->cost, $currency);
        }
    }

    public function test_receiving_weighted_cost_gl_and_cogs(): void
    {
        $usd = $this->store('USD');
        $supplier = Supplier::create(['store_id' => $usd->id, 'name' => 'Acme', 'active' => true]);
        $flour = Ingredient::create(['store_id' => $usd->id, 'name' => 'Flour', 'unit' => 'kg', 'current_stock' => 0, 'cost_per_unit' => 2]);
        $po = PurchaseOrder::create(['store_id' => $usd->id, 'supplier_id' => $supplier->id, 'status' => 'ordered']);
        $line = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $flour->id, 'quantity' => 10, 'unit_cost' => 3, 'line_total' => 30]);
        (new ReceivingService)->receive($po->fresh('items'), [['item_id' => $line->id, 'qty_received' => 10, 'actual_unit_cost' => 3.125]]);
        $this->assertSame(['3.13', '3.13'], [$line->fresh()->actual_unit_cost, $flour->fresh()->cost_per_unit]);
        $gl = GlJournal::where('store_id', $usd->id)->where('source_type', 'PurchaseOrder')->firstOrFail();
        $this->assertSame('31.30', $gl->lines()->orderBy('id')->first()->debit);
        $cogs = new CogsService;
        $this->assertSame(31.3, $cogs->purchases($usd->id));
        $this->assertSame([['supplier' => 'Acme', 'total' => 31.3]], $cogs->supplierSpend($usd->id));
        $this->assertSame(10.81, $cogs->actualCogs(10.0625, 1.25, 0.5));
        $this->assertSame(['actual' => 31.3, 'theoretical' => 30.0, 'variance' => 1.3, 'variance_pct' => 4.3], $cogs->variance(31.3, 30.0));

        $kwd = $this->store('KWD');
        $supplier = Supplier::create(['store_id' => $kwd->id, 'name' => 'Omani', 'active' => true]);
        $oil = Ingredient::create(['store_id' => $kwd->id, 'name' => 'Oil', 'unit' => 'l', 'current_stock' => 10, 'cost_per_unit' => 0.125]);
        $po = PurchaseOrder::create(['store_id' => $kwd->id, 'supplier_id' => $supplier->id, 'status' => 'ordered']);
        $line = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $oil->id, 'quantity' => 4, 'unit_cost' => 0.275, 'line_total' => 1.1]);
        (new ReceivingService)->receive($po->fresh('items'), [['item_id' => $line->id, 'qty_received' => 4, 'actual_unit_cost' => 0.3125]]);
        // (10 × 0.125 + 4 × actual) / 14 — before: actual '0.310', weighted cost '0.178'
        $this->assertSame(['0.313', '0.179'], [$line->fresh()->actual_unit_cost, $oil->fresh()->cost_per_unit]);
        $gl = GlJournal::where('store_id', $kwd->id)->where('source_type', 'PurchaseOrder')->firstOrFail();
        $this->assertSame('1.252', $gl->lines()->orderBy('id')->first()->debit, 'before: 1.240');
        $this->assertSame(1.252, $cogs->purchases($kwd->id), 'before: 1.24');
        $this->assertSame([['supplier' => 'Omani', 'total' => 1.252]], $cogs->supplierSpend($kwd->id), 'before: 1.24');
        // before (no currency argument): variance(1.24, 1.0) → 0.24 / 24.0 %
        $this->assertSame(['actual' => 1.252, 'theoretical' => 1.0, 'variance' => 0.252, 'variance_pct' => 25.2], $cogs->variance(1.252, 1.0, 'KWD'));
        $this->assertSame(10.813, $cogs->actualCogs(10.0625, 1.25, 0.5, 'KWD'), 'before (2 decimals for every currency): 10.81');
        $this->assertSame(10.81, $cogs->actualCogs(10.0625, 1.25, 0.5), 'no currency: 2 decimals as before');
    }
}
