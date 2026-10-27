<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\StaffPush;

use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\FcmV1Client;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;
use Illuminate\Support\Facades\Http;

/**
 * Multi-channel push sender. Routes each subscription by platform: native
 * FCM/APNs device tokens go to Firebase, web subscriptions fall through to the
 * web-push sender.
 *
 * PARITY-RUN T9 upgraded the native lane:
 *  - **BYOK per store.** Credentials resolve through {@see PushCredentials}
 *    (merchant vault row → platform default → config/env), so a reseller or a
 *    single merchant can bring their own Firebase project.
 *  - **FCM HTTP v1** when a service account is configured (the only API Google
 *    still issues). The retired legacy `server_key` lane is kept for installs
 *    that already carry one in .env.
 *  - No credentials at all ⇒ a safe no-op returning true, so the fan-out /
 *    prefs / prune pipeline still runs in dev, CI and un-provisioned installs
 *    WITHOUT deleting anybody's token.
 *
 * Return contract (inherited): false means the endpoint is GONE and the caller
 * must prune. Transient failures return true.
 */
class FcmPushSender implements WebPushSender
{
    private WebPushSender $webFallback;

    private FcmV1Client $v1;

    public function __construct(?WebPushSender $webFallback = null, ?FcmV1Client $v1 = null)
    {
        // T9: the default web lane is the REAL VAPID sender, not the null stub.
        $this->webFallback = $webFallback ?? new VapidWebPushSender;
        $this->v1 = $v1 ?? new FcmV1Client;
    }

    public function send(PushSubscription $sub, array $payload): bool
    {
        $platform = (string) ($sub->platform ?? 'web');

        if ($platform === 'web') {
            return $this->webFallback->send($sub, $payload);
        }

        // Owner-app devices → platform credential; staff/driver devices → store BYOK.
        $creds = PushCredentials::forDevice($sub);

        if ($creds['mode'] === PushCredentials::MODE_V1) {
            return $this->keepToken($this->v1->send($creds, (string) $sub->endpoint, $platform, $payload));
        }

        if ($creds['mode'] === PushCredentials::MODE_LEGACY) {
            return $this->keepToken($this->sendLegacy((string) $creds['server_key'], $sub, $payload));
        }

        // No FCM credentials → treat as delivered (deferred live wiring).
        return true;
    }

    /** 404/410 = the token is gone; everything else keeps it (0 = never attempted). */
    private function keepToken(int $status): bool
    {
        return $status !== 404 && $status !== 410;
    }

    /** The retired FCM legacy endpoint. Returns the HTTP status, 0 on transport failure. */
    private function sendLegacy(string $serverKey, PushSubscription $sub, array $payload): int
    {
        try {
            $res = Http::withToken($serverKey)->timeout(10)->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $sub->endpoint,
                'notification' => [
                    'title' => $payload['title'] ?? ($payload['head'] ?? 'New order'),
                    'body' => $payload['body'] ?? '',
                ],
                'data' => $payload,
            ]);

            return $res->status();
        } catch (\Throwable $e) {
            return 0; // transient failure never prunes a good token
        }
    }
}
