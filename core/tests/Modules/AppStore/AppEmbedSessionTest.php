<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use Juwri\Core\Models\User;
use App\Services\AppInstallService;
use App\Services\AppSessionToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use Tests\TestCase;

/**
 * P3-3: embedded apps — the dashboard iframe host page (frame-ancestors),
 * 60-second session-token JWTs signed with the app's client secret, and the
 * verifying middleware for embedded API calls.
 */
class AppEmbedSessionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'embed-secret-embed-secret-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    private function makeEmbeddedApp(): App
    {
        $client = Client::create([
            'name' => 'Embed App', 'secret' => self::SECRET, 'provider' => 'users',
            'redirect' => 'https://embed-app.test/cb',
            'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);

        return App::create([
            'name' => 'Embed App', 'slug' => 'embed-app',
            'scopes' => ['menu:read'],
            'oauth_client_id' => $client->id,
            'embed_url' => 'https://embed-app.test/panel',
            'status' => 'approved',
        ]);
    }

    private function makeInstalledOwner(App $app): User
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        new AppInstallService()->install($app, $owner);

        return $owner;
    }

    public function test_embed_page_serves_iframe_with_frame_ancestors_guard(): void
    {
        $app = $this->makeEmbeddedApp();
        $owner = $this->makeInstalledOwner($app);

        $resp = $this->actingAs($owner)->get('apps/'.$app->id.'/embed');

        $resp->assertOk();
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $resp->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://embed-app.test/panel', $resp->getContent());
        $this->assertStringContainsString('mb:session-token', $resp->getContent(), 'token push wiring present');

        // Not installed → no embed.
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($stranger)->get('apps/'.$app->id.'/embed')->assertNotFound();
    }

    public function test_session_token_is_short_lived_and_verifiable_with_the_client_secret(): void
    {
        $app = $this->makeEmbeddedApp();
        $owner = $this->makeInstalledOwner($app);

        $json = $this->actingAs($owner)->getJson('apps/'.$app->id.'/session-token')
            ->assertOk()->json();

        $this->assertSame(AppSessionToken::TTL_SECONDS, $json['expires_in']);

        // The partner verifies with its own secret — exactly what the app does.
        $claims = (array) JWT::decode($json['token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame((string) $app->oauth_client_id, $claims['aud']);
        $this->assertSame((string) $owner->id, $claims['sub']);
        $this->assertSame('embed-app', $claims['app']);
        $this->assertLessThanOrEqual(time() + AppSessionToken::TTL_SECONDS, $claims['exp']);
    }

    public function test_middleware_authenticates_valid_tokens_and_rejects_bad_ones(): void
    {
        $app = $this->makeEmbeddedApp();
        $owner = $this->makeInstalledOwner($app);

        Route::middleware('auth.app-session')->get('/_test/app-api', function (\Illuminate\Http\Request $r) {
            return ['user' => $r->user()->id, 'app' => $r->attributes->get('app')->slug];
        });

        $token = AppSessionToken::mint($app, $owner);

        // Valid token → authenticated with the right user + app context.
        $this->getJson('/_test/app-api', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJson(['user' => $owner->id, 'app' => 'embed-app']);

        // Wrong signature → 401.
        $forged = JWT::encode([
            'aud' => (string) $app->oauth_client_id, 'sub' => (string) $owner->id,
            'iat' => time(), 'nbf' => time(), 'exp' => time() + 60,
        ], 'wrong-secret-wrong-secret-000000000000', 'HS256');
        $this->getJson('/_test/app-api', ['Authorization' => 'Bearer '.$forged])->assertUnauthorized();

        // Expired token → 401.
        $expired = JWT::encode([
            'aud' => (string) $app->oauth_client_id, 'sub' => (string) $owner->id,
            'iat' => time() - 300, 'nbf' => time() - 300, 'exp' => time() - 120,
        ], self::SECRET, 'HS256');
        $this->getJson('/_test/app-api', ['Authorization' => 'Bearer '.$expired])->assertUnauthorized();

        // No token → 401.
        $this->getJson('/_test/app-api')->assertUnauthorized();
    }

    public function test_uninstalled_app_tokens_stop_working(): void
    {
        $app = $this->makeEmbeddedApp();
        $owner = $this->makeInstalledOwner($app);

        Route::middleware('auth.app-session')->get('/_test/app-api2', fn () => ['ok' => true]);

        $token = AppSessionToken::mint($app, $owner);
        $this->getJson('/_test/app-api2', ['Authorization' => 'Bearer '.$token])->assertOk();

        new AppInstallService()->uninstall($app->installations()->first());

        // Same (still unexpired) token now fails — installation is inactive.
        $this->getJson('/_test/app-api2', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }
}
