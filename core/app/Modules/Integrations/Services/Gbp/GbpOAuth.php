<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;


/**
 * google-partner Lane A — Google Business Profile OAuth2 authorization-code helpers
 * (pure/testable; the HTTP token exchange is {@see GbpTokenService}).
 *
 * SEPARATE OAuth client from the Socialite login flow (`services.google`): this uses
 * the SENSITIVE `business.manage` scope with offline access (refresh token) so
 * background jobs can sync menus / poll reviews after the owner has left the page.
 * `state` is CSRF protection — generated on redirect, verified constant-time on callback.
 *
 * Mirrors the FoodicsOAuth shape (authorizeUrl / stateMatches / newState).
 */
class GbpOAuth
{
    /** Google's OAuth2 authorization + token endpoints (identical to the Socialite ones, but a DIFFERENT client + scope). */
    public const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** The single scope the Business Profile APIs use (no narrower per-feature scope exists). */
    public const SCOPE = 'https://www.googleapis.com/auth/business.manage';

    /**
     * Build the authorize redirect URL with a CSRF state.
     *
     * access_type=offline + prompt=consent guarantee a refresh_token on first consent
     * (Google only returns one when consent is freshly granted).
     */
    public static function authorizeUrl(string $clientId, string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
            'include_granted_scopes' => 'false',
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    /** Constant-time state check for the callback. */
    public static function stateMatches(?string $expected, ?string $got): bool
    {
        if (! is_string($expected) || $expected === '' || ! is_string($got)) {
            return false;
        }

        return hash_equals($expected, $got);
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(16));
    }
}
