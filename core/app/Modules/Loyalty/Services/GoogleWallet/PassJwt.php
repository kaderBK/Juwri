<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services\GoogleWallet;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\GoogleWallet\GoogleWalletService;

/**
 * Signs the Google Wallet "Save to Wallet" JWT (RS256) with the platform issuer's
 * service-account key. The signed JWT is embedded in the save link:
 *   https://pay.google.com/gp/v/save/{jwt}
 *
 * This is a "fat" JWT: it carries the full class + object definitions in `payload`,
 * so Google auto-creates them on first save and no pre-provisioning REST call is
 * needed to render the button (balance UPDATES are a separate REST PATCH — C3).
 *
 * firebase/php-jwt is already vendored (v7, confirmed C0/P0b) — no composer add.
 * Everything here is fail-soft: a missing/broken key returns null, never throws,
 * so a save button simply doesn't render rather than 500-ing the storefront.
 */
class PassJwt
{
    /**
     * Read the service-account key file → ['private_key' => ..., 'client_email' => ...].
     * Null when the path is unset, unreadable, or the JSON lacks the required fields.
     *
     * @return array{private_key: string, client_email: string}|null
     */
    public static function loadCredentials(?string $path = null): ?array
    {
        $path = $path !== null ? $path : GoogleWalletService::serviceAccountPath();
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($json)) {
            return null;
        }

        $privateKey = (string) ($json['private_key'] ?? '');
        $clientEmail = (string) ($json['client_email'] ?? '');
        if ($privateKey === '' || $clientEmail === '') {
            return null;
        }

        return ['private_key' => $privateKey, 'client_email' => $clientEmail];
    }

    /**
     * Build the save-to-wallet JWT claim set (unsigned) for a wallet payload. Exposed
     * so tests (and callers) can assert on claim shape without a key present.
     *
     * @param  array<string, mixed>  $payload  Wallet payload, e.g. ['loyaltyObjects' => [...], 'loyaltyClasses' => [...]]
     * @param  string[]  $origins
     * @return array<string, mixed>
     */
    public static function claims(array $payload, array $origins, string $issuer): array
    {
        return [
            'iss'     => $issuer,
            'aud'     => 'google',
            'typ'     => 'savetowallet',
            'iat'     => time(),
            'origins' => array_values($origins),
            'payload' => $payload,
        ];
    }

    /**
     * Sign the JWT (RS256). Null on any failure (no key, bad key, encode error).
     *
     * @param  array<string, mixed>  $payload  Wallet payload (loyalty/giftCard classes + objects).
     * @param  string[]  $origins
     */
    public static function sign(array $payload, array $origins): ?string
    {
        $creds = self::loadCredentials();
        if ($creds === null) {
            return null;
        }

        try {
            $claims = self::claims($payload, $origins, $creds['client_email']);

            return JWT::encode($claims, $creds['private_key'], 'RS256');
        } catch (\Throwable $e) {
            Log::warning('Google Wallet JWT sign failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The full "Save to Google Wallet" link for a payload, or null when unsigned.
     *
     * @param  array<string, mixed>  $payload
     * @param  string[]  $origins
     */
    public static function saveUrl(array $payload, array $origins): ?string
    {
        $jwt = self::sign($payload, $origins);

        return $jwt === null ? null : 'https://pay.google.com/gp/v/save/' . $jwt;
    }

    /**
     * Mint an OAuth2 access token for the Wallet Objects REST API via the
     * service-account JWT-bearer grant. Used by the balance-sync PATCH (C3). Null on
     * any failure (no key, token endpoint error). Fail-soft.
     */
    public static function accessToken(): ?string
    {
        $creds = self::loadCredentials();
        if ($creds === null) {
            return null;
        }

        try {
            $now = time();
            $assertion = JWT::encode([
                'iss'   => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $creds['private_key'], 'RS256');

            $resp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);

            return $resp->ok() ? ((string) $resp->json('access_token')) ?: null : null;
        } catch (\Throwable $e) {
            Log::warning('Google Wallet access token failed: ' . $e->getMessage());

            return null;
        }
    }
}
