<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers;

use Juwri\Modules\Channels\Services\Aggregators\AggregatorIngestor;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorRegistry;
use App\Services\FeatureAccess;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;
use Juwri\Core\Http\Controllers\Controller;

/**
 * T3: inbound aggregator/delivery webhooks. Every provider is resolved from the
 * registry, the tenant is resolved from the stored connection (never trusted from the
 * payload), and the request MUST pass the provider's signature verification before any
 * order is created. This replaces the earlier unsigned scaffold.
 */
class DeliveryWebhookController extends Controller
{
    public function inbound(Request $request, string $provider)
    {
        $prov = AggregatorRegistry::get($provider);
        if (!$prov) {
            return response()->json(['error' => 'Unknown provider.'], 404);
        }

        $payload = $request->json()->all() ?: $request->all();

        // Resolve the tenant from the connection (store id) — never the raw payload.
        $connection = $prov->resolveConnection($request, $payload);
        if (!$connection) {
            return response()->json(['error' => 'Unknown store.'], 404);
        }

        // A2 (integrations pack): per-provider feature gate. A dark or disabled
        // aggregator refuses inbound orders so the platform (e.g. Deliverect) fails
        // over to its fallback POS instead of accruing orders the plan no longer
        // covers. storeAllows fails OPEN for a provider with no `aggregator_<key>`
        // feature (unchanged behaviour for the always-on providers).
        if (! FeatureAccess::storeAllows((int) $connection->store_id, 'aggregator_' . $prov->key())) {
            return response()->json(['error' => 'Integration disabled.'], 403);
        }

        // Hard requirement: a valid signature. No verified signature → no order.
        // P1-13 13c-R: partner-credential providers through the partner transport (direct = the provider's own check).
        if (! PartnerTransports::verifyInbound($prov->key(), $request, fn (Request $r) => $prov->verifyWebhook($r, $connection))) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $order = AggregatorIngestor::ingest($prov, $connection, $payload);
        if (!$order) {
            // Paused channel or unusable payload — accept so the aggregator stops retrying.
            return response()->json(['success' => true, 'skipped' => true], 202);
        }

        return response()->json(['success' => true, 'order_id' => $order->id], 201);
    }
}
