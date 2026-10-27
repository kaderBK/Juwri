<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Mut;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\CardHolds;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P6-28: mutation killers for Settlement (card-hold authorise, credit relay failure, buyer return), RefundGuard
 * (fixed-currency gateways, a ledger in another currency, ignored failed entries) and TransactionalOutbox (relay
 * filters, last error).
 */
class SettlementGuardOutboxMutTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function spyHandler(): object
    {
        $real = app(OrderPaymentHandler::class);
        $spy = new class($real) implements OrderPaymentHandler
        {
            /** @var list<int> */
            public array $authorised = [];

            public function __construct(private OrderPaymentHandler $real) {}

            public function paid(Transaction $transaction): mixed
            {
                return $this->real->paid($transaction);
            }

            public function buyerPage(Transaction $transaction): string
            {
                return $this->real->buyerPage($transaction);
            }

            public function authorised(Transaction $transaction): void
            {
                $this->authorised[] = (int) $transaction->getKey();
            }
        };
        app()->instance(OrderPaymentHandler::class, $spy);

        return $spy;
    }

    public function test_authorise_claims_a_pending_order_once_with_reference_amount_and_utc_expiry(): void
    {
        $spy = $this->spyHandler();
        [, , $tx] = $this->makeOrderTransaction('stripe', 42.5, [], 'USD');
        $expires = new \DateTimeImmutable('2026-10-20 15:00:00', new \DateTimeZone('Asia/Qatar'));

        $this->assertTrue(Settlement::authorise($tx, 'stripe', 'pi_hold_1', $expires));
        $fresh = $tx->fresh();
        $this->assertSame(CardHolds::STATUS_AUTHORISED, $fresh->status);
        $this->assertSame('stripe', $fresh->transaction_gatway);
        $this->assertSame('pi_hold_1', $fresh->payment_id);
        $this->assertSame(4250, $fresh->details->authorised_minor);
        $this->assertSame('2026-10-20T12:00:00+00:00', $fresh->details->authorisation_expires_at);
        $this->assertSame([(int) $tx->id], $spy->authorised);

        // a racing second confirmation records nothing
        $this->assertFalse(Settlement::authorise($tx->fresh(), 'stripe', 'pi_hold_2'));
        $this->assertSame('pi_hold_1', $tx->fresh()->payment_id);
        $this->assertSame([(int) $tx->id], $spy->authorised);
    }

    public function test_authorise_without_reference_or_expiry_keeps_them_unset(): void
    {
        $spy = $this->spyHandler();
        [, , $tx] = $this->makeOrderTransaction('stripe', 10.0, [], 'USD');
        $tx->forceFill(['payment_id' => 'cs_keep'])->save();

        $this->assertTrue(Settlement::authorise($tx, 'stripe'));
        $fresh = $tx->fresh();
        $this->assertSame('cs_keep', $fresh->payment_id);
        $this->assertSame(1000, $fresh->details->authorised_minor);
        $this->assertObjectNotHasProperty('authorisation_expires_at', $fresh->details);
        $this->assertCount(1, $spy->authorised);
    }

    public function test_authorise_refuses_a_platform_payment(): void
    {
        $spy = $this->spyHandler();
        [, $tx] = $this->makePlanTransaction(10.0, 'USD');
        $this->assertFalse(Settlement::authorise($tx, 'stripe', 'pi_x'));
        $this->assertNull($tx->fresh()->status);
        $this->assertSame([], $spy->authorised);
    }

    public function test_a_credit_that_fails_to_relay_throws_with_the_last_error(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'USD');
        app(Outbox::class)->handle(Settlement::CREDIT, function (): void {
            throw new \RuntimeException('ledger offline');
        });

        try {
            Settlement::settle($tx, 'stripe', 'pi_r');
            $this->fail('expected the pending credit to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('credit pending retry: RuntimeException: ledger offline', $e->getMessage());
        }
    }

    public function test_settle_for_buyer_returns_null_when_another_confirmation_won(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'USD');
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS])->save();
        $this->assertNull(Settlement::settleForBuyer($tx->fresh(), 'stripe', 'pi_dup', 'subscription', true));
    }

    public function test_a_store_payment_follows_a_headless_client_return_url_only_when_http(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 10.0, [], 'USD');
        $tx->forceFill(['transaction_method' => 'booking_deposit', 'product_id' => null, 'status' => Transaction::STATUS_SUCCESS,
            'details' => ['client_return_url' => 'https://client.example/done']])->save();
        $this->assertSame('https://client.example/done', Settlement::completeForBuyer($tx->fresh())->headers->get('Location'));

        $tx->forceFill(['details' => ['client_return_url' => 'javascript:alert(1)']])->save();
        $this->assertSame(route('publicView', $store->slug), Settlement::completeForBuyer($tx->fresh())->headers->get('Location'));
    }

    public function test_a_store_order_completes_with_its_order_page_not_a_redirect(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 10.0, [], 'USD');
        $order->forceFill(['is_paid' => 1, 'status' => 'pending'])->save();
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS])->save();

        $res = Settlement::completeForBuyer($tx->fresh());
        $this->assertFalse($res->isRedirection());
    }

    private function paidUsd(float $amount = 30.0, array $details = []): Transaction
    {
        [, $tx] = $this->makePlanTransaction($amount, 'USD');
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'stripe', 'details' => $details ?: null])->save();

        return $tx->fresh();
    }

    public function test_a_fixed_currency_gateway_refuses_a_payment_in_another_currency(): void
    {
        $sent = 0;
        $res = RefundGuard::run($this->paidUsd(), 5.0, null, 'paytm', 2, function () use (&$sent) {
            $sent++;

            return ['ok' => true, 'reference' => 'x'];
        });
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['refused']);
        $this->assertSame('This payment method cannot charge in USD. Please choose another payment method.', $res['message']);
        $this->assertSame(0, $sent);
    }

    public function test_a_ledger_in_another_currency_refuses_and_failed_entries_are_ignored(): void
    {
        $send = fn () => ['ok' => true, 'reference' => 'r'];
        $eur = $this->paidUsd(30.0, ['gateway_refunds' => [['status' => 'ok', 'currency' => 'EUR', 'minor' => 100]]]);
        $res = RefundGuard::run($eur, 5.0, 'USD', 'stripe', 2, $send);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['refused']);
        $this->assertSame('This payment has refunds in another currency. Refund it in your payment provider dashboard.', $res['message']);
        $this->assertNull(RefundGuard::refundedMinor($eur));

        $failed = $this->paidUsd(30.0, ['gateway_refunds' => [['status' => 'failed', 'currency' => 'EUR', 'minor' => 100], 'junk']]);
        $this->assertSame(0, RefundGuard::refundedMinor($failed));
        $this->assertTrue(RefundGuard::run($failed, 30.0, 'usd', 'stripe', 2, $send)['ok']);
    }

    public function test_outbox_relay_ignores_non_positive_ids_and_unhandled_types_and_reports_last_error(): void
    {
        $outbox = new TransactionalOutbox;
        $this->assertSame(10, TransactionalOutbox::MAX_ATTEMPTS);
        $this->assertSame(10, TransactionalOutbox::STALE_AFTER_MINUTES);

        $ran = [];
        $outbox->handle('mut.ok', function (array $p) use (&$ran): void {
            $ran[] = $p['n'];
        });
        $unhandled = $outbox->record('mut.none', ['n' => 0]);
        $ok = $outbox->record('mut.ok', ['n' => 1]);

        $this->assertSame(0, $outbox->relay([0, -3]));
        $this->assertSame([], $ran);
        $this->assertSame(1, $outbox->relay([$unhandled, $ok]));
        $this->assertSame([1], $ran);
        $this->assertSame('pending', $outbox->status($unhandled));
        $this->assertNull($outbox->lastError($ok));
        $this->assertNull($outbox->lastError(999999));

        DB::table(TransactionalOutbox::TABLE)->where('id', $unhandled)->update(['last_error' => 'E: boom']);
        $this->assertSame('E: boom', $outbox->lastError($unhandled));
    }
}
