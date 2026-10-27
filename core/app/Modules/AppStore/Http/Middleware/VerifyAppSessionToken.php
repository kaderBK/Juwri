<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Middleware;

use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use App\Services\AppSessionToken;
use Closure;
use Illuminate\Http\Request;

/**
 * P3-3: authenticates embedded-app API calls via the short-lived session-token
 * JWT (Bearer). Resolves the app from the token's audience (client id),
 * verifies the signature against that app's client secret, requires an ACTIVE
 * installation for the token's user, and exposes app/installation/user on the
 * request. 401 on any failure — never a redirect (these are XHR calls).
 */
class VerifyAppSessionToken
{
    public function handle(Request $request, Closure $next)
    {
        $jwt = $request->bearerToken();
        if (! $jwt) {
            \App\Services\PlatformMetrics::record('app.auth_fail', 'missing-token');

            return response()->json(['message' => 'Missing session token.'], 401);
        }

        // Cheap unverified aud read to locate the app, then FULL verification
        // against that app's secret (the decode below is the real gate).
        $parts = explode('.', $jwt);
        $payload = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        $app = is_array($payload) && ! empty($payload['aud'])
            ? App::where('oauth_client_id', (int) $payload['aud'])->first()
            : null;
        if (! $app) {
            \App\Services\PlatformMetrics::record('app.auth_fail', 'unknown-app');

            return response()->json(['message' => 'Unknown app.'], 401);
        }

        try {
            $claims = AppSessionToken::verify($jwt, $app);
        } catch (\Throwable $e) {
            \App\Services\PlatformMetrics::record('app.auth_fail', $app->slug);

            return response()->json(['message' => 'Invalid session token.'], 401);
        }

        $user = User::find((int) ($claims['sub'] ?? 0));
        $installation = $user ? AppInstallation::where('app_id', $app->id)
            ->where('user_id', $user->id)->where('status', 'active')->first() : null;
        if (! $user || ! $installation) {
            \App\Services\PlatformMetrics::record('app.auth_fail', $app->slug);

            return response()->json(['message' => 'App is not installed for this account.'], 401);
        }

        // F5-3: a banned tenant's embedded app is refused too. Stateless read of
        // users.status — the ban revokes nothing, so an unban restores access.
        if (\App\Services\Entitlements::isBanned($user)) {
            \App\Services\PlatformMetrics::record('app.auth_fail', $app->slug);

            return response()->json(['message' => 'This account is suspended.'], 403);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('app', $app);
        $request->attributes->set('app_installation', $installation);

        return $next($request);
    }
}
