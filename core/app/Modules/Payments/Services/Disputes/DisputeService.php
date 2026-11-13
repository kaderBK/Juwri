<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Disputes;

use App\Models\Order;
use App\Services\SellerPayoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;

/**
 * GAPS-RUN-2 B11-P1: ingest gateway dispute events. Idempotent per (gateway,
 * external_id); resolves the tenant from the linked transaction/order (never from
 * unverified payload trust beyond the dispute object itself, which arrives on a
 * signature-verified webhook). Fires an owner alert on first `created`.
 */
class DisputeService
{
    /**
     * Upsert a Stripe dispute from a decoded event. $event = the Stripe event as an
     * array/object; the dispute is at data.object.
     */
    public function upsertFromStripe($event): ?Dispute
    {
        $event = json_decode(json_encode($event), true); // normalize to array
        $type = $event['type'] ?? '';
        $obj = $event['data']['object'] ?? [];
        $externalId = $obj['id'] ?? null;
        if (! $externalId) {
            return null;
        }

        [$storeId, $transactionId, $orderId] = $this->resolveTenant($obj);

        $existing = Dispute::where('gateway', 'stripe')->where('external_id', $externalId)->first();
        $wasNew = $existing === null;

        $dispute = Dispute::updateOrCreate(
            ['gateway' => 'stripe', 'external_id' => $externalId],
            array_filter([
                'store_id' => $storeId,
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'amount' => isset($obj['amount']) ? round(((int) $obj['amount']) / (10 ** GatewayMinorUnits::decimals($obj['currency'] ?? null)), GatewayMinorUnits::decimals($obj['currency'] ?? null)) : 0,
                'currency' => isset($obj['currency']) ? strtoupper((string) $obj['currency']) : null,
                'status' => $obj['status'] ?? null,
                'reason' => $obj['reason'] ?? null,
                'evidence_due_at' => isset($obj['evidence_details']['due_by']) ? Carbon::createFromTimestamp((int) $obj['evidence_details']['due_by']) : null,
                'opened_at' => isset($obj['created']) ? Carbon::createFromTimestamp((int) $obj['created']) : null,
                'closed_at' => ($type === 'charge.dispute.closed') ? now() : null,
            ], fn ($v) => $v !== null),
        );

        if ($wasNew && $type === 'charge.dispute.created') {
            $this->alert($dispute);
        }

        // P6-243: a charged-back platform purchase takes back what it bought (idempotent per dispute ref).
        if ($transactionId !== null) {
            try {
                $this->applyChargeback($dispute, $type, (string) ($obj['status'] ?? ''));
            } catch (\Throwable $e) {
                report($e);
                AlertDispatcher::send('payment', 'chargeback for transaction #'.$transactionId.' not applied: '.$e->getMessage());
            }
        }

        return $dispute;
    }

    /**
     * P6-243: money side of a dispute on a PLATFORM payment.
     *   - wallet top-up: the credited balance is debited back once the funds are withdrawn (opened dispute); if the
     *     owner already spent it, nothing is debited and the admin is alerted. A dispute closed `won` gives it back.
     *   - card app purchase: the developer's share is reversed through the same step as an admin refund
     *     ({@see SellerPayoutService::reverseOrFlag()}).
     * Every step is keyed on the dispute's external id, so replayed webhooks change nothing.
     */
    private function applyChargeback(Dispute $dispute, string $type, string $status): void
    {
        $tx = Transaction::find($dispute->transaction_id);
        if (! $tx || $tx->store_id !== null) {
            return; // order payments are the store's own dispute workflow
        }
        $ref = 'chargeback:'.$dispute->gateway.':'.$dispute->external_id;
        $won = $type === 'charge.dispute.closed' && $status === 'won';

        if ((string) $tx->transaction_method === 'wallet_topup') {
            $user = User::find($tx->user_id);
            $credited = WalletTransaction::where('ref', 'topup-txn-'.$tx->id)->exists();
            if (! $user || ! $credited) {
                return;
            }
            $debited = WalletTransaction::where('ref', $ref)->exists();
            $amount = (float) $tx->amount;
            $currency = $tx->currency_code ?: null;

            if ($won) {
                if ($debited) {
                    app(Wallet::class)->creditBack($user, $amount, (string) ($currency ?: app(Wallet::class)->currency()), 'chargeback won: transaction #'.$tx->id, $ref.':won');
                }

                return;
            }
            if ($debited || $status === 'won' || str_starts_with($status, 'warning')) { // an inquiry withdraws no funds
                return;
            }
            if (app(Wallet::class)->balanceFor($user, $currency) >= $amount) {
                app(Wallet::class)->debit($user, $amount, 'chargeback: transaction #'.$tx->id, $ref, $currency);
                log_activity('wallet.chargeback', $user, ['transaction_id' => $tx->id, 'amount' => $amount, 'dispute' => $dispute->external_id], null);

                return;
            }
            if ($dispute->wasRecentlyCreated || $type === 'charge.dispute.created') {
                log_activity('wallet.chargeback_unrecovered', $user, ['transaction_id' => $tx->id, 'amount' => $amount, 'dispute' => $dispute->external_id], null);
                AlertDispatcher::send('payment', "chargeback on wallet top-up #{$tx->id}: user #{$user->id} already spent the {$amount} {$tx->currency_code}; nothing debited — review the account");
            }

            return;
        }

        if ((string) $tx->transaction_method === 'app_purchase' && ! $won && ! str_starts_with($status, 'warning')) {
            foreach (array_filter([
                (string) (data_get($tx->details, 'payout_ref') ?? ''),
                (string) (data_get($tx->details, 'wallet_ref') ?? ''),
                'app-card-txn-'.$tx->id,
            ], fn (string $ref): bool => $ref !== '') as $saleRef) {
                if (SellerPayoutService::reverseOrFlag($saleRef, 'app chargeback clawback (txn #'.$tx->id.')', ['transaction_id' => $tx->id, 'dispute' => $dispute->external_id]) !== 'none') {
                    break;
                }
            }
        }
    }

    /** @return array{0:?int,1:?int,2:?int} [store_id, transaction_id, order_id] */
    private function resolveTenant(array $obj): array
    {
        // Trust the dispute's own charge/payment_intent to find our transaction.
        $candidates = array_filter([$obj['charge'] ?? null, $obj['payment_intent'] ?? null]);
        if (empty($candidates)) {
            return [null, null, null];
        }

        $tx = Transaction::whereIn('payment_id', $candidates)->first();
        if (! $tx) {
            return [null, null, null];
        }

        $order = $tx->product_id ? Order::find($tx->product_id) : null;

        return [$order?->store_id, $tx->id, $order?->id];
    }

    /**
     * Platform-level heads-up on a new dispute. The Notification model is the
     * superadmin stream (notifications.status is a 0/1 boolean — unread = 0), so a
     * string 'unread' would silently cast to true (read). The owner sees the dispute
     * itself in their Disputes page; this alert is the ops signal.
     */
    private function alert(Dispute $dispute): void
    {
        try {
            $where = $dispute->store?->title ? ' — '.$dispute->store->title : '';
            Notification::create([
                'title' => 'New payment dispute'.($dispute->amount ? ' ('.$dispute->currency.' '.$dispute->amount.')' : '').$where,
                'type' => 'dispute',
                'link' => route('stores.disputes.index'),
                'status' => 0, // unread
            ]);
        } catch (\Throwable $e) {
            Log::warning('[disputes] alert failed: '.$e->getMessage());
        }
    }
}
