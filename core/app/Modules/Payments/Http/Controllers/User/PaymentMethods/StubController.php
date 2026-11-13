<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Support\Fulfilment;
use Symfony\Component\HttpFoundation\Response;

/**
 * E2E-only fake payment gateway (rev/platform-e2e-v2 V0.2).
 *
 * Lets a v2 checkout journey drive a gateway outcome — success / failure — with
 * NO real gateway, keys, or charge. Reached exactly like any other method:
 * CheckoutController posts `payment_method=stub`, PaymentController::pay()
 * dispatches to `PaymentMethods\StubController::pay()`.
 *
 * PROD SAFETY (double-guarded, can never settle money in production):
 *   1. pay() abort(404)s unless app()->environment(['local','testing']) AND
 *      env('E2E_STUBS') is truthy. In production the guard is false → 404.
 *   2. It is only DISPATCHABLE when a seeded `stub` row exists in
 *      payment_gateways with payment_install='1' — seeded ONLY into E2E databases
 *      by E2ePlatformV2Seeder, never in production.
 *
 * Outcome is chosen by the `e2e_outcome` request field (default `success`):
 *   - success: an ORDER settles through the REAL PaymentController::paySuccess() (marks the order paid, credits the
 *     wallet, accrues loyalty, writes the receipt, fires order.paid) so ledger/order assertions are against the
 *     real path; any other payment (wallet top-up, plan, add-on) settles through Settlement::settleForBuyer()
 *     like a real gateway's confirmation. Partial-refund is then exercised via the app's own refund flow on the
 *     resulting paid order (the stub never refunds by itself).
 *   - fail: leaves the transaction PENDING and the order unpaid, and bounces the
 *     customer back to the store with an error — the "declined" branch.
 */
class StubController extends Controller
{
    /** Only local/testing with E2E_STUBS armed may ever reach the gateway. */
    private static function guardOrAbort(): void
    {
        $armed = app()->environment(['local', 'testing']) && filter_var(config('app.e2e_stubs'), FILTER_VALIDATE_BOOLEAN);
        if (! $armed) {
            abort(404);
        }
    }

    /**
     * Process the (fake) payment.
     */
    public static function pay(Transaction $transaction): Response
    {
        self::guardOrAbort();

        $outcome = strtolower((string) request()->input('e2e_outcome', 'success'));
        $store = Store::find($transaction->store_id);

        if ($outcome === 'fail' || $outcome === 'failure' || $outcome === 'decline' || $outcome === 'declined') {
            // Declined: record the attempt, settle nothing, bounce back.
            $transaction->update(['transaction_gatway' => 'stub', 'status' => Transaction::STATUS_PENDING]);
            app_alert_error(___('Payment was declined.'));

            return $store
                ? redirect()->route('publicView', $store->slug)
                : redirect('/');
        }

        /* P3-09: a weighed basket's card hold — authorised now, captured after weighing (CardHolds; the stub holds,
           captures and voids in-process), never settled as a payment here. */
        if (Settlement::isOrder($transaction) && data_get($transaction->details, 'capture') === 'manual') {
            if (! Settlement::authorise($transaction, 'stub', 'stub-auth-'.$transaction->id, now()->addDays(7))) {
                return redirect()->to(Settlement::cancelUrl($transaction));
            }

            return response(app(OrderPaymentHandler::class)->buyerPage($transaction->fresh() ?? $transaction));
        }

        /* P1-12 (journey h): anything that is not a store order — a plan subscription, an add-on, a wallet top-up,
           a gift card, a deposit — settles through the SAME path a live gateway return takes (Settlement: claim the
           pending row, outbox credit → Fulfilment::fulfil → updateUserPlan & co.). It used to be handed to the ORDER
           success path, which treated product_id (the plan id) as an order id: the plan was never activated and an
           unrelated order with that id could be marked paid. */
        if (! Settlement::isOrder($transaction)) {
            return Settlement::settleForBuyer($transaction, 'stub', 'stub-'.$transaction->id, claim: true)
                ?? redirect()->to(Settlement::cancelUrl($transaction));
        }

        // Success: settle through the real gateway-callback path so every side
        // effect (order paid, wallet credit, loyalty, receipt, webhooks) fires
        // exactly as a live gateway return would. paySuccess() echoes a client
        // redirect to the order-track page; capture it and return as the body.
        $transaction->update(['transaction_gatway' => 'stub']);

        ob_start();
        Fulfilment::orderPaid($transaction);
        $html = ob_get_clean();

        return response($html !== false ? $html : '');
    }
}
