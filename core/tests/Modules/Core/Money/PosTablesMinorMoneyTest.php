<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Order;
use App\Models\PosShift;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Modules\Tables\Models\TableCheck;
use Tests\TestCase;

/**
 * P1-05 slice 7a: pos_shifts drawer amounts and table_checks.total are stored as `*_minor` in the row's
 * `currency_code` (the store's at open / the order's) — a fact, never re-interpreted after a store currency change.
 */
class PosTablesMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create()->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    public function test_shift_amounts_are_minor_units_of_the_currency_at_open(): void
    {
        $r = $this->store('OMR');
        $shift = PosService::openShift($r, null, null, 10.125);
        $row = DB::table('pos_shifts')->where('id', $shift->id)->first();
        $this->assertSame(['OMR', 10125, null], [$row->currency_code, (int) $row->opening_float_minor, $row->over_short_minor]);
        $this->assertSame(10125, PosShift::find($shift->id)->money('opening_float')->minor());

        // The store moves to USD while the drawer is open: the float keeps its fils (a fact of the open).
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        $fresh = PosShift::find($shift->id);
        $this->assertSame('10.125', $fresh->opening_float);
        $fresh->update(['counted_cash' => 9.5]);
        $row = DB::table('pos_shifts')->where('id', $shift->id)->first();
        $this->assertSame(['OMR', 10125, 9500], [$row->currency_code, (int) $row->opening_float_minor, (int) $row->counted_cash_minor]);
        $this->assertSame(10125, (int) PosShift::where('opening_float_minor', '>', 10000)->value('opening_float_minor'));
    }

    public function test_check_total_is_in_the_order_currency(): void
    {
        $r = $this->store('KWD');
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'Joe', 'status' => 'new']);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');

        $check = TableCheck::create(['store_id' => $r->id, 'order_id' => $order->id, 'total' => 3.125, 'parts' => 2, 'status' => 'open']);
        $row = DB::table('table_checks')->where('id', $check->id)->first();
        $this->assertSame(['KWD', 3125], [$row->currency_code, (int) $row->total_minor]);
        $fresh = TableCheck::find($check->id);
        $this->assertSame('KWD', $fresh->moneyCurrency());
        $this->assertSame('3.125', $fresh->total);
        $this->assertSame(3.125, $fresh->remaining());
        $this->assertSame(3125, $fresh->toArray()['total_minor']);
    }

    public function test_legacy_rows(): void
    {
        $this->assertSame(
            ['currency_code' => 'OMR', 'opening_float_minor' => 10125, 'expected_cash_minor' => null, 'counted_cash_minor' => 9500, 'over_short_minor' => -625],
            LegacyMoneyColumns::upgradeRow('pos_shifts', ['currency_code' => 'OMR', 'opening_float' => '10.125', 'expected_cash' => null, 'counted_cash' => '9.500', 'over_short' => '-0.625'], 'USD'),
        );
        $this->assertSame(['total_minor' => 2050, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('table_checks', ['total' => '20.50'], 'USD'));
    }
}
