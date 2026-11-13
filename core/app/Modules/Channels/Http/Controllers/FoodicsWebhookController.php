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
 * N1-P3 — Foodics webhook receiver.
 *
 * The tenant is resolved from a per-connection webhook token in the URL (never from the
 * payload) — an unknown/mismatched token → 404, no side effects. Handles:
 *   - application.order.updated : maps their status → our PosOrderPush record.
 *   - menu.updated              : flags the connection "menu drift — re-import suggested"
 *                                 (surfaced in the UI; we NEVER auto-import).
 *
 * Foodics status codes: 1 Pending, 2 Active, 3 Declined, 4 Closed, 7 Void.
 */
class FoodicsWebhookController extends Controller
{
    private const STATUS = [1 => 'pending', 2 => 'active', 3 => 'declined', 4 => 'closed', 7 => 'void'];

    /** P6-100: status order for out-of-order updates (unknown codes are applied as before). */
    private const RANK = ['pending' => 1, 'active' => 2, 'declined' => 3, 'closed' => 3, 'void' => 3];

    public function inbound(Request $request, string $token)
    {
        // Resolve the tenant strictly from the vaulted shared secret.
        $connection = PosBridgeConnection::resolveByWebhookToken('foodics', $token);

        if (! $connection) {
            return response()->json(['error' => 'Unknown connection.'], 404);
        }
        // P1-13 13c-R: the URL token is the foodics check; a Cloud partner relay (when bound) may vouch for what it forwards.
        if (! PartnerTransports::verifyInbound('foodics', $request, fn () => true)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all() ?: $request->all();
        $event = (string) ($payload['event'] ?? $payload['type'] ?? '');

        if (str_contains($event, 'menu.updated')) {
            $settings = $connection->settings ?? [];
            $settings['menu_drift'] = true;
            $connection->settings = $settings;
            $connection->save();
            return response()->json(['success' => true, 'flagged' => 'menu_drift'], 200);
        }

        if (str_contains($event, 'order.updated')) {
            $data = $payload['data'] ?? $payload;
            $ourRef = $data['meta']['reference'] ?? ($data['reference'] ?? null);
            $foodicsId = (string) ($data['id'] ?? '');
            $statusCode = (int) ($data['status'] ?? 0);

            $push = null;
            if ($ourRef) {
                $push = PosOrderPush::where('provider', 'foodics')
                    ->where('store_id', $connection->store_id)
                    ->where('order_id', (int) $ourRef)->first();
            }
            if (! $push && $foodicsId !== '') {
                $push = PosOrderPush::where('provider', 'foodics')
                    ->where('store_id', $connection->store_id)
                    ->where('external_id', $foodicsId)->first();
            }

            // P6-100: order updates are retried and arrive out of order — a status only moves forward
            // (pending → active → declined/closed/void; those three are final).
            $next = self::STATUS[$statusCode] ?? $statusCode;
            $current = $push ? (($push->provider_meta ?? [])['foodics_status'] ?? null) : null;
            if ($push && (self::RANK[(string) $current] ?? -1) >= (self::RANK[(string) $next] ?? PHP_INT_MAX)) {
                $push = null;
            }
            if ($push) {
                $meta = $push->provider_meta ?? [];
                $meta['foodics_status'] = $next;
                $push->provider_meta = $meta;
                $push->save();
            }
            return response()->json(['success' => true], 200);
        }

        return response()->json(['success' => true, 'ignored' => $event], 202);
    }
}
