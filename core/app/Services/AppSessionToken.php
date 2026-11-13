<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\App;
use Juwri\Core\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use RuntimeException;

/**
 * P3-3: short-lived session tokens for EMBEDDED apps (the App-Bridge pattern).
 * The dashboard mints a 60-second JWT signed with the app's OAuth client
 * secret; the iframe'd app verifies it independently (it knows its own secret)
 * and uses it as the Bearer credential for API calls — no long-lived secret
 * ever sits in the browser, and an intercepted token dies in a minute.
 */
class AppSessionToken
{
    public const TTL_SECONDS = 60;
    public const ALGO = 'HS256';

    public static function mint(App $app, User $user): string
    {
        $now = time();

        return JWT::encode([
            'iss' => rtrim(config('app.url'), '/'),
            'aud' => (string) $app->oauth_client_id,
            'sub' => (string) $user->id,
            'app' => $app->slug,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::TTL_SECONDS,
            'jti' => bin2hex(random_bytes(16)),
        ], self::secretFor($app), self::ALGO);
    }

    /**
     * Verify a session token for the given app. Returns the claims.
     *
     * @throws RuntimeException on any signature/claim failure.
     */
    public static function verify(string $jwt, App $app): array
    {
        try {
            $claims = (array) JWT::decode($jwt, new Key(self::secretFor($app), self::ALGO));
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid session token: '.$e->getMessage(), 0, $e);
        }

        if (($claims['aud'] ?? null) !== (string) $app->oauth_client_id) {
            throw new RuntimeException('Session token audience mismatch.');
        }

        return $claims;
    }

    protected static function secretFor(App $app): string
    {
        $client = $app->oauth_client_id ? Client::find($app->oauth_client_id) : null;
        $secret = $client?->secret;
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('App has no OAuth client secret to sign session tokens with.');
        }

        return $secret;
    }
}
