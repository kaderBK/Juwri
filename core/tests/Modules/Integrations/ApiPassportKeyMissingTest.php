<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

/**
 * B1 (theme-audit-followups / API robustness): the Passport-guarded API routes
 * must DEGRADE GRACEFULLY — never a 500 — when the OAuth signing keys are
 * missing or invalid.
 *
 * The bug: the `api` guard is Passport's TokenGuard, and PassportServiceProvider
 * builds it eagerly by resolving the ResourceServer singleton, which constructs
 * a League CryptKey from the OAuth public key. With no key present that ctor
 * throws `LogicException: Invalid key supplied` while the guard is being
 * resolved — before any bearer token is looked at — so `auth:api` returned an
 * uncaught 500 (RouteSmokeSweepTest saw this in single-process runs, where the
 * parallel key-seeding in TestCase does not run). The EnsurePassportKeysConfigured
 * middleware, placed before `auth:api`, catches exactly that and returns a clean
 * 401 instead.
 */
class ApiPassportKeyMissingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The api group carries no session-y middleware, but be explicit so the
        // assertion is about auth/keys and nothing else.
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    /**
     * Point Passport at an empty key directory and drop the already-resolved
     * ResourceServer singleton + guards, so the very next request rebuilds the
     * guard against a key path that does not exist — the exact production
     * condition (a deploy that never ran `passport:keys`).
     */
    private function forceMissingKeys(): void
    {
        $dir = storage_path('framework/testing/passport-missing-'.uniqid());
        File::ensureDirectoryExists($dir); // deliberately EMPTY — no oauth-*.key

        Passport::loadKeysFrom($dir);
        $this->app->forgetInstance(ResourceServer::class);
        $this->app['auth']->forgetGuards();
    }

    public function test_api_user_returns_401_not_500_when_oauth_keys_are_missing(): void
    {
        $this->forceMissingKeys();

        $res = $this->getJson('/api/user');

        $this->assertNotSame(500, $res->status(), '/api/user must not 500 on a missing OAuth key');
        $res->assertStatus(401);
    }

    public function test_app_license_returns_401_not_500_when_oauth_keys_are_missing(): void
    {
        $this->forceMissingKeys();

        $res = $this->getJson('/api/app/license');

        $this->assertNotSame(500, $res->status(), '/api/app/license must not 500 on a missing OAuth key');
        $res->assertStatus(401);
    }

    /**
     * Guard against over-reach: with keys present the middleware is a pure
     * pass-through, so an UNauthenticated request still gets the ordinary
     * `auth:api` 401 (access is refused, never a 500 and never granted). The
     * valid-token -> 200 happy path is exercised by FeaturePlatformFix6LicenseTest,
     * which would break if this middleware altered the keys-present path.
     */
    public function test_keys_present_still_401s_an_unauthenticated_request(): void
    {
        $this->artisan('passport:keys', ['--force' => true])->run();
        $this->app->forgetInstance(ResourceServer::class);
        $this->app['auth']->forgetGuards();

        foreach (['/api/user', '/api/app/license'] as $uri) {
            $res = $this->getJson($uri);
            $this->assertNotSame(500, $res->status(), "{$uri} must not 500 with keys present");
            $res->assertStatus(401);
        }
    }
}
