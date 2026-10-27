<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Square;

use Illuminate\Support\Facades\Http;

/**
 * N7-P1 — Square OAuth token exchange / refresh / revoke over the Http client (fakeable
 * in CI). Tokens are stored encrypted in the Credential vault as {access_token,
 * refresh_token, expires_at, merchant_id}. Never logged.
 */
class SquareTokenExchange
{
    /** @return array{access_token:string,refresh_token:?string,expires_at:?string,merchant_id:?string}|null */
    public static function exchange(bool $sandbox, string $appId, string $appSecret, string $code, ?string $redirectUri = null): ?array
    {
        return self::post($sandbox, [
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]);
    }

    /** @return array|null */
    public static function refresh(bool $sandbox, string $appId, string $appSecret, string $refreshToken): ?array
    {
        return self::post($sandbox, [
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public static function revoke(bool $sandbox, string $appId, string $appSecret, string $accessToken): bool
    {
        try {
            $res = Http::withHeaders(['Authorization' => 'Client ' . $appSecret])
                ->acceptJson()->post(SquareOAuth::revokeUrl($sandbox), [
                    'client_id'    => $appId,
                    'access_token' => $accessToken,
                ]);
            return $res->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function post(bool $sandbox, array $body): ?array
    {
        try {
            $res = Http::acceptJson()->post(SquareOAuth::tokenUrl($sandbox), array_filter($body));
            if (! $res->successful()) {
                return null;
            }
            $d = $res->json();
            if (empty($d['access_token'])) {
                return null;
            }
            return [
                'access_token'  => (string) $d['access_token'],
                'refresh_token' => $d['refresh_token'] ?? null,
                'expires_at'    => $d['expires_at'] ?? null,
                'merchant_id'   => $d['merchant_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
