<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * P3-1: the OAuth2 authorization server — full authorization_code flow:
 * consent screen (branded, scope-listing) → approve → code → token exchange →
 * scoped API access via the passport guard; deny path and scope enforcement.
 */
class OAuthAppAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://partner-app.test/callback';

    /**
     * BL-018 (flaky): Passport keys are minted ONCE PER PROCESS into this
     * process's own temporary directory, never --force-regenerated. The old
     * setUp() re-minted a fresh pair on every test run, which — when parallel
     * test processes share the same directory — raced the writers: a token
     * minted under the old pair was then verified against the new public key
     * ("Token signature mismatch"), or a request read a half-written pair
     * ("Unable to read key"). The directory is stable per process (memoised
     * static), so a parallel worker that runs this class several times
     * generates the keys only once.
     */
    private static ?string $keyDirName = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class]);

        // Per-process temp key dir (gitignored, no shared file is ever written).
        self::$keyDirName ??= 'passport-pid-'.(getmypid() ?: substr(md5(uniqid('', true)), 0, 16));
        $keyDir = storage_path('framework/testing/'.self::$keyDirName);
        $private = $keyDir.'/oauth-private.key';
        $public = $keyDir.'/oauth-public.key';

        // A run interrupted mid-seed (killed worker) leaves a truncated or
        // half-pair directory that a recycled pid would trust: validate what is
        // there and re-mint instead of loading garbage.
        if (is_file($private) && is_file($public)) {
            $readable = openssl_pkey_get_private((string) file_get_contents($private)) !== false
                && openssl_pkey_get_public((string) file_get_contents($public)) !== false;
            if (! $readable) {
                @unlink($private);
                @unlink($public);
            }
        }

        if (! is_file($private) || ! is_file($public)) {
            File::ensureDirectoryExists($keyDir);
            Passport::loadKeysFrom($keyDir);
            $this->artisan('passport:keys')->run(); // no --force: the dir belongs to this process alone
            $this->assertFileExists($private, 'the OAuth private key was minted for this process');
            $this->assertFileExists($public, 'the OAuth public key was minted for this process');
        }

        // TestCase re-points Passport at the worker key dir for every test; bring it back here.
        Passport::loadKeysFrom($keyDir);
    }

    private function makeClient(): Client
    {
        return Client::create([
            'name' => 'Partner App',
            'secret' => 'partner-secret-partner-secret-1234567890',
            'provider' => 'users',
            'redirect' => self::REDIRECT,
            'personal_access_client' => false,
            'password_client' => false,
            'revoked' => false,
        ]);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    public function test_consent_screen_shows_client_and_requested_scopes(): void
    {
        $client = $this->makeClient();

        $html = $this->actingAs($this->makeOwner())
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => self::REDIRECT,
                'response_type' => 'code',
                'scope' => 'menu:read orders:write',
                'state' => 'st4te',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Partner App', $html);
        $this->assertStringContainsString('menu:read', $html);
        $this->assertStringContainsString('orders:write', $html);
        $this->assertStringContainsString('Read menus', $html, 'human scope description shown');
    }

    public function test_full_authorization_code_flow_yields_a_scoped_working_token(): void
    {
        $client = $this->makeClient();
        $owner = $this->makeOwner();

        // 1. Consent screen → pull the one-time auth_token out of the form.
        $html = $this->actingAs($owner)
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => self::REDIRECT,
                'response_type' => 'code',
                'scope' => 'menu:read',
                'state' => 'st4te',
            ]))->assertOk()->getContent();
        preg_match('/name="auth_token" value="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? null, 'auth_token present in consent form');

        // 2. Approve → redirect back to the partner app with ?code=.
        $redirect = $this->actingAs($owner)->post('/oauth/authorize', [
            'state' => 'st4te',
            'client_id' => $client->id,
            'auth_token' => $m[1],
        ])->assertRedirect()->headers->get('location');

        $this->assertStringStartsWith(self::REDIRECT, $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
        $this->assertSame('st4te', $q['state']);
        $this->assertNotEmpty($q['code']);

        // 3. Exchange the code for tokens (what the partner server does).
        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => 'partner-secret-partner-secret-1234567890',
            'redirect_uri' => self::REDIRECT,
            'code' => $q['code'],
        ])->assertOk()->json();

        $this->assertSame('Bearer', $token['token_type']);
        $this->assertNotEmpty($token['access_token']);
        $this->assertNotEmpty($token['refresh_token']);

        // 4. The token authenticates the api guard and carries its scope.
        Route::middleware(['auth:api', 'scopes:menu:read'])->get('/_test/oauth-menu', fn () => ['ok' => true, 'user' => auth('api')->id()]);
        Route::middleware(['auth:api', 'scopes:orders:write'])->get('/_test/oauth-orders', fn () => ['ok' => true]);

        $this->flushHeaders();
        $this->getJson('/_test/oauth-menu', ['Authorization' => 'Bearer '.$token['access_token']])
            ->assertOk()->assertJson(['ok' => true, 'user' => $owner->id]);

        // 5. Scope enforcement: the token does NOT hold orders:write.
        $this->getJson('/_test/oauth-orders', ['Authorization' => 'Bearer '.$token['access_token']])
            ->assertForbidden();
    }

    public function test_api_guard_rejects_requests_without_a_token(): void
    {
        // Fresh test (no actingAs session) — bare request to a passport-guarded route.
        Route::middleware(['auth:api'])->get('/_test/oauth-bare', fn () => ['ok' => true]);

        $this->getJson('/_test/oauth-bare')->assertUnauthorized();
    }

    public function test_deny_redirects_back_with_access_denied(): void
    {
        $client = $this->makeClient();
        $owner = $this->makeOwner();

        $html = $this->actingAs($owner)
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => self::REDIRECT,
                'response_type' => 'code',
                'scope' => 'menu:read',
                'state' => 'st4te',
            ]))->assertOk()->getContent();
        preg_match('/name="auth_token" value="([^"]+)"/', $html, $m);

        $redirect = $this->actingAs($owner)->delete('/oauth/authorize', [
            'state' => 'st4te',
            'client_id' => $client->id,
            'auth_token' => $m[1],
        ])->assertRedirect()->headers->get('location');

        $this->assertStringContainsString('error=access_denied', $redirect);
    }

    public function test_defined_scopes_are_registered(): void
    {
        $ids = collect(Passport::scopes())->pluck('id')->all();
        foreach (['menu:read', 'menu:write', 'orders:read', 'orders:write', 'analytics:read', 'restaurant:read'] as $scope) {
            $this->assertContains($scope, $ids);
        }
    }
}
