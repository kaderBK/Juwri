<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Square;

/**
 * N7-P1 — Square OAuth authorization-code helpers (pure/testable). Square App
 * Marketplace REQUIRES OAuth — personal access tokens disqualify a listing.
 *
 *   Authorize: https://connect.squareup.com/oauth2/authorize
 *              (sandbox https://connect.squareupsandbox.com/oauth2/authorize)
 *   Token:     POST /oauth2/token   (exchange + refresh)
 *   Revoke:    POST /oauth2/revoke
 *
 * Access tokens expire (~30 days) with a refresh token → refresh before expiry.
 */
class SquareOAuth
{
    public static function base(bool $sandbox): string
    {
        return $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
    }

    public static function authorizeUrl(bool $sandbox, string $appId, string $state, array $scopes = []): string
    {
        $scopes = $scopes ?: ['MERCHANT_PROFILE_READ', 'ORDERS_READ', 'ORDERS_WRITE', 'ITEMS_READ', 'PAYMENTS_WRITE'];
        return self::base($sandbox) . '/oauth2/authorize?' . http_build_query([
            'client_id' => $appId,
            'scope'     => implode(' ', $scopes),
            'session'   => 'false',
            'state'     => $state,
        ]);
    }

    public static function tokenUrl(bool $sandbox): string
    {
        return self::base($sandbox) . '/oauth2/token';
    }

    public static function revokeUrl(bool $sandbox): string
    {
        return self::base($sandbox) . '/oauth2/revoke';
    }

    public static function stateMatches(?string $expected, ?string $got): bool
    {
        return is_string($expected) && $expected !== '' && is_string($got) && hash_equals($expected, $got);
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Square webhook signature: base64( HMAC-SHA256( signatureKey, notificationUrl + body ) )
     * compared to the x-square-hmacsha256-signature header.
     */
    public static function verifyWebhook(string $signatureKey, string $notificationUrl, string $body, string $provided): bool
    {
        if ($provided === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $body, $signatureKey, true));
        return hash_equals($expected, $provided);
    }
}
