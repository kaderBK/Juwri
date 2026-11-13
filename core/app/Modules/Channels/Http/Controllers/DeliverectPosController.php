<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use App\Models\Order;
use Juwri\Modules\Channels\Services\Aggregators\Deliverect\DeliverectMenuMapper;
use Juwri\Modules\Channels\Services\Aggregators\DeliverectProvider;
use App\Services\FeatureAccess;
use App\Services\PosService;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;
use Juwri\Core\Http\Controllers\Controller;

/**
 * N6 — Deliverect POS-track endpoints (they CALL US). Hardens the existing order-webhook
 * driver to the full documented POS integration:
 *   - pos_register      : Deliverect registers a location link (persist channelLinkId).
 *   - pos_syncproducts  : Deliverect pulls our menu — we respond from CatalogExport (I0).
 *   - pos_ordercancel   : cancel an order that was placed.
 *
 * Every endpoint verifies the HMAC-SHA256 signature (X-Deliverect-Signature) against the
 * tenant's stored webhook secret; the tenant is resolved from the stored connection by
 * channelLinkId — never trusted from the payload. Bad signature → 401, no side effects.
 */
class DeliverectPosController extends Controller
{
    private function verify(Request $request, AggregatorConnection $connection): bool
    {
        $secret = (Credential::for('deliverect', (int) $connection->store_id) ?? [])['webhook_secret'] ?? null;
        if (! $secret) {
            return false;
        }
        $provided = (string) $request->header(DeliverectProvider::HMAC_HEADER, '');
        if ($provided === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        // Tolerate a "sha256=" prefix.
        if (str_contains($provided, '=') && ! ctype_xdigit($provided)) {
            $provided = trim(substr($provided, strpos($provided, '=') + 1));
        }
        return hash_equals($expected, $provided);
    }

    private function resolve(array $payload): ?AggregatorConnection
    {
        $link = $payload['channelLinkId'] ?? $payload['channel_link_id'] ?? null;
        return $link ? AggregatorConnection::resolve('deliverect', (string) $link) : null;
    }

    /**
     * A2: resolve the tenant (from the connection, never the payload), refuse if the
     * `aggregator_deliverect` feature is off for that store (dark or later disabled →
     * Deliverect fails over to its fallback POS), then verify the raw-body HMAC.
     * Returns [connection, errorResponse, payload]; a non-null error is the refusal.
     *
     * @return array{0: ?AggregatorConnection, 1: ?\Illuminate\Http\JsonResponse, 2: array<string, mixed>}
     */
    private function guard(Request $request): array
    {
        $payload = $request->json()->all() ?: $request->all();
        $connection = $this->resolve($payload);
        if (! $connection) {
            return [null, response()->json(['error' => 'Unknown store.'], 404), $payload];
        }
        if (! FeatureAccess::storeAllows((int) $connection->store_id, 'aggregator_deliverect')) {
            return [null, response()->json(['error' => 'Deliverect integration is not enabled for this store.'], 403), $payload];
        }
        if (! PartnerTransports::verifyInbound('deliverect', $request, fn (Request $r) => $this->verify($r, $connection))) {
            return [null, response()->json(['error' => 'Invalid signature.'], 401), $payload];
        }

        return [$connection, null, $payload];
    }

    /** pos_register: establish/refresh the channel link for a location. */
    public function register(Request $request)
    {
        [$connection, $error, $payload] = $this->guard($request);
        if ($error) {
            return $error;
        }

        $settings = $connection->settings ?? [];
        $settings['channel_link_id'] = (string) ($payload['channelLinkId'] ?? '');
        $settings['registered_at'] = now()->toIso8601String();
        $connection->settings = $settings;
        $connection->save();

        // A3: the register handshake must hand Deliverect the per-location webhook
        // URL set — without ordersWebhookURL/syncProductsURL it has nowhere to send
        // orders or pull the catalog. Orders land on the generic signed inbound path;
        // sync/cancel on the dedicated Deliverect endpoints. All absolute HTTPS.
        return response()->json([
            'ordersWebhookURL'      => route('webhook.delivery.inbound', 'deliverect'),
            'syncProductsURL'       => route('webhook.deliverect.syncproducts'),
            'storeStatusWebhookURL' => route('webhook.deliverect.register'),
            'operationsWebhookURL'  => route('webhook.deliverect.ordercancel'),
            'channelLinkId'         => $settings['channel_link_id'],
        ], 200);
    }

    /** pos_syncproducts: respond with our menu in Deliverect's product schema. */
    public function syncProducts(Request $request)
    {
        [$connection, $error] = $this->guard($request);
        if ($error) {
            return $error;
        }

        $menu = new DeliverectMenuMapper()->build((int) $connection->store_id, $connection->branch_id);
        return response()->json($menu, 200);
    }

    /** pos_ordercancel: cancel a previously-ingested order. */
    public function orderCancel(Request $request)
    {
        [$connection, $error, $payload] = $this->guard($request);
        if ($error) {
            return $error;
        }

        $externalId = (string) ($payload['orderId'] ?? $payload['id'] ?? '');
        $delivery = DeliveryOrder::where('provider', 'deliverect')
            ->where('store_id', $connection->store_id)
            ->where('external_id', $externalId)->first();

        // Idempotency: Deliverect re-delivers pos_ordercancel; only mutate (and fire
        // Order::booted side effects — board/shopper push) on the first transition.
        if ($delivery && $delivery->order_id && ($order = Order::find($delivery->order_id)) && $order->status !== 'cancelled') {
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $order->save();
            // Ingested orders deducted stock at creation — give it back on a partner
            // cancel (idempotent; no-op for held orders), matching the POS reject path.
            PosService::restoreOrderStock($order);
        }

        return response()->json(['success' => true], 200);
    }
}
