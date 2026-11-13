<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

use Illuminate\Support\Facades\Http;

/**
 * N1-P1 — exchanges a Foodics authorization code for an access token. Access tokens do
 * NOT expire (stored encrypted; revoke supported). Uses the Http client so it is
 * fully fakeable in CI (no live network). Returns the token array or null on failure.
 */
class FoodicsTokenExchange
{
    /**
     * @return array{access_token:string, business?:string}|null
     */
    public static function exchange(bool $sandbox, string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        try {
            $res = Http::asForm()->acceptJson()->post(FoodicsOAuth::tokenUrl($sandbox), [
                'grant_type'    => 'authorization_code',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ]);
            if (! $res->successful()) {
                return null;
            }
            $data = $res->json();
            $token = $data['access_token'] ?? null;
            return $token ? ['access_token' => (string) $token, 'business' => $data['business'] ?? null] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
