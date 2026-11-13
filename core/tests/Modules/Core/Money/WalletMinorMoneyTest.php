<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\LegacyMoneyColumns;
use Tests\TestCase;

/**
 * P1-05 slice 5c: wallet_transactions store `amount_minor` / `balance_after_minor` in the row's currency_code (the
 * platform currency); balances are integer sums per currency.
 */
class WalletMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_currency_precision_and_exact_balance(): void
    {
        config(['settings.currency_code' => 'KWD']);
        $u = User::factory()->create();
        foreach (range(1, 10) as $i) {
            WalletService::credit($u, 0.105, 'fils', "k{$i}");
        }

        $row = DB::table('wallet_transactions')->where('user_id', $u->id)->orderByDesc('id')->first();
        $this->assertSame('KWD', $row->currency_code);
        $this->assertSame(105, (int) $row->amount_minor, 'KWD keeps its fils (the decimal(10,2) column rounded them)');
        $this->assertSame(1050, (int) $row->balance_after_minor);
        $this->assertSame(1.05, WalletService::balanceFor($u));
        $this->assertSame('0.105', WalletTransaction::find($row->id)->money('amount')->major());
    }

    public function test_legacy_row_without_currency_reads_as_the_platform_currency(): void
    {
        config(['settings.currency_code' => 'USD']);
        $u = User::factory()->create();
        DB::table('wallet_transactions')->insert([
            'user_id' => $u->id, 'amount_minor' => 1999, 'balance_after_minor' => 1999, 'currency_code' => null,
            'type' => 'credit', 'reason' => 'legacy', 'created_at' => now(), 'updated_at' => now(),
        ]);
        WalletService::credit($u, 0.01, 'more', 'm1');

        $this->assertSame(20.0, WalletService::balanceFor($u));
        $this->assertSame(['amount_minor' => -250, 'balance_after_minor' => 1000, 'currency_code' => 'USD'],
            LegacyMoneyColumns::upgradeRow('wallet_transactions', ['amount' => '-2.50', 'balance_after' => '10.00'], 'USD'));
    }
}
