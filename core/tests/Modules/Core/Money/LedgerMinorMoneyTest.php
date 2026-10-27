<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\LegacyMoneyColumns;
use Tests\TestCase;

/**
 * P1-05 slice 5b: ledger_entries store `amount_minor` / `balance_after_minor` in their ledger account's currency
 * (stamped as the entry's currency_code); balances are exact integer sums per account.
 */
class LedgerMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_take_the_account_currency_and_sum_exactly(): void
    {
        $platform = LedgerService::accountFor('platform', 0, 'platform', 'KWD');
        $wallet = LedgerService::accountFor('merchant', 1, 'merchant', 'KWD');
        foreach (range(1, 10) as $i) {
            LedgerService::transfer($platform, $wallet, 0.1, "t{$i}", 'fund', true);
        }

        $row = DB::table('ledger_entries')->where('ledger_account_id', $wallet->id)->orderByDesc('id')->first();
        $this->assertSame('KWD', $row->currency_code);
        $this->assertSame(100, (int) $row->amount_minor);
        $this->assertSame(1000, (int) $row->balance_after_minor);
        $this->assertSame(1.0, LedgerService::balanceFor($wallet), 'ten 0.1 credits are exactly 1 (integer sum)');
        $this->assertSame(1.0, (float) LedgerEntry::where('ledger_account_id', $wallet->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))));
    }

    public function test_zero_decimal_currency_posts_whole_units(): void
    {
        $platform = LedgerService::accountFor('platform', 0, 'platform', 'JPY');
        $wallet = LedgerService::accountFor('merchant', 2, 'merchant', 'JPY');
        LedgerService::transfer($platform, $wallet, 1500.4, 'j1', 'fund', true);

        $entry = LedgerEntry::where('ledger_account_id', $wallet->id)->first();
        $this->assertSame(1500, $entry->amount_minor);
        $this->assertSame(1500, $entry->balance_after_minor);
        $this->assertSame(1500.0, LedgerService::balanceFor($wallet));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_legacy_rows_convert_in_the_given_currency(): void
    {
        $this->assertSame(['amount_minor' => -250, 'balance_after_minor' => 1000, 'currency_code' => 'USD'],
            LegacyMoneyColumns::upgradeRow('ledger_entries', ['amount' => '-2.50', 'balance_after' => '10.00'], 'USD'));
    }
}
