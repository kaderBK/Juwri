<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\StaffPush;

use Juwri\Modules\Messaging\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * PARITY-RUN T9 — the REAL web-push sender (minishlink/web-push + VAPID).
 *
 * This is the class the container has always been missing: `StaffPushService`
 * fell back to {@see NullWebPushSender} because nothing ever bound
 * {@see WebPushSender}, so staff web push has never left the building. See
 * AppServiceProvider for the binding.
 *
 * Contract inherited from the interface: return FALSE only when the endpoint is
 * GONE (404/410), because false makes the caller prune the subscription. Every
 * other outcome — including "no VAPID keys configured" and a transient network
 * failure — returns true, so an unconfigured install behaves exactly as it did
 * under the null sender instead of silently deleting every subscription.
 */
class VapidWebPushSender implements WebPushSender
{
    public function send(PushSubscription $sub, array $payload): bool
    {
        $public  = (string) config('webpush.public_key');
        $private = (string) config('webpush.private_key');

        if ($public === '' || $private === '' || ! class_exists(WebPush::class)) {
            return true; // not configured → no-op, never a prune
        }

        // A native (FCM/APNs) token is not a web-push endpoint; the multi-channel
        // sender routes those. Reaching here with one means a misconfiguration —
        // report success so it is not pruned.
        if (($sub->platform ?? 'web') !== 'web' || empty($sub->public_key) || empty($sub->auth_token)) {
            return true;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject'    => (string) config('webpush.subject'),
                'publicKey'  => $public,
                'privateKey' => $private,
            ]]);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint'        => (string) $sub->endpoint,
                    'publicKey'       => (string) $sub->public_key,
                    'authToken'       => (string) $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                ]),
                json_encode([
                    'title' => $payload['title'] ?? ($payload['head'] ?? ''),
                    'body'  => $payload['body'] ?? '',
                    'url'   => $payload['url'] ?? '/staff-app',
                    'data'  => $payload,
                ])
            );
        } catch (\Throwable $e) {
            // Never log the payload or the keys — only that this endpoint failed.
            Log::info('[push] web-push send errored', ['subscription' => $sub->id]);
            return true;
        }

        if ($report->isSuccess()) {
            return true;
        }

        // Expired/unsubscribed → prune. Anything else is transient.
        return ! $report->isSubscriptionExpired();
    }
}
