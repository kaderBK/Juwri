<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Support\AccountPages;
use Symfony\Component\HttpFoundation\Response;

/**
 * Offline bank (wire) transfer (WI-06c). P1-23 part 6: moved from the hand-written WireTransferController into the
 * adapter family. No provider: the payment stays PENDING until the party whose bank account receives the money
 * confirms it — never the other party:
 *  - a platform purchase (plan, add-on, platform wallet top-up): the payer sees the PLATFORM's bank details
 *    (`company_bank_info`), the superadmin is notified and marks it paid (Admin → Transactions), as before;
 *  - a store payment (gift card, store-credit top-up, reservation / catering deposit): the customer sees the STORE's
 *    bank details (`restaurant_wire_transfer_bank_info`, never the platform's), nothing is sent to the platform, and
 *    only the store owner confirms it (Order Hub "Transfers to confirm", {@see StoreTransfers::confirm()}); the admin
 *    "mark as paid" refuses it. A store that has not set its bank details / switched it on is refused;
 *  - a store order: not offered (refused, back to the pay page).
 * No return or webhook (404, as before). No refunds.
 */
class WireTransferAdapter implements GatewayAdapter
{
    public function key(): string
    {
        return 'wire_transfer';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (Settlement::isOrder($transaction)) {
            app_alert_error(___('This payment method is not available right now.'));

            return redirect(Settlement::orderPageUrl($transaction));
        }
        if (Settlement::isStorePayment($transaction)) {
            return $this->storePayment($transaction);
        }

        $transaction->update(['transaction_gatway' => 'wire_transfer', 'status' => Transaction::STATUS_PENDING]);
        create_notification(
            ___('New offline payment request is pending.'),
            'new_payment',
            AccountPages::url('admin.transactions.index', 'admin.dashboard')
        );

        return response(view('owner.billing.gateways.wire-transfer', ['transaction' => $transaction]));
    }

    public function handleReturn(Request $request): Response
    {
        return response('', 404);
    }

    public function handleWebhook(Request $request): Response
    {
        return response('', 404);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return Settlement::noAutomaticRefund();
    }

    /** The store's own bank details; the store owner confirms (the platform is not told and cannot confirm). */
    private function storePayment(Transaction $transaction): Response
    {
        $store = Store::find($transaction->store_id);
        $postOptions = $store !== null ? store_options($store->id) : null;
        $bankInfo = trim((string) ($postOptions->restaurant_wire_transfer_bank_info ?? ''));
        if ($store === null || $postOptions === null || empty($postOptions->restaurant_wire_transfer_install) || $bankInfo === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect(Settlement::cancelUrl($transaction));
        }

        $transaction->update(['transaction_gatway' => 'wire_transfer', 'status' => Transaction::STATUS_PENDING]);

        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;
        Settlement::useStoreCurrency($transaction);

        return response(view('store_templates.gateways.bank-transfer', [
            'transaction' => $transaction,
            'store' => $store,
            'postOptions' => $postOptions,
            'theme' => $theme,
            'bankInfo' => $bankInfo,
        ]));
    }
}
