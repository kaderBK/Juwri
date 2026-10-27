<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\InApp;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Contracts\GooglePlayBilling;
use RuntimeException;
use Throwable;

/** GooglePlayBilling over the Play Developer API with the service-account key from config (inapp.google.*). */
final class GooglePlayHttp implements GooglePlayBilling
{
    private const API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/';

    public function subscription(string $packageName, string $purchaseToken): array
    {
        $r = Http::withToken($this->accessToken())->timeout(15)
            ->get(self::API.rawurlencode($packageName).'/purchases/subscriptionsv2/tokens/'.rawurlencode($purchaseToken));
        if ($r->status() === 404 || $r->status() === 410) {
            throw new InAppRejected('unknown_purchase');
        }
        if (! $r->ok() || ! is_array($r->json())) {
            throw new RuntimeException('google play: '.$r->status());
        }

        return (array) $r->json();
    }

    public function acknowledge(string $packageName, string $productId, string $purchaseToken): void
    {
        $r = Http::withToken($this->accessToken())->timeout(15)
            ->post(self::API.rawurlencode($packageName).'/purchases/subscriptions/'.rawurlencode($productId).'/tokens/'.rawurlencode($purchaseToken).':acknowledge', []);
        if (! $r->successful()) {
            throw new RuntimeException('google play acknowledge: '.$r->status());
        }
    }

    public function verifyPushToken(string $bearer): bool
    {
        $audience = (string) config('inapp.google.push_audience');
        $account = (string) config('inapp.google.push_service_account');
        if ($bearer === '' || $audience === '' || $account === '') {
            return false;
        }
        try {
            $keys = Cache::remember('inapp.google.oidc-jwks', 3600, fn () => (array) Http::timeout(10)->get('https://www.googleapis.com/oauth2/v3/certs')->throw()->json());
            $claims = (array) JWT::decode($bearer, JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable) {
            return false;
        }

        return in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            && ($claims['aud'] ?? null) === $audience
            && ($claims['email'] ?? null) === $account
            && ($claims['email_verified'] ?? false) === true;
    }

    /** OAuth 2 access token from the service-account key (JWT bearer grant), cached until shortly before it expires. */
    private function accessToken(): string
    {
        $key = json_decode((string) config('inapp.google.service_account'), true);
        if (! is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new RuntimeException('google play: no service account configured');
        }

        return (string) Cache::remember('inapp.google.token.'.hash('sha256', (string) $key['client_email']), 3000, function () use ($key) {
            $now = time();
            $assertion = JWT::encode(['iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600], (string) $key['private_key'], 'RS256');
            $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
            if (! $r->ok() || ! is_string($r->json('access_token'))) {
                throw new RuntimeException('google play: token '.$r->status());
            }

            return $r->json('access_token');
        });
    }
}
