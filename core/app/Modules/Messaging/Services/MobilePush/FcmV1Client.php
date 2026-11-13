<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\MobilePush;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PARITY-RUN T9 — FCM HTTP v1 transport.
 *
 * Two calls: mint an OAuth2 access token from the service account (RS256 JWT
 * assertion), then POST the message. Both go through the Http facade so tests
 * can fake them without a network.
 *
 * iOS note (DECISIONS D4): the wrapper uses @capacitor-firebase/messaging, so an
 * iOS device registers an **FCM** token, not a raw APNs token — FCM rejects the
 * latter. `platform` on the subscription therefore records the DEVICE OS, and
 * both platforms are addressed here by `message.token`. The `apns` block only
 * carries presentation hints.
 *
 * Nothing in this class logs a credential: failures log the HTTP status and the
 * project id, never the key, the assertion or the token.
 */
class FcmV1Client
{
    /** Google mints 1 h tokens; refresh a little early. */
    private const TOKEN_TTL = 3300;

    public const OAUTH_URL = 'https://oauth2.googleapis.com/token';
    public const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Deliver one message. Returns the HTTP status, or 0 when the send could not
     * even be attempted (no access token). 404/410 tell the caller to prune.
     */
    public function send(array $creds, string $token, string $platform, array $payload): int
    {
        $accessToken = $this->accessToken($creds);
        if ($accessToken === null) {
            return 0;
        }

        $message = [
            'token'        => $token,
            'notification' => [
                'title' => (string) ($payload['title'] ?? ''),
                'body'  => (string) ($payload['body'] ?? ''),
            ],
            // FCM data values must all be strings.
            'data' => array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $payload),
        ];

        if ($platform === 'apns') {
            $message['apns'] = ['payload' => ['aps' => ['sound' => 'default', 'badge' => 1]]];
        } else {
            $message['android'] = ['priority' => 'high'];
        }

        try {
            $res = Http::withToken($accessToken)
                ->timeout(10)
                ->post($this->endpoint($creds), ['message' => $message]);

            if ($res->failed()) {
                Log::info('[mobile-push] fcm v1 send failed', [
                    'project' => $creds['project_id'] ?? null,
                    'status'  => $res->status(),
                ]);
            }

            return $res->status();
        } catch (\Throwable $e) {
            // Transient network trouble is NOT a dead token.
            Log::info('[mobile-push] fcm v1 transport error', ['project' => $creds['project_id'] ?? null]);
            return 0;
        }
    }

    public function endpoint(array $creds): string
    {
        return 'https://fcm.googleapis.com/v1/projects/' . ($creds['project_id'] ?? '') . '/messages:send';
    }

    /** OAuth2 access token for the service account, cached for just under an hour. */
    public function accessToken(array $creds): ?string
    {
        $clientEmail = (string) ($creds['client_email'] ?? '');
        $projectId   = (string) ($creds['project_id'] ?? '');
        if ($clientEmail === '' || $projectId === '') {
            return null;
        }

        // The cache key is a hash of the identity, never of the key material.
        $cacheKey = 'mobile_push:fcm_token:' . hash('sha256', $projectId . '|' . $clientEmail);

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable $e) {
            // A broken cache store must not stop a push.
        }

        $assertion = $this->assertion($creds);
        if ($assertion === null) {
            return null;
        }

        try {
            $res = Http::asForm()->timeout(10)->post(self::OAUTH_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);

            $token = (string) $res->json('access_token', '');
            if ($res->failed() || $token === '') {
                Log::info('[mobile-push] fcm oauth failed', ['project' => $projectId, 'status' => $res->status()]);
                return null;
            }

            try {
                Cache::put($cacheKey, $token, self::TOKEN_TTL);
            } catch (\Throwable $e) {
                // cache is an optimisation, not a requirement
            }

            return $token;
        } catch (\Throwable $e) {
            Log::info('[mobile-push] fcm oauth transport error', ['project' => $projectId]);
            return null;
        }
    }

    /** Signed RS256 JWT assertion. Null when the private key will not load. */
    private function assertion(array $creds): ?string
    {
        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::OAUTH_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $input = $this->b64(json_encode($header)) . '.' . $this->b64(json_encode($claims));

        try {
            $key = openssl_pkey_get_private((string) $creds['private_key']);
            if ($key === false) {
                Log::info('[mobile-push] fcm private key rejected', ['project' => $creds['project_id'] ?? null]);
                return null;
            }
            $signature = '';
            if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $input . '.' . $this->b64($signature);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
