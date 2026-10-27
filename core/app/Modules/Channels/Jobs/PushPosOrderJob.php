<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry;

/**
 * GAPS-RUN-2 B1: push one order to a restaurant's connected POS.
 *
 * No-op unless (a) the pos_bridge kill-switch is on, (b) an active connection
 * exists for the restaurant+provider. Idempotent: a pos_order_pushes row unique on
 * (order_id, provider) guards against double-pushing on replay.
 */
class PushPosOrderJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $orderId, public string $provider) {}

    public function handle(): void
    {
        if (! feature_enabled('pos_bridge')) {
            return; // superadmin kill-switch
        }

        $order = Order::with('items')->find($this->orderId);
        if (! $order) {
            return;
        }

        $connection = PosBridgeConnection::resolve($this->provider, (int) $order->store_id);
        if (! $connection) {
            return; // zero behavior change when no connection exists
        }

        // Idempotency ledger — bail if this order already pushed to this provider.
        $push = PosOrderPush::firstOrNew([
            'order_id' => $order->id,
            'provider' => $this->provider,
        ]);
        if ($push->isPushed()) {
            return;
        }
        $push->store_id = (int) $order->store_id;

        $provider = PosBridgeRegistry::get($this->provider);
        if (! $provider) {
            $push->status = 'failed';
            $push->error = 'provider_unavailable';
            $push->save();

            return;
        }

        $result = $provider->pushOrder($order, $connection);

        if ($result->ok) {
            $push->status = 'pushed';
            $push->external_id = $result->externalId;
            $push->error = null;
            // Store the POS's own recomputed totals (e.g. Foodics orders_calculator) for
            // reconciliation — never overwrites our order totals.
            if (! empty($result->raw)) {
                $push->provider_meta = $result->raw;
            }
            $push->pushed_at = now();
            $connection->last_push_at = now();
            $connection->push_error = null;
        } else {
            // M5: `pending` while the queue will try again, `failed` only when the retry
            // budget is spent — so the owner's push log distinguishes "retrying" from
            // "dropped". 'pending' | 'pushed' | 'failed' are the three values the
            // pos_order_pushes migration documents; no schema change.
            $retryable = config('queue.default') !== 'sync' && $this->attempts() < $this->tries;
            $push->status = $retryable ? 'pending' : 'failed';
            $push->error = $result->error;
            $connection->push_error = $result->error;
        }
        $push->save();
        $connection->save();

        // M5: $tries/$backoff above were dead code — handle() returned normally on
        // failure, so the worker treated a dropped kitchen order as a success. Throw so
        // the queue applies the [30, 120, 300] backoff. Safe to replay: pos_order_pushes
        // is unique on (order_id, provider) and every driver sends a stable per-order
        // idempotency key (SquareProvider, Clover docblock, Foodics' duplicate-as-success).
        // Under `sync` there is nothing to retry into and the throw would surface inside
        // the payment request, so we never throw there. The provider_unavailable branch
        // above returns before here — a missing driver cannot resolve on a retry.
        if (! $result->ok && config('queue.default') !== 'sync') {
            throw new \RuntimeException(
                "POS push to {$this->provider} failed for order {$order->id}: ".(string) $result->error
            );
        }
    }
}
