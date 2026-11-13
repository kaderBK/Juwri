<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Reservation;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Tests\TestCase;

/**
 * P1-05 slice 6c: reservations.deposit_amount, stores.mk_min_order and the legacy users.balance are stored as
 * `*_minor` (in the reservation's `currency`, the store's `mk_min_order_currency`, and the platform currency).
 */
class AccountStoreMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency, array $attrs = []): Store
    {
        $store = Store::create($attrs + [
            'user_id' => User::factory()->create()->id, 'slug' => 's-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store->fresh();
    }

    public function test_reservation_deposit_keeps_the_store_currency_whatever_the_fill_order(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('OMR');
        // Amount before store_id: the old StoreMoney cast rounded this to the 2-decimal fallback (0.28).
        $res = Reservation::create([
            'deposit_amount' => 0.275, 'store_id' => $store->id, 'name' => 'G', 'party_size' => 2,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);
        $row = DB::table('reservations')->where('id', $res->id)->first();
        $this->assertSame(['OMR', 275], [$row->currency, (int) $row->deposit_amount_minor]);
        $this->assertSame('0.275', $res->fresh()->deposit_amount);

        // A later store-currency change does not re-interpret the deposit.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $fresh = $res->fresh();
        $fresh->update(['status' => 'confirmed', 'deposit_amount' => 0.5]);
        $row = DB::table('reservations')->where('id', $res->id)->first();
        $this->assertSame(['OMR', 500], [$row->currency, (int) $row->deposit_amount_minor]);
    }

    public function test_store_minimum_order_currency_column(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('KWD');
        $store->update(['mk_min_order' => 2.125]);
        $row = DB::table('stores')->where('id', $store->id)->first();
        $this->assertSame(['KWD', 2125], [$row->mk_min_order_currency, (int) $row->mk_min_order_minor]);
        $this->assertSame(2.125, $store->fresh()->mk_min_order);

        // Select lists that carry the two columns read the same number.
        $listed = Store::whereKey($store->id)->get(['id', 'mk_min_order_minor', 'mk_min_order_currency'])->first();
        $this->assertSame(2.125, $listed->mk_min_order);
        $this->assertSame(2.125, $listed->toArray()['mk_min_order']);

        // The store switches currency: the stored minimum keeps its meaning until it is edited.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $this->assertSame(2.125, $store->fresh()->mk_min_order);
        $store->fresh()->update(['mk_min_order' => 20]);
        $row = DB::table('stores')->where('id', $store->id)->first();
        $this->assertSame(['USD', 2000], [$row->mk_min_order_currency, (int) $row->mk_min_order_minor]);
    }

    public function test_user_balance_and_legacy_rows(): void
    {
        config(['settings.currency_code' => 'KWD']);
        $u = User::factory()->create();
        $u->update(['balance' => 1.5]);
        $this->assertSame(1500, (int) DB::table('users')->where('id', $u->id)->value('balance_minor'));
        $this->assertSame(1.5, $u->fresh()->balance);
        $this->assertSame(1.5, $u->fresh()->toArray()['balance']);

        $this->assertSame(['balance_minor' => 1250], LegacyMoneyColumns::upgradeRow('users', ['balance' => 1.25], 'KWD'));
        $this->assertSame(['currency' => 'OMR', 'deposit_amount_minor' => 275],
            LegacyMoneyColumns::upgradeRow('reservations', ['currency' => 'OMR', 'deposit_amount' => '0.275'], 'USD'));
        $this->assertSame(['mk_min_order_minor' => 2550, 'mk_min_order_currency' => 'USD'],
            LegacyMoneyColumns::upgradeRow('stores', ['mk_min_order' => '25.50'], 'USD'));
        $this->assertSame(['mk_min_order_minor' => null, 'mk_min_order_currency' => 'USD'], LegacyMoneyColumns::upgradeRow('stores', ['mk_min_order' => null], 'USD'));
    }
}
