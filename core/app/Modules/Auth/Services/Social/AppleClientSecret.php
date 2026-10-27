<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Services\Social;

use Firebase\JWT\JWT;

/**
 * FEATURES-5 Track A — Sign in with Apple client-secret generator.
 *
 * Apple's OAuth "client_secret" is NOT a static string: it is an ES256 JWT
 * signed with the developer's .p8 private key, valid at most 6 months. We mint
 * it on the fly from the BYOK/platform secrets shape:
 *   {client_id (services_id), team_id, key_id, private_key (.p8 contents)}
 *
 * firebase/php-jwt is already vendored, so no new dependency is required.
 */
class AppleClientSecret
{
    /**
     * Build a signed client-secret JWT from an apple credential secrets array.
     * Returns null when the shape is incomplete (so callers can skip Apple).
     *
     * @param array $secrets {client_id, team_id, key_id, private_key}
     * @param int   $ttl      Seconds until expiry (default ~5 months, < Apple's 6mo max).
     */
    public static function generate(array $secrets, int $ttl = 12960000): ?string
    {
        $clientId   = $secrets['client_id']   ?? null;
        $teamId     = $secrets['team_id']     ?? null;
        $keyId      = $secrets['key_id']      ?? null;
        $privateKey = $secrets['private_key'] ?? null;

        if (!$clientId || !$teamId || !$keyId || !$privateKey) {
            return null;
        }

        // Accept a bare base64 key body or a full PEM block.
        $pem = self::normalizeKey($privateKey);

        $now = time();
        $payload = [
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ];

        return JWT::encode($payload, $pem, 'ES256', $keyId);
    }

    /**
     * Wrap a raw base64 key body in a PKCS#8 PEM envelope if it isn't already,
     * so pasting either the raw .p8 contents or just the key body both work.
     */
    protected static function normalizeKey(string $key): string
    {
        $key = trim($key);

        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $body = chunk_split(preg_replace('/\s+/', '', $key), 64, "\n");

        return "-----BEGIN PRIVATE KEY-----\n{$body}-----END PRIVATE KEY-----\n";
    }
}
