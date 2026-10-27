<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Middleware;

use App\Http\Middleware\ApiTokenAuth;
use App\Models\App;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Contracts\Auth\AbilityToken;
use Juwri\Core\Http\Middleware\Concerns\StripsClientIdentity;
use Juwri\Core\Services\AbuseHold;
use Juwri\Core\Support\TokenAbilities;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-16: authentication of the owner MCP server (POST /mcp). Two credentials, one identity (the account):
 *  - the account's ApiToken (#99) — unchanged: delegated to ApiTokenAuth (401/403/423 exactly as before);
 *  - an OAuth 2.1 access token issued by Passport (authorization code + PKCE) carrying the `mcp:use` scope. Its
 *    other scopes become the tool abilities (menu:write, orders:read, ...); `read` is implied. A token without
 *    `mcp:use`, carrying the wildcard `*`, or issued to a partner app's client, is refused 403.
 * A 401 carries `WWW-Authenticate: Bearer resource_metadata=...` so an MCP client can discover the authorization
 * server (RFC 9728). Identity is written to request attributes only (client-supplied identity keys are stripped).
 */
class McpOwnerAuth
{
    use StripsClientIdentity;

    /** The scope that makes a Passport token an MCP credential (the laravel/mcp convention). */
    public const OAUTH_SCOPE = 'mcp:use';

    public function handle(Request $request, Closure $next): Response
    {
        $response = self::looksLikeJwt((string) $request->bearerToken())
            ? $this->viaOAuth($request, $next)
            : app(ApiTokenAuth::class)->handle($request, $next);

        if ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        }

        return $response;
    }

    /**
     * The caller's ability check for the shared tool registry: the ApiToken's abilities, or the OAuth token's
     * scopes (+ `read`) under the same coverage rules (`menu:write` covers `menu:read`, `*` covers all).
     *
     * @return Closure(string): bool
     */
    public static function abilities(Request $request): Closure
    {
        $token = $request->attributes->get('_api_token');
        if ($token instanceof AbilityToken) {
            return fn (string $ability): bool => $token->hasAbility($ability);
        }

        $scopes = $request->attributes->get('_mcp_oauth_scopes');
        if (! is_array($scopes)) {
            return fn (string $ability): bool => false;
        }
        // '*' never becomes an ability (viaOAuth refuses wildcard tokens; this is the second line).
        $granted = array_values(array_unique(array_merge(['read'], array_diff($scopes, [self::OAUTH_SCOPE, '*']))));

        return function (string $ability) use ($granted): bool {
            foreach ($granted as $scope) {
                if (TokenAbilities::covers((string) $scope, $ability)) {
                    return true;
                }
            }

            return false;
        };
    }

    private function viaOAuth(Request $request, Closure $next): Response
    {
        $this->stripClientIdentity($request);
        $request->attributes->remove('_mcp_oauth_scopes');

        $user = Auth::guard('api')->user();
        /** @var AccessToken<mixed>|null $token Passport 13: a bearer token is an AccessToken */
        $token = $user?->token();
        if ($user === null || $token === null) {
            return ApiError::status(401, 'Invalid or expired token.');
        }
        // Defence in depth: Passport's wildcard scope (password / personal-access grants, not issued here today)
        // would satisfy `mcp:use` AND, through abilityCovers('*'), every tool ability including write. An MCP
        // grant must name its scopes.
        $scopes = array_values((array) ($token->oauth_scopes ?? $token->scopes ?? []));
        if (in_array('*', $scopes, true)) {
            return ApiError::status(403, "Wildcard-scoped tokens cannot use the MCP server; request '".self::OAUTH_SCOPE."' and the scopes you need.");
        }
        if (! $token->can(self::OAUTH_SCOPE)) {
            return ApiError::status(403, "This token lacks the '".self::OAUTH_SCOPE."' scope.");
        }
        // A partner app's token never reaches the MCP server, even with `mcp:use`: its grant is the install's
        // frozen granted_scopes (EnforceAppScope), which this server does not apply.
        if (App::where('oauth_client_id', $token->client_id)->exists()) {
            return ApiError::status(403, 'Partner app tokens cannot use the MCP server.');
        }

        $request->attributes->set('_api_user_id', $user->id);
        $request->attributes->set('_mcp_oauth_scopes', $scopes);
        app()->instance('api.user', $user);

        // Abuse Guard P6 (6.3): a held merchant's API cannot WRITE (same rule as ApiTokenAuth).
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && AbuseHold::active((int) $user->id)) {
            return ApiError::status(423, 'Account is temporarily on hold pending review.');
        }

        return $next($request);
    }

    /** A Passport access token is a JWT (three base64url segments); an ApiToken is a random string without dots. */
    private static function looksLikeJwt(string $bearer): bool
    {
        return substr_count($bearer, '.') === 2;
    }
}
