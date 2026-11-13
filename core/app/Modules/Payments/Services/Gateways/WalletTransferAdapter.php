<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Models\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * Syria A1 wallet_transfer — a MANUAL wallet tender (Syriatel Cash / MTN Cash / Sham Cash, reusable for any market).
 * P1-23 part 6: moved from the hand-written WalletTransferController into the adapter family.
 *
 * Nothing is verified against a remote API: initiate() marks the transaction PENDING and shows the STORE's wallet
 * numbers + instructions; the customer transfers the money in their wallet app and submits the wallet + transaction id
 * CLAIM (a claim, never proof). Only the STORE OWNER settles it, after checking their wallet SMS:
 *  - a store order: Order Hub → ManagesOrders::confirmWalletTransfer → paySuccess (unchanged; claim route
 *    `payment.claim`);
 *  - a store payment (gift card, store-credit top-up, reservation / catering deposit): Order Hub "Transfers to confirm"
 *    → {@see StoreTransfers::confirm()} (claim route `storeTransfer.claim`, signed).
 * A platform purchase is refused: the money would go to a store's wallet, and the platform has no wallet numbers.
 * The return / notification entry points stay safe no-ops (no provider ever calls them). No refunds.
 */
class WalletTransferAdapter implements GatewayAdapter
{
    public function key(): string
    {
        return 'wallet_transfer';
    }

    public function initiate(Transaction $transaction): Response
    {
        $store = Settlement::usesStoreGateway($transaction) ? Store::find($transaction->store_id) : null;
        if ($store === null) {
            app_alert_error(___('This payment method is not available right now.'));

            return redirect(route('home'));
        }

        $postOptions = store_options($store->id);
        if (Settlement::isStorePayment($transaction) && (empty($postOptions->restaurant_wallet_transfer_install) || ! $this->hasWallet($postOptions))) {
            app_alert_error(___('This payment method is not configured.'));

            return redirect(Settlement::cancelUrl($transaction));
        }

        $transaction->update(['transaction_gatway' => 'wallet_transfer', 'status' => Transaction::STATUS_PENDING]);

        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;
        // The amount in the transaction's own currency, with the store's sign / position.
        Settlement::useStoreCurrency($transaction);

        $isOrder = Settlement::isOrder($transaction); // the order claim route vs the signed store-payment one

        return response(view('store_templates.gateways.wallet-transfer', [
            'transaction' => $transaction,
            'store' => $store,
            'postOptions' => $postOptions,
            'theme' => $theme,
            'isOrder' => $isOrder,
            'claimUrl' => $isOrder
                ? URL::signedRoute('payment.claim', ['transaction' => $transaction->id])
                : URL::signedRoute('storeTransfer.claim', ['transaction' => $transaction->id]),
        ]));
    }

    /** No gateway callback for a manual tender: back to the store the payment was for. */
    public function handleReturn(Request $request): Response
    {
        $id = $request->input('order_id') ?: $request->input('order_number');
        $transaction = is_scalar($id) && ctype_digit((string) $id) ? Transaction::find($id) : null;
        $store = $transaction !== null ? Settlement::store($transaction) : null;

        return redirect($store !== null ? route('publicView', $store->slug) : route('home'));
    }

    /** No provider ever calls this for a manual tender; acknowledge and do nothing. */
    public function handleWebhook(Request $request): Response
    {
        return response('ok', 200);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return Settlement::noAutomaticRefund();
    }

    private function hasWallet(object $options): bool
    {
        foreach (['syriatel_number', 'mtn_number', 'shamcash_number'] as $field) {
            if (trim((string) ($options->{'restaurant_wallet_transfer_'.$field} ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
