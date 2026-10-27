<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use Juwri\Core\Models\Credential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Services\SsrfGuard;

/**
 * GAPS-RUN-2 B3-P1: OAuth token refresh with ROTATION for accounting providers.
 *
 * Both Xero and QuickBooks rotate the refresh token on every refresh — the OLD one
 * dies immediately. We must persist the newest access + refresh token atomically or
 * the connection breaks. Xero: single-use refresh, 60-day idle. QuickBooks: refresh
 * rotates ~every 24h, ~100-day validity. Token endpoint + client creds come from the
 * stored Credential secrets (BYOK). Live OAuth apps are DEFERRED — this drives via
 * config + Http (fakeable in tests).
 */
class AccountingTokenService
{
    /**
     * Refresh one accounting connection, persisting the rotated tokens.
     * @return bool true when tokens were refreshed and stored.
     */
    public function refresh(Credential $credential): bool
    {
        $secrets = (array) $credential->secrets;
        $tokenUrl = $secrets['token_url'] ?? null;
        $refreshToken = $secrets['refresh_token'] ?? null;
        $clientId = $secrets['client_id'] ?? null;
        $clientSecret = $secrets['client_secret'] ?? null;

        if (empty($tokenUrl) || empty($refreshToken)) {
            return false; // nothing to rotate (paste-token-only connection)
        }

        // P6-68: secrets + client credentials go here — re-vet and pin at send time (DNS rebinding).
        $guard = SsrfGuard::pinnedOptions((string) $tokenUrl, false);
        if ($guard === null) {
            Log::warning('[accounting] token refresh blocked: token URL is not a safe public https endpoint');
            return false;
        }

        try {
            $res = Http::asForm()->timeout(20)->withOptions($guard)->post($tokenUrl, array_filter([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]));
        } catch (\Throwable $e) {
            Log::warning('[accounting] token refresh failed: ' . $e->getMessage());
            return false;
        }

        if ($res->failed() || empty($res->json('access_token'))) {
            Log::warning('[accounting] token refresh rejected: ' . $res->status());
            return false;
        }

        // Persist the ROTATED tokens atomically. The new refresh token REPLACES the
        // old one (which is now dead); providers that don't rotate omit it.
        $secrets['access_token'] = (string) $res->json('access_token');
        if ($res->json('refresh_token')) {
            $secrets['refresh_token'] = (string) $res->json('refresh_token');
        }
        if ($res->json('expires_in')) {
            $secrets['token_expires_at'] = now()->addSeconds((int) $res->json('expires_in'))->toIso8601String();
        }

        $credential->forceFill(['secrets' => $secrets])->save();
        return true;
    }

    /**
     * Refresh dormant accounting connections (token near/after expiry). Returns the
     * number refreshed. Called by the scheduled tick.
     */
    public function refreshDormant(): int
    {
        $n = 0;
        Credential::where('feature', 'accounting')->where('active', true)->chunkById(100, function ($rows) use (&$n) {
            foreach ($rows as $cred) {
                $expiresAt = (array) $cred->secrets;
                $exp = $expiresAt['token_expires_at'] ?? null;
                // Refresh when we can't tell, or it expires within 10 minutes.
                if ($exp === null || now()->addMinutes(10)->greaterThanOrEqualTo($exp)) {
                    if ($this->refresh($cred)) {
                        $n++;
                    }
                }
            }
        });
        return $n;
    }
}
