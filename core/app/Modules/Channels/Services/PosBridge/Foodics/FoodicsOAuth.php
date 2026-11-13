<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

/**
 * N1 — Foodics OAuth2 authorization-code helpers (pure/testable; the HTTP token
 * exchange is a thin call the real client makes with these URLs).
 *
 * Authorize:  https://console.foodics.com/authorize        (sandbox console-sandbox)
 * Token:      POST https://api.foodics.com/oauth/token      (sandbox api-sandbox)
 * Auth-code TTL 600s. Access tokens DO NOT expire (store encrypted; support revoke).
 * `state` is CSRF protection — generated on redirect, verified on callback.
 */
class FoodicsOAuth
{
    public static function authorizeBase(bool $sandbox): string
    {
        return $sandbox ? 'https://console-sandbox.foodics.com' : 'https://console.foodics.com';
    }

    public static function apiBase(bool $sandbox): string
    {
        return $sandbox ? 'https://api-sandbox.foodics.com' : 'https://api.foodics.com';
    }

    /** Build the authorize redirect URL with a CSRF state. */
    public static function authorizeUrl(bool $sandbox, string $clientId, string $redirectUri, string $state, array $scopes = []): string
    {
        $scopes = $scopes ?: [
            'general.read', 'orders.limited.read', 'orders.limited.create',
            'orders.limited.pay', 'orders.limited.deliver', 'orders.limited.decline',
        ];
        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
        ]);
        return self::authorizeBase($sandbox) . '/authorize?' . $params;
    }

    public static function tokenUrl(bool $sandbox): string
    {
        return self::apiBase($sandbox) . '/oauth/token';
    }

    /** Constant-time state check for the callback. */
    public static function stateMatches(?string $expected, ?string $got): bool
    {
        if (! is_string($expected) || $expected === '' || ! is_string($got)) {
            return false;
        }
        return hash_equals($expected, $got);
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(16));
    }
}
