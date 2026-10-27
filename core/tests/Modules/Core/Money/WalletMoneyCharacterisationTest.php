<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Http\Middleware\Installed;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-05 slice 5c (characterisation): the public money behaviour of the platform wallet (wallet_transactions) —
 * credits/debits, balances and balance_after snapshots, idempotent refs, the funds check, the ledger mirror, the
 * entry shapes, and the partner "earned" sums. Written against the decimal(10,2) columns BEFORE the move to
 * `*_minor` and kept green after it: only the storage changes.
 */
class WalletMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD']);
        $this->withoutMiddleware([Installed::class]);
    }

    private function user(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    public function test_credit_debit_balances_snapshots_and_shapes(): void
    {
        $u = $this->user();
        $this->assertSame(0.0, WalletService::balanceFor($u));

        $this->assertSame(25.5, WalletService::credit($u, 25.5, 'Wallet top-up', 'topup-txn-1'));
        $this->assertSame(25.5, WalletService::credit($u, 25.5, 'Wallet top-up', 'topup-txn-1'), 'same ref = no-op');
        $this->assertEqualsWithDelta(15.25, WalletService::debit($u, 10.25, 'addon_purchase', 'addon-1'), 0.00001);
        $this->assertSame(15.25, WalletService::balanceFor($u));

        try {
            WalletService::debit($u, 15.26, 'too much');
            $this->fail('insufficient funds must throw');
        } catch (\RuntimeException) {
        }
        $this->assertSame(15.25, WalletService::balanceFor($u));

        $rows = WalletService::ledgerFor($u);
        $this->assertCount(2, $rows);
        $debit = WalletTransaction::where('ref', 'addon-1')->first();
        $this->assertSame('-10.25', $debit->amount, 'amount keeps the decimal:2 string');
        $this->assertSame('15.25', $debit->balance_after);
        $this->assertSame('debit', $debit->type);
        $this->assertSame('USD', $debit->currency_code);
        $json = $debit->toArray();
        $this->assertSame('-10.25', $json['amount']);
        $this->assertSame('15.25', $json['balance_after']);

        // Every movement is mirrored into the unified ledger (merchant account vs platform clearing).
        $account = LedgerService::accountFor('merchant', $u->id, 'merchant');
        $this->assertSame(15.25, LedgerService::balanceFor($account));
        $this->assertSame(2, LedgerEntry::where('ledger_account_id', $account->id)->count());
    }

    public function test_partner_earned_sums_sales_only(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $me = $this->user();
        WalletService::credit($me, 70.1, 'theme sale: mine', 'sale-1');
        WalletService::credit($me, 9.9, 'app renewal: x', 'ren-1');
        WalletService::credit($me, 20, 'wallet top-up', 'topup-1');
        WalletService::debit($me, 5, 'payout sale: refund', 'neg-1'); // a negative row never counts as earned

        $overview = $this->actingAs($me)->get(route('partner.overview'))->assertOk();
        $this->assertEqualsWithDelta(95.0, (float) $overview->viewData('balance'), 0.00001);
        $this->assertEqualsWithDelta(80.0, (float) $overview->viewData('earned'), 0.00001);

        $earnings = $this->actingAs($me)->get(route('partner.earnings'))->assertOk();
        $this->assertEqualsWithDelta(80.0, (float) $earnings->viewData('earned'), 0.00001, 'pre-ledger fallback: wallet sale rows');
        $this->assertCount(2, $earnings->viewData('sales'));
    }
}
