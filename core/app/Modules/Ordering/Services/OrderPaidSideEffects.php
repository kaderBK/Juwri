<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Order;
use App\Services\WebhookService;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * J25-7: what a settled store-order payment sets off outside the order itself, as transactional-outbox messages.
 *
 * {@see self::record()} runs inside the payment claim's DB transaction (PaymentController::paySuccess), so the
 * intents exist exactly when the claim does — a crash right after the claim no longer loses them. paySuccess relays
 * them inline at the point it used to dispatch them (same order, same jobs); anything left is retried by
 * `payments:outbox-relay`. Each handler is the code paySuccess ran before; all are idempotent per order (accounting
 * sync, POS push per order+provider, GL post) except the partner `order.paid` webhook, which is at least once.
 */
final class OrderPaidSideEffects
{
    public const SYNC_INVOICE = 'ordering.order_paid.sync_invoice';

    public const POS_PUSH = 'ordering.order_paid.pos_push';

    public const GL_POST = 'ordering.order_paid.gl_post';

    public const PARTNER_WEBHOOK = 'ordering.order_paid.webhook';

    public const TYPES = [self::SYNC_INVOICE, self::POS_PUSH, self::GL_POST, self::PARTNER_WEBHOOK];

    /**
     * Record the side effects of this paid transaction (one message per effect, deduplicated per transaction).
     *
     * @return list<int> message ids
     */
    public static function record(Outbox $outbox, Transaction $transaction): array
    {
        $payload = [
            'transaction_id' => (int) $transaction->id,
            'order_id' => (int) $transaction->product_id,
            'store_id' => (int) $transaction->store_id,
            'gateway' => $transaction->transaction_gatway,
        ];
        $ids = [];
        foreach (self::TYPES as $type) {
            $id = $outbox->record($type, $payload, $type.':'.$transaction->id);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function register(Outbox $outbox): void
    {
        // #98: export the paid order to the connected accounting provider (no-op unless connected; idempotent).
        $outbox->handle(self::SYNC_INVOICE, function (array $p): void {
            if ($order = Order::find($p['order_id'] ?? 0)) {
                app(GeneralLedger::class)->exportOrderInvoice((int) $order->id); // P1-13: Accounting module
            }
        });

        // B1: push the paid order into the store's connected POS (Square/Clover); idempotent per order+provider.
        $outbox->handle(self::POS_PUSH, function (array $p): void {
            if ($order = Order::find($p['order_id'] ?? 0)) {
                Hooks::call('order.paid.pos-push', $order); // P1-13: Channels module (hook slot) queues one push per active POS
            }
        });

        // SYSTEMS-RUN A-P1: classify the settled sale into the GL (no-op unless accounting_gl is on; idempotent).
        $outbox->handle(self::GL_POST, function (array $p): void {
            if ($order = Order::find($p['order_id'] ?? 0)) {
                app(GeneralLedger::class)->postOrderPaid($order);
            }
        });

        // #99: notify the store's API webhook subscribers that the order is paid.
        $outbox->handle(self::PARTNER_WEBHOOK, function (array $p): void {
            $store = Store::query()->find($p['store_id'] ?? 0);
            $order = Order::find($p['order_id'] ?? 0);
            if ($store && $order) {
                WebhookService::dispatch($store->user_id, 'order.paid', [
                    'order_id' => $order->id,
                    'total' => $order->total,
                    'payment_gateway' => $p['gateway'] ?? null,
                ]);
            }
        });
    }
}
