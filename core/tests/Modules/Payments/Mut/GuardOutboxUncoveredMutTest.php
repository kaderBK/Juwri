<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Mut;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P6-128: the code paths `composer mutate:payments` reported UNCOVERED on 2026-10-13 — RefundGuard's cap re-check under
 * the row lock (a concurrent refund moved the cap after the first check), the report of a failed "outcome unknown"
 * mark, and the outbox relay going on past a message another relay claimed first.
 */
class GuardOutboxUncoveredMutTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function paidUsd(float $amount = 30.0): Transaction
    {
        [, $tx] = $this->makePlanTransaction($amount, 'USD');
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'stripe'])->save();

        return $tx->fresh();
    }

    public function test_the_cap_is_rechecked_under_the_row_lock(): void
    {
        $tx = $this->paidUsd(30.0);
        // A concurrent refund of 25.00 lands after this caller loaded the row: its own copy still shows nothing refunded.
        $row = Transaction::find($tx->id);
        $row->forceFill(['details' => ['gateway_refunds' => [[
            'id' => 'other', 'gateway' => 'stripe', 'amount_minor' => 2500, 'currency' => 'USD', 'status' => 'ok',
        ]]]])->save();

        $sent = 0;
        $res = RefundGuard::run($tx, 10.0, 'USD', 'stripe', 2, function () use (&$sent) {
            $sent++;

            return ['ok' => true, 'reference' => 'r'];
        });

        $this->assertSame(0, $sent, 'nothing is sent past the re-checked cap');
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['refused']);
        $this->assertSame('The refund is more than what is left to refund on this payment (5.00 USD).', $res['message']);
        $this->assertCount(1, (array) $tx->fresh()->details->gateway_refunds);
    }

    public function test_a_failed_unknown_mark_is_reported_and_the_entry_stays_pending(): void
    {
        Exceptions::fake();
        $tx = $this->paidUsd(30.0);

        $res = RefundGuard::run($tx, 10.0, 'USD', 'stripe', 2, function () {
            Transaction::saving(function (): void {
                throw new \RuntimeException('ledger write refused');
            });

            throw new \RuntimeException('provider timeout');
        });

        $this->assertFalse($res['ok']);
        $this->assertArrayNotHasKey('refused', $res);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'ledger write refused');
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'provider timeout');

        $details = json_decode((string) Transaction::query()->whereKey($tx->id)->toBase()->value('details'), true);
        $this->assertSame('pending', $details['gateway_refunds'][0]['status'], 'still counted against the cap');
        $this->assertArrayNotHasKey('unknown', $details['gateway_refunds'][0]);
    }

    public function test_relay_goes_on_past_a_message_another_relay_claimed(): void
    {
        $outbox = new TransactionalOutbox;
        $ran = [];
        $ids = [];
        $outbox->handle('mut.race', function (array $p) use (&$ran, &$ids): void {
            $ran[] = $p['n'];
            if ($p['n'] === 1) {
                // another relay claims message 2 while this one performs message 1
                DB::table(TransactionalOutbox::TABLE)->where('id', $ids[1])->update(['status' => 'processing', 'claimed_at' => now()]);
            }
        });
        $ids[] = $outbox->record('mut.race', ['n' => 1]);
        $ids[] = $outbox->record('mut.race', ['n' => 2]);
        $ids[] = $outbox->record('mut.race', ['n' => 3]);

        $this->assertSame(2, $outbox->relay($ids));
        $this->assertSame([1, 3], $ran);
        $this->assertSame(['done', 'processing', 'done'], array_map(fn (int $id) => $outbox->status($id), $ids));
    }

    /**
     * The gateway currency tables, entry by entry. pcov never marks a class-constant line covered, so the mutation run
     * lists every mutant of these tables UNCOVERED whatever the tests do; this pins them exactly (each mutant fails it).
     */
    public function test_gateway_currency_tables_are_pinned_exactly(): void
    {
        $this->assertSame(['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], GatewayMinorUnits::ZERO_DECIMAL);
        $this->assertSame(['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], GatewayMinorUnits::THREE_DECIMAL);
        $this->assertSame(['paypal' => ['HUF', 'JPY', 'TWD'], 'midtrans' => ['IDR'], 'mollie' => ['ISK']], GatewayMinorUnits::GATEWAY_ZERO_DECIMAL);
        $this->assertSame(['geidea' => 2, 'paylink' => 2, 'fawry' => 2, 'paymob' => 2], GatewayMinorUnits::GATEWAY_MAX_DECIMALS);
        $three = ['BHD' => 2, 'IQD' => 2, 'JOD' => 2, 'KWD' => 2, 'LYD' => 2, 'OMR' => 2, 'TND' => 2];
        $this->assertSame([
            'stripe' => ['BHD' => 2, 'JOD' => 2, 'KWD' => 2, 'OMR' => 2, 'TND' => 2, 'ISK' => 0],
            'razorpay' => $three,
            'lemon_squeezy' => $three,
            'mollie' => ['HUF' => 0, 'TWD' => 0],
        ], GatewayMinorUnits::GATEWAY_STEP_DECIMALS);
        $this->assertSame(['stripe' => ['UGX']], GatewayMinorUnits::GATEWAY_UNSUPPORTED);
        $this->assertSame(10, TransactionalOutbox::MAX_ATTEMPTS);
        $this->assertSame(10, TransactionalOutbox::STALE_AFTER_MINUTES);
    }
}
