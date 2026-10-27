<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Credential;

/**
 * google-partner Lane A — GBP token vault + refresh choke point.
 *
 * ============================ CREDENTIAL ROW SHAPE (Lane B reads this) =============
 * Row: Credential { store_id, feature='gbp', provider='gbp', active=true,
 *                   secrets: encrypted:array }
 * secrets keys this service manages:
 *   - access_token   (string)   OAuth2 bearer, short-lived
 *   - refresh_token  (string)   long-lived; Google returns it ONCE on first consent
 *   - expires_at     (int)      unix ts the access_token dies (we refresh ~60s early)
 *   - token_type     (string)   'Bearer'
 *   - scope          (string)   granted scopes
 * Set later by A4 (location picker): account_id (string), location_id (string).
 * Set later by A5 (menu sync):      gbp_menu_sync_enabled, last_synced_at, sync_error.
 *
 * Lane B: to make an authenticated GBP call, get a live token with
 *   GbpTokenService::accessTokenFor($storeId)  // null when dark/unconfigured
 * NEVER read access_token straight from secrets — it may be expired. This is the ONE
 * refresh choke point; it persists the refreshed token back into the same row.
 * ===================================================================================
 *
 * All HTTP goes through the Http facade so tests fake it without a network. Every
 * failure returns null (dark discipline): a missing-creds environment mints zero
 * tokens and makes zero live calls. Nothing here logs a token or a client secret.
 */
class GbpTokenService
{
    /** Refresh this many seconds before the token actually expires (clock skew guard). */
    private const SKEW = 60;

    /** Short lock window so two concurrent jobs don't both refresh. */
    private const REFRESH_LOCK_TTL = 15;

    /**
     * Exchange an authorization code for the initial token set.
     *
     * @return array{access_token:string, refresh_token?:string, expires_in?:int, token_type?:string, scope?:string}|null
     */
    public static function exchange(string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout(15)->post(GbpOAuth::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

            if (! $res->successful()) {
                Log::info('[gbp] token exchange failed', ['status' => $res->status()]);

                return null;
            }

            $data = $res->json();
            $access = $data['access_token'] ?? null;

            return $access ? $data : null;
        } catch (\Throwable $e) {
            Log::info('[gbp] token exchange transport error');

            return null;
        }
    }

    /**
     * Persist a token set into the restaurant's gbp Credential row, MERGING so an
     * existing refresh_token / location_id / account_id survives a re-consent that
     * omits them. Computes expires_at from expires_in.
     *
     * @param  array<string,mixed>  $token  the token endpoint response
     * @param  array<string,mixed>  $extra  extra secrets to merge (e.g. account_id/location_id)
     */
    public static function store(int $storeId, array $token, array $extra = []): void
    {
        $row = Credential::firstOrNew(['store_id' => $storeId, 'feature' => 'gbp']);
        $secrets = $row->secrets ?? [];

        $secrets['access_token'] = (string) ($token['access_token'] ?? ($secrets['access_token'] ?? ''));
        // Google returns refresh_token only on first consent — keep the old one otherwise.
        if (! empty($token['refresh_token'])) {
            $secrets['refresh_token'] = (string) $token['refresh_token'];
        }
        if (isset($token['expires_in'])) {
            $secrets['expires_at'] = time() + (int) $token['expires_in'];
        }
        if (! empty($token['token_type'])) {
            $secrets['token_type'] = (string) $token['token_type'];
        }
        if (! empty($token['scope'])) {
            $secrets['scope'] = (string) $token['scope'];
        }
        foreach ($extra as $k => $v) {
            $secrets[$k] = $v;
        }

        $row->provider = 'gbp';
        $row->active = true;
        $row->secrets = array_filter($secrets, fn ($v) => $v !== null && $v !== '');
        $row->save();
    }

    /**
     * THE refresh choke point. Returns a currently-valid access token for the store,
     * refreshing via the refresh_token when the cached one has (nearly) expired.
     * Returns null when there is no credential, no refresh path, or any HTTP failure
     * (dark: caller makes zero live calls).
     */
    public static function accessTokenFor(int $storeId): ?string
    {
        $creds = Credential::for('gbp', $storeId);
        if (empty($creds)) {
            return null;
        }

        $access = $creds['access_token'] ?? null;
        $expiresAt = (int) ($creds['expires_at'] ?? 0);

        // Still valid → use it.
        if (! empty($access) && $expiresAt > time() + self::SKEW) {
            return (string) $access;
        }

        // Needs refresh — requires a refresh_token + configured client.
        $refresh = $creds['refresh_token'] ?? null;
        $clientId = (string) config('services.gbp.client_id');
        $clientSecret = (string) config('services.gbp.client_secret');
        if (empty($refresh) || $clientId === '' || $clientSecret === '') {
            // No refresh path: fall back to the (possibly-expiring) access token if any.
            return ! empty($access) ? (string) $access : null;
        }

        return self::refresh($storeId, (string) $refresh, $clientId, $clientSecret);
    }

    /** Mint a new access token from the refresh token and persist it. Null on failure. */
    private static function refresh(int $storeId, string $refreshToken, string $clientId, string $clientSecret): ?string
    {
        // Serialize concurrent refreshes so we don't spend the grant twice at once.
        $lockKey = 'gbp:refresh:'.$storeId;
        try {
            if (! Cache::add($lockKey, 1, self::REFRESH_LOCK_TTL)) {
                // Someone else is refreshing — re-read; they likely just persisted a fresh token.
                $creds = Credential::for('gbp', $storeId);

                return ! empty($creds['access_token']) ? (string) $creds['access_token'] : null;
            }
        } catch (\Throwable $e) {
            // Broken cache store must not stop a refresh.
        }

        try {
            $res = Http::asForm()->acceptJson()->timeout(15)->post(GbpOAuth::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);

            if (! $res->successful()) {
                Log::info('[gbp] token refresh failed', ['rid' => $storeId, 'status' => $res->status()]);

                return null;
            }

            $data = $res->json();
            $access = $data['access_token'] ?? null;
            if (empty($access)) {
                return null;
            }

            // Persist the refreshed token (refresh_token usually absent here → merge keeps old one).
            self::store($storeId, $data);

            return (string) $access;
        } catch (\Throwable $e) {
            Log::info('[gbp] token refresh transport error', ['rid' => $storeId]);

            return null;
        } finally {
            try {
                Cache::forget($lockKey);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
}
