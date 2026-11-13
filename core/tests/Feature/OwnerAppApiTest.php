<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OwnerApp;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner mobile app (Capacitor shell) API — rev/mobile-app/IMPLEMENTATION-SPEC.md.
 *
 * The load-bearing guarantees:
 *  - only a kind=app token can be exchanged for a dashboard web session;
 *  - login applies the web login's rules (blocked, admin, TOTP 2FA);
 *  - the hand-off URL is single-use and only lands on a relative same-site path;
 *  - devices and stores are scoped to the stores the owner owns.
 */
class OwnerAppApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        config(['settings.admin_allow_mobile_app_push' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function owner(array $attrs = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create(array_merge([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(8)).'@owner.test',
            'password' => Hash::make(self::PASSWORD),
        ], $attrs));
    }

    private function store(User $owner, string $type = 'restaurant'): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(User $user, array $extra = [])
    {
        return $this->postJson('/api/v1/app/auth/login', array_merge([
            'username' => $user->email,
            'password' => self::PASSWORD,
            'device_id' => 'device-1',
            'platform' => 'ios',
            'device_name' => 'Test iPhone',
        ], $extra));
    }

    private function appToken(User $user, string $device = 'device-1'): string
    {
        return (string) $this->login($user, ['device_id' => $device])->assertOk()->json('token');
    }

    private function bearer(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw];
    }

    // ── config ────────────────────────────────────────────────────────────────

    public function test_config_is_public_and_reports_the_version_gate(): void
    {
        config(['mobile_app.min_version.ios' => '1.2.0', 'mobile_app.latest_version.ios' => '1.5.0']);

        $this->getJson('/api/v1/app/config?platform=ios&version=1.1.9')
            ->assertOk()
            ->assertJsonPath('api_version', 1)
            ->assertJsonPath('versions.ios.update_required', true)
            ->assertJsonPath('versions.ios.update_available', true)
            ->assertJsonPath('links.login', '/login');

        $this->getJson('/api/v1/app/config?platform=ios&version=1.5.0')
            ->assertJsonPath('versions.ios.update_required', false)
            ->assertJsonPath('versions.ios.update_available', false);
    }

    // ── login ─────────────────────────────────────────────────────────────────

    public function test_login_issues_a_hashed_app_token_and_lists_only_owned_stores(): void
    {
        $owner = $this->owner();
        $a = $this->store($owner);
        $b = $this->store($owner, 'retail');
        $this->store($this->owner()); // someone else's

        $res = $this->login($owner)->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonCount(2, 'stores')
            ->assertJsonPath('default_store_id', (int) $a->id)
            ->assertJsonPath('stores.1.group', 'goods')
            ->assertJsonPath('user.email', $owner->email);

        $raw = (string) $res->json('token');
        $this->assertSame(64, strlen($raw));

        $token = ApiToken::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('app', $token->kind);
        $this->assertSame('device-1', $token->device_id);
        $this->assertSame('ios', $token->platform);
        $this->assertSame(hash('sha256', $raw), $token->token, 'only the hash is stored');
        $this->assertNotNull($token->expires_at);
        $this->assertContains('orders:write', $token->abilities);
        $this->assertArrayNotHasKey('password', $res->json('user'));
        $this->assertContains((int) $b->id, array_column($res->json('stores'), 'id'));
    }

    public function test_login_accepts_a_username_as_well_as_an_email(): void
    {
        $owner = $this->owner();

        $this->login($owner, ['username' => $owner->username])->assertOk();
    }

    public function test_device_name_is_optional(): void
    {
        $owner = $this->owner();

        $this->postJson('/api/v1/app/auth/login', [
            'username' => $owner->email, 'password' => self::PASSWORD,
            'device_id' => 'device-9', 'platform' => 'android',
        ])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame('Mobile app · Android', ApiToken::where('device_id', 'device-9')->value('name'));
    }

    public function test_bad_credentials_get_one_generic_answer(): void
    {
        $owner = $this->owner();

        $this->login($owner, ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

        $this->login($owner, ['username' => 'nobody@nowhere.test'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

        $this->assertSame(0, ApiToken::count());
    }

    public function test_blocked_and_admin_accounts_are_refused(): void
    {
        $this->login($this->owner(['status' => 0]))
            ->assertStatus(403)->assertJsonPath('code', 'account_blocked');

        $this->login($this->owner(['user_type' => 'admin']))
            ->assertStatus(403)->assertJsonPath('code', 'admin_not_supported');

        $this->assertSame(0, ApiToken::count());
    }

    public function test_two_factor_is_enforced_and_a_recovery_code_is_single_use(): void
    {
        $owner = $this->owner();
        $owner->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['RECOVERY-CODE-1'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->login($owner)->assertStatus(401)->assertJsonPath('code', 'two_factor_required');
        $this->login($owner, ['otp' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->assertSame(0, ApiToken::count());

        $this->login($owner, ['otp' => 'RECOVERY-CODE-1'])->assertOk();
        $this->login($owner, ['otp' => 'RECOVERY-CODE-1'])->assertStatus(422);
    }

    public function test_logging_in_again_on_the_same_device_replaces_its_token(): void
    {
        $owner = $this->owner();

        $this->appToken($owner, 'device-1');
        $this->appToken($owner, 'device-1');
        $this->assertSame(1, ApiToken::where('user_id', $owner->id)->count());

        $this->appToken($owner, 'device-2');
        $this->assertSame(2, ApiToken::where('user_id', $owner->id)->count());
    }

    // ── me / tabs ─────────────────────────────────────────────────────────────

    public function test_me_lays_out_tabs_for_the_store_vertical(): void
    {
        $owner = $this->owner();
        $food = $this->store($owner);
        $shop = $this->store($owner, 'retail');
        $raw = $this->appToken($owner);

        $res = $this->getJson('/api/v1/app/me?store_id='.$food->id, $this->bearer($raw))->assertOk()
            ->assertJsonPath('store.id', (int) $food->id)
            ->assertJsonCount(5, 'tabs')
            ->assertJsonPath('tabs.0.key', 'home')
            ->assertJsonPath('tabs.4.key', 'more')
            ->assertJsonPath('tabs.4.path', null)
            ->assertJsonPath('onboarding_path', null);
        $this->assertStringEndsWith('/stores/'.$food->id.'/menu', (string) $res->json('tabs.2.path'));
        $this->assertNotEmpty($res->json('more'));

        $res = $this->getJson('/api/v1/app/me?store_id='.$shop->id, $this->bearer($raw))->assertOk();
        $this->assertStringContainsString('/orders/board', (string) $res->json('tabs.1.path'));
        $this->assertStringEndsWith('/stores/'.$shop->id.'/products', (string) $res->json('tabs.2.path'));
    }

    public function test_me_ignores_a_store_the_owner_does_not_own(): void
    {
        $owner = $this->owner();
        $mine = $this->store($owner);
        $theirs = $this->store($this->owner());

        $this->getJson('/api/v1/app/me?store_id='.$theirs->id, $this->bearer($this->appToken($owner)))
            ->assertOk()->assertJsonPath('store.id', (int) $mine->id);
    }

    public function test_an_owner_without_a_store_is_sent_to_onboarding(): void
    {
        $owner = $this->owner();

        $this->getJson('/api/v1/app/me', $this->bearer($this->appToken($owner)))
            ->assertOk()
            ->assertJsonPath('tabs', [])
            ->assertJsonPath('onboarding_path', '/stores/create');
    }

    // ── the security boundary ─────────────────────────────────────────────────

    public function test_an_integration_token_can_never_reach_the_app_routes(): void
    {
        $owner = $this->owner();
        $this->store($owner);
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id, 'name' => 'zapier', 'token' => hash('sha256', $raw),
            'abilities' => ['*'], // even an all-powerful integration token
        ]);

        $this->getJson('/api/v1/app/me', $this->bearer($raw))
            ->assertStatus(403)->assertJsonPath('code', 'app_token_required');
        $this->postJson('/api/v1/app/session', [], $this->bearer($raw))
            ->assertStatus(403)->assertJsonPath('code', 'app_token_required');
    }

    public function test_app_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/app/me')->assertStatus(401);
        $this->postJson('/api/v1/app/session')->assertStatus(401);
    }

    public function test_a_token_issued_before_a_block_stops_working(): void
    {
        $owner = $this->owner();
        $raw = $this->appToken($owner);
        $owner->forceFill(['status' => 0])->save();

        $this->getJson('/api/v1/app/me', $this->bearer($raw))
            ->assertStatus(403)->assertJsonPath('code', 'account_blocked');
    }

    // ── session hand-off ──────────────────────────────────────────────────────

    public function test_the_handoff_logs_the_webview_in_exactly_once(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $raw = $this->appToken($owner);

        $url = (string) $this->postJson('/api/v1/app/session', ['to' => '/orders', 'store_id' => $store->id], $this->bearer($raw))
            ->assertOk()->assertJsonPath('expires_in', 60)->json('url');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#^/api/v1/app/handoff/[A-Za-z0-9]{64}$#', $path);

        $this->get($path)->assertRedirect('/orders')->assertSessionHas('dashboard_store_id', (int) $store->id);
        $this->assertAuthenticatedAs($owner, 'web');

        // Single use: the same URL a second time does not log anybody in.
        auth()->forgetGuards();
        $this->get($path)->assertRedirect('/login');
    }

    public function test_the_handoff_refuses_unsafe_destinations(): void
    {
        $owner = $this->owner();
        $raw = $this->appToken($owner);

        foreach (['https://evil.test/x', '//evil.test/x', 'javascript:alert(1)', '/api/v1/app/handoff/abc'] as $to) {
            $this->postJson('/api/v1/app/session', ['to' => $to], $this->bearer($raw))->assertStatus(422);
        }
    }

    public function test_the_handoff_refuses_a_store_the_owner_does_not_own(): void
    {
        $owner = $this->owner();
        $theirs = $this->store($this->owner());

        $this->postJson('/api/v1/app/session', ['store_id' => $theirs->id], $this->bearer($this->appToken($owner)))
            ->assertStatus(404);
    }

    public function test_a_revoked_token_cannot_complete_a_pending_handoff(): void
    {
        $owner = $this->owner();
        $raw = $this->appToken($owner);
        $path = (string) parse_url((string) $this->postJson('/api/v1/app/session', [], $this->bearer($raw))->json('url'), PHP_URL_PATH);

        ApiToken::where('user_id', $owner->id)->delete(); // owner revoked the device meanwhile

        $this->get($path)->assertRedirect('/login');
        $this->assertGuest('web');
    }

    // ── devices ───────────────────────────────────────────────────────────────

    public function test_device_registration_is_owner_laned_and_tenant_scoped(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $theirs = $this->store($this->owner());
        $raw = $this->appToken($owner);

        $this->postJson('/api/v1/app/devices', [
            'store_id' => $store->id, 'token' => 'fcm-token-ios', 'platform' => 'ios',
            'device_name' => 'Owner iPhone', 'app_version' => '1.0.0',
        ], $this->bearer($raw))->assertOk()->assertJsonPath('device.name', 'Owner iPhone');

        $sub = PushSubscription::where('endpoint_hash', hash('sha256', 'fcm-token-ios'))->firstOrFail();
        $this->assertSame((int) $owner->id, (int) $sub->user_id);
        $this->assertNull($sub->staff_id);
        $this->assertSame((int) $store->id, (int) $sub->store_id);
        $this->assertSame('apns', $sub->platform);

        $this->postJson('/api/v1/app/devices', [
            'store_id' => $theirs->id, 'token' => 'x', 'platform' => 'android',
        ], $this->bearer($raw))->assertStatus(404);
    }

    public function test_device_registration_reports_a_killed_push_feature(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $raw = $this->appToken($owner);

        config(['settings.admin_allow_mobile_app_push' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->postJson('/api/v1/app/devices', [
            'store_id' => $store->id, 'token' => 't', 'platform' => 'android',
        ], $this->bearer($raw))->assertStatus(403)->assertJsonPath('code', 'feature_disabled');
    }

    public function test_logout_revokes_the_token_and_forgets_the_push_device(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $raw = $this->appToken($owner);
        $this->postJson('/api/v1/app/devices', [
            'store_id' => $store->id, 'token' => 'push-1', 'platform' => 'android',
        ], $this->bearer($raw))->assertOk();

        $this->postJson('/api/v1/app/auth/logout', ['push_token' => 'push-1'], $this->bearer($raw))->assertOk();

        $this->assertSame(0, ApiToken::where('user_id', $owner->id)->count());
        $this->assertSame(0, PushSubscription::where('user_id', $owner->id)->count());
        $this->getJson('/api/v1/app/me', $this->bearer($raw))->assertStatus(401);
    }

    // ── deep links + in-app detection ─────────────────────────────────────────

    public function test_association_files_stay_dark_until_configured(): void
    {
        config(['mobile_app.apple_team_id' => '', 'mobile_app.android_sha256_fingerprints' => []]);
        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
        $this->get('/.well-known/assetlinks.json')->assertNotFound();

        config([
            'mobile_app.apple_team_id' => 'ABCDE12345',
            'mobile_app.app_id' => 'com.juwri.owner',
            'mobile_app.android_sha256_fingerprints' => ['AA:BB'],
        ]);
        $this->get('/.well-known/apple-app-site-association')->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'ABCDE12345.com.juwri.owner')
            ->assertJsonPath('webcredentials.apps.0', 'ABCDE12345.com.juwri.owner');
        $this->get('/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'com.juwri.owner')
            ->assertJsonPath('0.target.sha256_cert_fingerprints.0', 'AA:BB');
    }

    public function test_an_in_app_web_logout_is_marked_for_the_shell(): void
    {
        $owner = $this->owner();
        $ua = ['User-Agent' => 'Mozilla/5.0 (iPhone) JuwriApp/1.0.0 (ios)'];

        $this->actingAs($owner)->withHeaders($ua)->post('/logout')
            ->assertRedirect(route('login', ['app_logout' => 1]));

        // A normal browser logout is untouched.
        $this->actingAs($owner)->withHeaders(['User-Agent' => 'Mozilla/5.0 Safari'])->post('/logout')
            ->assertRedirect('/');
    }

    public function test_the_app_user_agent_is_recognised(): void
    {
        $inApp = Request::create('/dashboard', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) AppleWebKit/605 JuwriApp/1.4.2 (ios)',
        ]);
        $browser = Request::create('/dashboard', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Safari']);

        $this->assertTrue(OwnerApp::inApp($inApp));
        $this->assertSame('1.4.2', OwnerApp::appVersion($inApp));
        $this->assertFalse(OwnerApp::inApp($browser));
        $this->assertNull(OwnerApp::appVersion($browser));
    }
}
