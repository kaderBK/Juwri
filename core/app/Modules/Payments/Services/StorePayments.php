<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use App\Models\CateringRequest;
use App\Models\GiftCard;
use App\Models\Reservation;
use App\Services\Catering\CateringService;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\ReservationDepositService;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Contracts\SettlementHandlerMissing;
use Juwri\Modules\Payments\Contracts\StoreCredits;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-13 13g: what a confirmed payment of a business's OWN customers buys — a diner's store-credit top-up, a gift card,
 * a reservation or catering deposit. They settle through the same choke point as platform purchases (Billing's
 * CheckoutController::updateUserPlan, which calls {@see settle()} at the place these branches were), and keep working
 * in the Community edition without Billing ({@see StoreTransactionFulfiller}). Moved verbatim from Billing (same order,
 * same idempotency, same outbox message type for the gift-card delivery).
 */
final class StorePayments implements StoreCredits
{
    /** Transaction methods settled here. */
    public const METHODS = ['cust_wallet_topup', 'gift_card_purchase', 'reservation_deposit', 'catering_deposit', 'booking_deposit'];

    /**
     * P3-10: store payments credited by a module ABOVE Payments (a booking deposit: Bookings), registered by that
     * module's provider — Payments never imports it. A method in METHODS with no handler is left unsettled
     * (SettlementHandlerMissing), like an ee payment without its module.
     *
     * @var array<string, callable(Transaction): void>
     */
    private static array $handlers = [];

    /** Payments only an ee module (other than Billing) credits: method => that module (named when it is refused). */
    public const EE_MODULES = ['mk_wallet_topup' => 'Marketplace', 'reseller_topup' => 'Reseller', 'reseller_tier' => 'Reseller', 'reseller_invoice' => 'Reseller'];

    /** Outbox message type of a gift card's delivery (was Billing's PaidPlanSideEffects::GIFT_CARD_DELIVERY — kept). */
    public const GIFT_CARD_DELIVERY = StoreCredits::GIFT_CARD_DELIVERY;

    public function credit(Transaction $transaction): bool
    {
        return self::settle($transaction);
    }

    public function settleWith(string $method, callable $handler): void
    {
        self::$handlers[$method] = $handler;
    }

    public static function handles(Transaction $transaction): bool
    {
        return in_array($transaction->transaction_method, self::METHODS, true);
    }

    /** Credit a confirmed store payment (after the caller's credited_at claim). False when it is not a store payment. */
    public static function settle(Transaction $transaction): bool
    {
        /* Customer store-credit top-up: a confirmed charge adds spendable balance
           to the diner's per-store wallet (CustomerWalletService). Same choke
           point + credited_at idempotency as every other top-up. */
        if ($transaction->transaction_method === 'cust_wallet_topup') {
            CustomerWalletService::creditTopupFromTransaction($transaction);

            return true;
        }

        /* T2 gift cards: a confirmed purchase funds + activates the pending card
           (ledger-backed) instead of changing a plan. Idempotent per transaction. */
        if ($transaction->transaction_method === 'gift_card_purchase') {
            self::activateGiftCardPurchase($transaction);

            return true;
        }

        /* T5 reservation deposit: a confirmed deposit payment holds the funds and
           confirms the reservation. Idempotent per transaction. */
        if ($transaction->transaction_method === 'reservation_deposit') {
            $reservation = Reservation::where('deposit_transaction_id', $transaction->id)->first();
            if ($reservation) {
                ReservationDepositService::markPaid($reservation, $transaction);
            }

            return true;
        }

        /* B8 catering deposit: a confirmed deposit payment holds the funds against the
           catering request. Idempotent per transaction (markDepositPaid guards on
           deposit_status + a unique ledger ref). Mirrors reservation_deposit above. */
        if ($transaction->transaction_method === 'catering_deposit') {
            $cateringRequest = CateringRequest::where('deposit_transaction_id', $transaction->id)->first();
            if ($cateringRequest) {
                app(CateringService::class)->markDepositPaid($cateringRequest, $transaction);
            }

            return true;
        }

        if (isset(self::$handlers[$transaction->transaction_method])) {
            (self::$handlers[$transaction->transaction_method])($transaction);

            return true;
        }
        if ($transaction->transaction_method === 'booking_deposit') {
            // its module is not booted: leave it unsettled for a retry (the caller rolls back the credited_at claim)
            throw new SettlementHandlerMissing('Payment #'.$transaction->id.' left unsettled: no handler for booking_deposit', 'Bookings');
        }

        return false;
    }

    public static function activateGiftCardPurchase(Transaction $transaction): void
    {
        $card = GiftCard::where('transaction_id', $transaction->id)->first();
        if (! $card) {
            return;
        }

        GiftCardService::activate($card, $transaction);

        // T2-P3: deliver now (or defer to the scheduled tick for a future send date). J25-7: through the outbox, after
        // the credit commits, once per card; a failed send is still retried by giftcards:deliver-scheduled.
        self::deliverGiftCard($card);

        log_activity('giftcard.purchased', $transaction->user, [
            'gift_card_id' => $card->id,
            'amount' => $transaction->amount,
            'transaction_id' => $transaction->id,
        ], null);
    }

    /** J25-7: one outbox message per card (dedupe key); GiftCardService::deliver() itself skips a delivered card. */
    public static function deliverGiftCard(GiftCard $card): void
    {
        $outbox = app(Outbox::class);
        $id = $outbox->record(self::GIFT_CARD_DELIVERY, ['gift_card_id' => (int) $card->id], self::GIFT_CARD_DELIVERY.':'.$card->id);
        $outbox->relayAfterCommit($id === null ? [] : [$id]);
    }

    public static function register(Outbox $outbox): void
    {
        // T2-P3: deliver now (or leave it to giftcards:deliver-scheduled for a future send date / a failed send).
        $outbox->handle(self::GIFT_CARD_DELIVERY, function (array $p): void {
            if ($card = GiftCard::find((int) ($p['gift_card_id'] ?? 0))) {
                GiftCardService::deliver($card);
            }
        }, sendOnce: true); // P6-158: GiftCardService::deliver() guards the email with SendOnce
    }
}
