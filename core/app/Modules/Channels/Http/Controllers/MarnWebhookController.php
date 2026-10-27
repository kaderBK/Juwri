<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers;

use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;
use Juwri\Core\Http\Controllers\Controller;

/**
 * N4-P2 — Marn webhook receiver. Marn publishes no signature scheme, so the tenant is
 * resolved from a per-connection shared-secret token in the URL (never the payload) and
 * cross-checked against brandKey/locationKey — unknown → 404, no side effects.
 *
 * Order state: 1 Waiting, 2 InKitchen, 3 Ready, 4 Served, 5 OnTheWay, 6 Delivered.
 * Status: Pending, Paid, Void, Refund, PartialRefund.
 * "MARN Services Updates" = menu changed → flag re-import (never auto-import).
 */
class MarnWebhookController extends Controller
{
    private const STATE = [1 => 'waiting', 2 => 'in_kitchen', 3 => 'ready', 4 => 'served', 5 => 'on_the_way', 6 => 'delivered'];

    public function inbound(Request $request, string $token)
    {
        $connection = PosBridgeConnection::resolveByWebhookToken('marn', $token);

        if (! $connection) {
            return response()->json(['error' => 'Unknown connection.'], 404);
        }
        // P1-13 13c-R: the URL token is the marn check; a Cloud partner relay (when bound) may vouch for what it forwards.
        if (! PartnerTransports::verifyInbound('marn', $request, fn () => true)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all() ?: $request->all();

        // Menu-changed trigger.
        if (! empty($payload['servicesUpdate']) || ($payload['event'] ?? '') === 'services.update') {
            $settings = $connection->settings ?? [];
            $settings['menu_drift'] = true;
            $connection->settings = $settings;
            $connection->save();
            return response()->json(['success' => true, 'flagged' => 'menu_drift'], 200);
        }

        // Cross-check the location the payload claims against the stored connection.
        $locationKey = (string) ($payload['locationKey'] ?? '');
        if ($locationKey !== '' && $locationKey !== (string) $connection->external_location_id) {
            return response()->json(['error' => 'Unknown store.'], 404);
        }

        $orderNo = (string) ($payload['orderNo'] ?? '');
        if ($orderNo !== '') {
            $push = PosOrderPush::where('provider', 'marn')
                ->where('store_id', $connection->store_id)
                ->where('external_id', $orderNo)->first();
            // P6-100: orderState only moves forward (waiting … delivered); a retried older update is ignored.
            $nextRank = (int) ($payload['orderState'] ?? 0);
            $currentRank = $push ? (int) (array_search(($push->provider_meta ?? [])['marn_state'] ?? null, self::STATE, true) ?: 0) : 0;
            if ($push && isset(self::STATE[$nextRank]) && $currentRank >= $nextRank) {
                $push = null;
            }
            if ($push) {
                $meta = $push->provider_meta ?? [];
                $meta['marn_state'] = self::STATE[(int) ($payload['orderState'] ?? 0)] ?? ($payload['orderState'] ?? null);
                $meta['marn_status'] = $payload['status'] ?? null;
                $push->provider_meta = $meta;
                $push->save();
            }
        }

        return response()->json(['success' => true], 200);
    }
}
