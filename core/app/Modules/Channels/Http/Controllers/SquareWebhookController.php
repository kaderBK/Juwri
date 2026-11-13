<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareOAuth;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;

/**
 * N7-P2 — Square webhooks. Verified with the app's webhook signature key over
 * (notificationUrl + rawBody) — bad signature → 401, no side effects. The tenant is
 * resolved from the payload's merchant_id against the stored connection (never trusted
 * blindly). Handles:
 *   - order.updated / order.fulfillment.updated → update the push record + fulfillment state.
 *   - catalog.version.updated → flag the connection "catalog drift" (re-pull mapping).
 */
class SquareWebhookController extends Controller
{
    public function inbound(Request $request)
    {
        $platform = Credential::for('square', null) ?? [];
        $signatureKey = $platform['webhook_signature_key'] ?? config('services.square.webhook_signature_key');
        $provided = (string) $request->header('x-square-hmacsha256-signature', '');
        $notificationUrl = route('webhook.square.inbound');

        if (empty($signatureKey) || ! SquareOAuth::verifyWebhook((string) $signatureKey, $notificationUrl, $request->getContent(), $provided)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all() ?: $request->all();
        $type = (string) ($payload['type'] ?? '');
        $merchantId = (string) ($payload['merchant_id'] ?? '');

        $connection = PosBridgeConnection::where('provider', 'square')->get()
            ->first(fn ($c) => ($c->settings['merchant_id'] ?? null) === $merchantId && $merchantId !== '');
        if (! $connection) {
            return response()->json(['error' => 'Unknown merchant.'], 404);
        }

        if ($type === 'catalog.version.updated') {
            $settings = $connection->settings ?? [];
            $settings['catalog_drift'] = true;
            $connection->settings = $settings;
            $connection->save();
            return response()->json(['success' => true, 'flagged' => 'catalog_drift'], 200);
        }

        if ($type === 'order.updated' || $type === 'order.fulfillment.updated' || $type === 'order.created') {
            $object = $payload['data']['object'] ?? [];
            $order = $object['order_updated'] ?? $object['order'] ?? $object;
            $squareOrderId = (string) ($order['order_id'] ?? $order['id'] ?? '');
            $refId = $order['reference_id'] ?? null;

            $push = null;
            if ($refId) {
                $push = PosOrderPush::where('provider', 'square')
                    ->where('store_id', $connection->store_id)
                    ->where('order_id', (int) $refId)->first();
            }
            if (! $push && $squareOrderId !== '') {
                $push = PosOrderPush::where('provider', 'square')
                    ->where('store_id', $connection->store_id)
                    ->where('external_id', $squareOrderId)->first();
            }
            // P6-100: Square retries and does not order deliveries — keep only a newer order version, and never
            // move a COMPLETED/CANCELED order back.
            $version = isset($order['version']) && is_numeric($order['version']) ? (int) $order['version'] : null;
            $meta = $push ? ($push->provider_meta ?? []) : [];
            if ($push && (($version !== null && isset($meta['square_version']) && (int) $meta['square_version'] >= $version)
                || in_array($meta['square_state'] ?? null, ['COMPLETED', 'CANCELED'], true))) {
                $push = null;
            }
            if ($push) {
                $meta['square_state'] = $order['state'] ?? ($order['fulfillments'][0]['state'] ?? null);
                if ($version !== null) {
                    $meta['square_version'] = $version;
                }
                $push->provider_meta = $meta;
                $push->save();
            }
            return response()->json(['success' => true], 200);
        }

        return response()->json(['success' => true, 'ignored' => $type], 202);
    }
}
