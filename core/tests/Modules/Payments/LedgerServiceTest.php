<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coupons & Wallets P1-2: the LedgerService core. Money correctness is the
 * product — every rule below (zero-sum, idempotency, funds-under-lock, reversal)
 * gets an explicit test.
 */
class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function acct(string $kind = 'merchant', int $owner = 1, string $cur = 'USD'): LedgerAccount
    {
        return LedgerService::accountFor($kind === 'platform' ? 'platform' : 'merchant', $owner, $kind, $cur);
    }

    public function test_account_for_is_get_or_create(): void
    {
        $a = LedgerService::accountFor('merchant', 7, 'merchant', 'USD');
        $b = LedgerService::accountFor('merchant', 7, 'merchant', 'usd'); // case-normalised

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LedgerAccount::count());
    }

    public function test_post_requires_zero_sum(): void
    {
        $src = $this->acct('platform', 0);
        $dst = $this->acct('merchant', 1);

        $this->expectException(\InvalidArgumentException::class);
        LedgerService::post([
            ['account' => $src, 'amount' => -100, 'allow_negative' => true],
            ['account' => $dst, 'amount' => 90], // does not balance
        ]);
    }

    public function test_post_moves_balanced_legs(): void
    {
        $src = $this->acct('platform', 0);
        $dst = $this->acct('merchant', 1);

        $tx = LedgerService::post([
            ['account' => $src, 'amount' => -100, 'allow_negative' => true, 'ref' => 'seed:src'],
            ['account' => $dst, 'amount' => 100, 'ref' => 'seed:dst'],
        ], null, 'seed');

        $this->assertNotEmpty($tx);
        $this->assertEqualsWithDelta(-100.0, LedgerService::balanceFor($src), 0.001);
        $this->assertEqualsWithDelta(100.0, LedgerService::balanceFor($dst), 0.001);
        $this->assertSame(2, LedgerEntry::where('transaction_id', $tx)->count());
    }

    public function test_post_is_idempotent_on_ref(): void
    {
        $src = $this->acct('platform', 0);
        $dst = $this->acct('merchant', 1);
        $legs = [
            ['account' => $src, 'amount' => -50, 'allow_negative' => true, 'ref' => 'once:src'],
            ['account' => $dst, 'amount' => 50, 'ref' => 'once:dst'],
        ];

        $t1 = LedgerService::post($legs);
        $t2 = LedgerService::post($legs); // replay

        $this->assertSame($t1, $t2);
        $this->assertEqualsWithDelta(50.0, LedgerService::balanceFor($dst), 0.001, 'not double-applied');
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_debit_beyond_balance_is_rejected_and_rolls_back(): void
    {
        $payer = $this->acct('merchant', 1);
        $payee = $this->acct('merchant', 2);
        // payer has only 30
        LedgerService::post([
            ['account' => $this->acct('platform', 0), 'amount' => -30, 'allow_negative' => true, 'ref' => 's1'],
            ['account' => $payer, 'amount' => 30, 'ref' => 's2'],
        ]);

        try {
            LedgerService::post([
                ['account' => $payer, 'amount' => -50, 'ref' => 'over:1'], // would go negative
                ['account' => $payee, 'amount' => 50, 'ref' => 'over:2'],
            ]);
            $this->fail('expected insufficient-funds RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        // No partial post: payer still 30, payee still 0, and neither over: ref landed.
        $this->assertEqualsWithDelta(30.0, LedgerService::balanceFor($payer), 0.001);
        $this->assertEqualsWithDelta(0.0, LedgerService::balanceFor($payee), 0.001);
        $this->assertSame(0, LedgerEntry::where('ref', 'like', 'over:%')->count());
    }

    public function test_reverse_posts_inverse_and_links(): void
    {
        $src = $this->acct('platform', 0);
        $dst = $this->acct('merchant', 1);
        $tx = LedgerService::post([
            ['account' => $src, 'amount' => -40, 'allow_negative' => true, 'ref' => 'm:src'],
            ['account' => $dst, 'amount' => 40, 'ref' => 'm:dst'],
        ]);

        $rev = LedgerService::reverse($tx, 'refund');

        $this->assertEqualsWithDelta(0.0, LedgerService::balanceFor($dst), 0.001, 'balance restored');
        $this->assertEqualsWithDelta(0.0, LedgerService::balanceFor($src), 0.001);
        $this->assertSame(2, LedgerEntry::where('transaction_id', $rev)->count());
        // originals now point at their reversals
        $this->assertSame(0, LedgerEntry::where('transaction_id', $tx)->whereNull('reversed_by')->count());
    }

    public function test_double_reverse_throws(): void
    {
        $src = $this->acct('platform', 0);
        $dst = $this->acct('merchant', 1);
        $tx = LedgerService::post([
            ['account' => $src, 'amount' => -10, 'allow_negative' => true, 'ref' => 'x:src'],
            ['account' => $dst, 'amount' => 10, 'ref' => 'x:dst'],
        ]);
        LedgerService::reverse($tx);

        $this->expectException(\RuntimeException::class);
        LedgerService::reverse($tx);
    }

    public function test_single_currency_per_move(): void
    {
        $usd = LedgerService::accountFor('platform', 0, 'platform', 'USD');
        $eur = LedgerService::accountFor('merchant', 1, 'merchant', 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        LedgerService::post([
            ['account' => $usd, 'amount' => -10, 'allow_negative' => true],
            ['account' => $eur, 'amount' => 10],
        ]);
    }

    public function test_post_needs_at_least_two_legs(): void
    {
        $dst = $this->acct('merchant', 1);
        $this->expectException(\InvalidArgumentException::class);
        LedgerService::post([['account' => $dst, 'amount' => 10]]);
    }

    public function test_transfer_moves_funds_and_is_idempotent(): void
    {
        $funded = $this->acct('merchant', 1);
        $other = $this->acct('merchant', 2);
        LedgerService::post([
            ['account' => $this->acct('platform', 0), 'amount' => -100, 'allow_negative' => true, 'ref' => 'seed:a'],
            ['account' => $funded, 'amount' => 100, 'ref' => 'seed:b'],
        ]);

        LedgerService::transfer($funded, $other, 40, 'move:1', 'fund');
        LedgerService::transfer($funded, $other, 40, 'move:1', 'fund'); // replay → no-op

        $this->assertEqualsWithDelta(60.0, LedgerService::balanceFor($funded), 0.001);
        $this->assertEqualsWithDelta(40.0, LedgerService::balanceFor($other), 0.001);
    }

    public function test_balances_are_separate_per_currency(): void
    {
        $usd = LedgerService::accountFor('merchant', 5, 'merchant', 'USD');
        $eur = LedgerService::accountFor('merchant', 5, 'merchant', 'EUR');
        $this->assertNotSame($usd->id, $eur->id, 'one account per currency');

        LedgerService::post([
            ['account' => LedgerService::accountFor('platform', 0, 'platform', 'USD'), 'amount' => -10, 'allow_negative' => true, 'ref' => 'cur:1'],
            ['account' => $usd, 'amount' => 10, 'ref' => 'cur:2'],
        ]);

        $this->assertEqualsWithDelta(10.0, LedgerService::balanceFor($usd), 0.001);
        $this->assertEqualsWithDelta(0.0, LedgerService::balanceFor($eur), 0.001, 'EUR balance untouched by a USD move');
    }

    public function test_default_currency_follows_setting(): void
    {
        config(['settings.currency_code' => 'GBP']);

        $this->assertSame('GBP', LedgerService::defaultCurrency());
        $acct = LedgerService::accountFor('merchant', 9, 'merchant'); // no currency arg
        $this->assertSame('GBP', $acct->currency);
    }

    public function test_transfer_insufficient_funds_rolls_back(): void
    {
        $from = $this->acct('merchant', 1); // empty
        $to = $this->acct('merchant', 2);

        try {
            LedgerService::transfer($from, $to, 25, 'bad:1');
            $this->fail('expected insufficient-funds');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertEqualsWithDelta(0.0, LedgerService::balanceFor($to), 0.001);
        $this->assertSame(0, LedgerEntry::where('ref', 'like', 'bad:%')->count());
    }
}
