<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\Aggregators;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use App\Models\Order;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorIngestor;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorProvider;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorRegistry;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PARITY-RUN T7 — the inbound endpoint for the Indian marketplaces.
 *
 * ── WHY THIS IS NOT `feature:` MIDDLEWARE ──
 * The caller is Zomato's or Swiggy's server: an unauthenticated third party with
 * no session, so `feature:`/`entitled:` middleware would resolve its subject from
 * a session that does not exist and 403 the partner forever. (That exact mistake
 * has bitten this codebase three times — see FIX-1.) The entitlement question is
 * asked here instead, store-scoped, once the caller has PROVED which store it is
 * speaking for by producing a valid signature.
 *
 * ── RESPONSE CONTRACT (order matters) ──
 *   404  unknown outlet — the payload named a store we have no connection for.
 *   401  bad/absent signature. Checked BEFORE the feature gate so an unauthorised
 *        caller can never probe which stores have which channels enabled.
 *   403  valid signature, but this store is not entitled to the channel (lapsed
 *        plan or superadmin kill-switch). Deliberately 403 and not a silent 202 —
 *        the partner's dashboard should show the integration failing, because it
 *        genuinely is, rather than the store quietly losing orders.
 *   202  accepted-and-skipped: paused channel, unusable payload, or a duplicate
 *        cancellation. The partner must stop retrying.
 *   201  order created (or the original returned, on a replay).
 *   200  cancellation applied.
 *
 * Orders are created ONLY by {@see AggregatorIngestor} → `OrderApiService::create`.
 * This controller never touches the Order table on the create path.
 */
abstract class IndiaAggregatorWebhookController extends Controller
{
    /** Provider key — `zomato` | `swiggy`. */
    abstract protected function providerKey(): string;

    /** Does this payload cancel a previously ingested order? */
    abstract protected function isCancellation(array $payload): bool;

    public function inbound(Request $request): JsonResponse
    {
        $provider = AggregatorRegistry::get($this->providerKey());
        if (! $provider) {
            return response()->json(['error' => 'Unknown provider.'], 404);
        }

        $payload = $request->json()->all() ?: $request->all();

        // Tenant identity comes from the STORED connection, never the payload.
        $connection = $provider->resolveConnection($request, $payload);
        if (! $connection) {
            return response()->json(['error' => 'Unknown outlet.'], 404);
        }

        // No verified signature → no side effects, ever.
        if (! $provider->verifyWebhook($request, $connection)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        // Entitlement, store-scoped (see the class docblock).
        if (! IndiaAggregatorSync::enabled((int) $connection->store_id, $this->providerKey())) {
            return response()->json(['error' => 'Channel not enabled for this store.'], 403);
        }

        if ($this->isCancellation($payload)) {
            return $this->cancel($provider, $connection, $payload);
        }

        $order = AggregatorIngestor::ingest($provider, $connection, $payload);

        if (! $order) {
            // Paused channel or unusable payload — ack so the partner stops retrying.
            return response()->json(['success' => true, 'skipped' => true], 202);
        }

        return response()->json([
            'success'  => true,
            'order_id' => $order->id,
            'token'    => $order->track_token,
        ], 201);
    }

    /**
     * Cancel a previously ingested order. Resolved through the SAME
     * `delivery_orders(provider, external_id)` ledger the ingest dedupes on, so a
     * cancellation can only ever reach an order this provider actually created —
     * a cancel payload naming another store's order id resolves to nothing.
     */
    private function cancel(AggregatorProvider $provider, $connection, array $payload): JsonResponse
    {
        $externalId = $provider->externalOrderId($payload);
        if (! $externalId) {
            return response()->json(['success' => true, 'skipped' => true], 202);
        }

        $link = DeliveryOrder::where('provider', $provider->key())
            ->where('external_id', $externalId)
            ->where('store_id', $connection->store_id)
            ->first();

        if (! $link || ! $link->order_id) {
            return response()->json(['success' => true, 'skipped' => true], 202);
        }

        $order = Order::find($link->order_id);
        if (! $order) {
            return response()->json(['success' => true, 'skipped' => true], 202);
        }

        if ($order->status !== 'cancelled') {
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            // Saved through Eloquent so the Order::booted() choke point runs: the
            // board, the shopper push and the status pusher all see it.
            $order->save();

            // Aggregator orders deduct stock at ingest (no held flag), so a partner
            // cancel must give it back — else the stock leaks (phantom sold-out). Same
            // restore the POS reject/void paths use; idempotent (stock_restored_at) and
            // a no-op for held orders.
            PosService::restoreOrderStock($order);

            AggregatorSyncLog::record(
                (int) $connection->store_id,
                $provider->key(),
                'ok',
                'cancelled #' . $order->id . ' (' . $externalId . ')'
            );
        }

        return response()->json(['success' => true, 'order_id' => $order->id, 'cancelled' => true], 200);
    }
}
