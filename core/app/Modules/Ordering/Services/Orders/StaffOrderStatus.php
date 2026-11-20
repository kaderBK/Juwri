<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use App\Models\StaffMember;
use App\Services\OrderStatusNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Team\Models\StaffSyncedOp;

/**
 * AUDIT-authz #1: the one staff order-status write, shared by the staff PWA
 * (session guard) and the staff-token API, so both enforce the same rules:
 * cancelled is terminal (P6-207), a PAID cancel needs pos_refund (P6-206), paid
 * split shares block a cancel (P6-205), offline replays apply once (SP2), and a
 * cancel runs the shared reversal (stock, folio, gift card, wallet, loyalty).
 *
 * The caller has already checked store, the `orders` permission and the branch.
 */
final class StaffOrderStatus
{
    public static function apply(StaffMember $staff, Order $order, string $status, string $opId = '', string $reason = '', string $via = 'staff_pwa'): JsonResponse
    {
        // The checks and the write run on a locked row, so two devices racing on the
        // same order can't both pass the terminal / paid checks.
        $result = DB::transaction(function () use ($staff, $order, $status, $opId): array {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // SP2: a replayed offline op (same client_op_id) applies exactly once — no
            // duplicate status write, SMS, or activity log on reconnect. P6-206: the key
            // is per store (two stores' devices may mint the same id).
            if ($opId !== '' && StaffSyncedOp::where('store_id', $locked->store_id)->where('op_id', $opId)->exists()) {
                return [200, ['id' => $locked->id, 'status' => $locked->status, 'duplicate' => true], null];
            }

            // P6-207: cancelled is terminal — stock and tender already went back.
            if (OrderCancellation::blocksTransition($locked, $status)) {
                return [422, ['error' => ___('A cancelled order cannot be changed.')], null];
            }
            if ($status === 'cancelled' && $locked->status !== 'cancelled') {
                // P6-206: cancelling a PAID order gives money back — same gate as POS refund.
                if ((bool) $locked->is_paid && ! $staff->hasPermission('pos_refund')) {
                    return [403, ['error' => ___('Manager permission required.')], null];
                }
                // P6-205: paid split shares must be refunded / settled first (as the dashboard).
                if (OrderCancellation::hasPaidSplitShares($locked)) {
                    return [422, ['error' => ___('This bill already has paid split shares — refund those or finish settling it before cancelling.')], null];
                }
            }

            if ($opId !== '') {
                StaffSyncedOp::create([
                    'op_id' => $opId, 'order_id' => $locked->id,
                    'store_id' => $locked->store_id, 'status' => $status, 'created_at' => now(),
                ]);
            }

            $locked->status = $status;
            foreach (['preparing' => 'preparing_at', 'ready' => 'ready_at', 'completed' => 'completed_at', 'cancelled' => 'cancelled_at'] as $s => $col) {
                if ($status === $s) {
                    $locked->{$col} = now();
                }
            }
            $locked->save();

            return [200, ['id' => $locked->id, 'status' => $status], $locked];
        });

        [$code, $body, $saved] = $result;
        if (! $saved instanceof Order) {
            return response()->json($body, $code);
        }

        // P6-205: the shared cancel routine — stock, folio, gift card, wallet, loyalty
        // (each idempotent, so a retried cancel never double-credits). Runs after the
        // commit: the folio reversal swallows its own errors, which must not poison
        // the status transaction.
        if ($status === 'cancelled') {
            OrderCancellation::reverse($saved);
        }

        log_activity('order.status', $saved, array_filter([
            'status' => $status,
            'order_id' => $saved->id,
            'via' => $via,
            'by_staff' => (int) $staff->id,
            'reason' => $reason !== '' ? $reason : null,
        ], fn ($v) => $v !== null), $saved->store_id);

        // Customer status SMS (owner opt-in), shared with the dashboard and the KDS:
        // same locale handling, template resolution and per (order, status) idempotency.
        OrderStatusNotifier::notify($saved, $status);

        return response()->json($body, $code);
    }
}
