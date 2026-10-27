<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

use App\Models\Order;
use Juwri\Modules\Payments\Contracts\Outbox;

/**
 * J25-7 part 4: what the Order model's is_paid hook sets off outside the order, as transactional-outbox messages
 * performed after the settling transaction commits (every settle path — POS, gateways, staff app — writes is_paid
 * through Eloquent, so the hook stays the single choke point):
 *
 *  - PARITY-RUN T1 auto-print: one message per paid transition (dedupe key), and PrintDispatcher is itself
 *    idempotent on job_key. A rolled-back settle no longer prints; a failing dispatch is retried by the relay.
 *
 * The merged-table release (TableMergeService::onOrderSettled) is NOT moved: it is a state change that belongs in
 * the settle's own transaction, not a side effect. Lives in Pos (the printer module); registered by PosServiceProvider.
 */
final class PaidOrderPrint
{
    public const PRINT = 'pos.order_paid.print';

    public static function paid(Order $order): void
    {
        $outbox = app(Outbox::class);
        $transition = (string) ($order->getAttributes()['updated_at'] ?? microtime(true)); // this paid transition's save
        $id = $outbox->record(self::PRINT, ['order_id' => (int) $order->id, 'transition' => $transition], self::PRINT.':'.$order->id.':'.$transition);
        $outbox->relayAfterCommit($id === null ? [] : [$id]);
    }

    public static function register(Outbox $outbox): void
    {
        // P6-158: a print (bridge delivery) cannot be rolled back: one send-once marker per paid transition (Outbox::sendOnce), so a job
        // killed after the print and before its ack does not print the ticket again. Old messages without a
        // transition key by order id alone.
        $outbox->handle(self::PRINT, function (array $p) use ($outbox): void {
            $order = Order::find((int) ($p['order_id'] ?? 0));
            if (! $order) {
                return;
            }
            $outbox->sendOnce(self::PRINT, $order->id.':'.($p['transition'] ?? ''), function () use ($order): void {
                PrintDispatcher::onOrderPaid($order);
            });
        }, sendOnce: true);
    }
}
