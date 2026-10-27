<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Middleware;

use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * F6-5 (feature-platform gap-fix): make `app_installations.granted_scopes`
 * LOAD-BEARING.
 *
 * The install froze the scope set the merchant consented to, but nothing ever
 * intersected it at request time — an app whose approved scope list later grew
 * could reach the wider surface with a token it minted itself, and a merchant
 * who re-consented (or narrowed) saw no effect until a re-install.
 *
 * This middleware is the intersection point. It runs on the partner-app rails
 * and computes, PER REQUEST and straight from the database:
 *
 *     effective = token scopes  ∩  installation.granted_scopes  ∩  app.scopes
 *
 * - `installation.granted_scopes` — what THIS merchant consented to. Read live,
 *   so a re-consent (widening) takes effect on the very next request with the
 *   same token and no re-install; a narrowing (merchant/admin drops a scope, or
 *   the app's approved list shrinks) revokes on the very next request too.
 * - `app.scopes` — what the app is currently approved for. Keeps a stale frozen
 *   grant from outliving a scope the platform has since pulled.
 * - token scopes — what the partner actually asked for on this token (OAuth
 *   rail only; the embedded session-token rail has no per-token scopes).
 *
 * Usage: `EnforceAppScope::class.':orders:read'` — the request must hold that
 * scope or it is refused. Without an argument it only resolves and publishes
 * the effective set on the request (`app_effective_scopes`).
 *
 * Failure shape is a JSON 403 with `error: insufficient_scope` — these are
 * server-to-server / XHR calls, so never a redirect. 403 (not 401): the caller
 * is authenticated fine, it simply was not granted this.
 */
class EnforceAppScope
{
    public function handle(Request $request, Closure $next, ?string $required = null)
    {
        /** @var App|null $app */
        $app = $request->attributes->get('app');
        /** @var AppInstallation|null $installation */
        $installation = $request->attributes->get('app_installation');
        $tokenScopes = null;

        if (! $app instanceof App) {
            // OAuth rail: the app identity comes from the TOKEN's client, never
            // from client input (same rule as the usage/license endpoints).
            $user = $request->user('api');
            /** @var \Laravel\Passport\AccessToken<mixed>|null $token Passport 13: bearer tokens are AccessToken (attributes + Token fallback) */
            $token = $user?->token();
            $app = $token ? App::where('oauth_client_id', $token->client_id)->first() : null;

            if (! $user instanceof User || ! $app) {
                return $this->deny($request, null, $required, [], 'Token does not belong to a registered app.');
            }

            $tokenScopes = array_values((array) ($token->scopes ?? []));
            $installation = AppInstallation::where('app_id', $app->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();
        }

        if (! $installation instanceof AppInstallation) {
            return response()->json(['message' => 'App is not installed for this account.'], 403);
        }

        // The consent ledger, live. Both rails re-read it inside this request,
        // so there is no window where a stale grant is honoured.
        $granted = $this->normalize($installation->granted_scopes);
        $approved = $this->normalize($app->scopes);

        $effective = $approved === [] ? $granted : array_values(array_intersect($granted, $approved));

        // A `*` token scope means "everything the token could have"; the install
        // grant still bounds it.
        if ($tokenScopes !== null && ! in_array('*', $tokenScopes, true)) {
            $effective = array_values(array_intersect($effective, $tokenScopes));
        }

        $request->attributes->set('app_effective_scopes', $effective);

        if ($required !== null && ! in_array($required, $effective, true)) {
            return $this->deny($request, $app, $required, $effective);
        }

        return $next($request);
    }

    /** @return list<string> */
    private function normalize(mixed $scopes): array
    {
        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($s) => is_string($s) ? trim($s) : null,
            $scopes,
        ))));
    }

    /** @param  list<string>  $effective */
    private function deny(Request $request, ?App $app, ?string $required, array $effective, ?string $message = null)
    {
        \App\Services\PlatformMetrics::record('app.scope_denied', $app->slug ?? 'unknown-app');

        return response()->json([
            'error' => 'insufficient_scope',
            'message' => $message ?? "This app has not been granted the '{$required}' scope for this account. The restaurant owner must accept the updated permissions.",
            'required_scope' => $required,
            'granted_scopes' => $effective,
        ], 403);
    }
}
