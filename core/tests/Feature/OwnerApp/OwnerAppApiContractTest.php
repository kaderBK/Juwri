<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OwnerApp;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Models\PushSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContractSnapshots;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-34: contract tests for the 9 owner-app API routes (api/v1/app/*). The 4 owner-app billing routes live in
 * OwnerAppBillingContractTest in the Enterprise package (13 in total).
 *
 *   route                                   auth        429 limiter
 *   GET  api/v1/app/config                  public      throttle:60,1
 *   GET  api/v1/app/path-configuration/{p}  public      throttle:60,1
 *   POST api/v1/app/auth/login              public      auth-login (6/min per username|ip)
 *   GET  api/v1/app/handoff/{nonce}         nonce       throttle:30,1 (browser redirect, no JSON)
 *   GET  api/v1/app/me                      app token   owner-api (api.owner_rate per token)
 *   POST api/v1/app/session                 app token   owner-api
 *   POST api/v1/app/devices                 app token   owner-api
 *   POST api/v1/app/devices/unregister      app token   owner-api
 *   POST api/v1/app/auth/logout             app token   owner-api
 *
 * Per route: status + JSON shape snapshot (tests/Fixtures/owner-app/*.json), 401 without a token, 403 for a token that
 * is not an app token, cross-account scoping (owner A's token never reads or changes owner B's store/devices), 429 and
 * the validation error envelope. None of these routes paginate.
 *
 * Shapes are compared, not values. To change a contract on purpose: UPDATE_CONTRACT_SNAPSHOTS=1 (see ContractSnapshots).
 */
class OwnerAppApiContractTest extends TestCase
{
    use ContractSnapshots;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private const DIR = 'owner-app';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['settings.admin_allow_mobile_app_push' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(8)).'@owner.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function token(User $u, string $device = 'device-1'): string
    {
        return (string) $this->postJson('/api/v1/app/auth/login', [
            'username' => $u->email, 'password' => self::PASSWORD, 'device_id' => $device, 'platform' => 'ios',
        ])->assertOk()->json('token');
    }

    /** @return array<string, string> */
    private function bearer(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw];
    }

    private function integrationToken(User $u): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $u->id, 'name' => 'zapier', 'token' => hash('sha256', $raw), 'abilities' => ['*']]);

        return $raw;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tokenRoutes(): array
    {
        return [
            'me' => ['GET', '/api/v1/app/me'],
            'session' => ['POST', '/api/v1/app/session'],
            'devices' => ['POST', '/api/v1/app/devices'],
            'devices.unregister' => ['POST', '/api/v1/app/devices/unregister'],
            'logout' => ['POST', '/api/v1/app/auth/logout'],
        ];
    }

    // ── public routes ─────────────────────────────────────────────────────────

    public function test_config_contract_and_throttle(): void
    {
        $this->assertContract($this->getJson('/api/v1/app/config?platform=ios&version=1.0.0'), self::DIR, 'config.200', 200);
        $this->assertContract($this->getJson('/api/v1/app/config'), self::DIR, 'config.all-platforms.200', 200);

        for ($i = 0; $i < 58; $i++) {
            $this->getJson('/api/v1/app/config')->assertOk();
        }
        $this->assertContract($this->getJson('/api/v1/app/config'), self::DIR, 'config.429', 429);
    }

    public function test_path_configuration_contract_and_throttle(): void
    {
        $this->assertContract($this->getJson('/api/v1/app/path-configuration/ios.json'), self::DIR, 'path-configuration.200', 200);
        $this->getJson('/api/v1/app/path-configuration/android.json')->assertOk();
        $this->getJson('/api/v1/app/path-configuration/windows.json')->assertNotFound();

        for ($i = 0; $i < 58; $i++) {
            $this->getJson('/api/v1/app/path-configuration/ios.json')->assertOk();
        }
        $this->getJson('/api/v1/app/path-configuration/ios.json')->assertStatus(429);
    }

    public function test_login_contract_validation_errors_and_throttle(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $ok = $this->postJson('/api/v1/app/auth/login', [
            'username' => $owner->email, 'password' => self::PASSWORD, 'device_id' => 'd1', 'platform' => 'android',
        ]);
        $this->assertContract($ok, self::DIR, 'login.200', 200);

        $this->assertContract($this->postJson('/api/v1/app/auth/login', []), self::DIR, 'login.422-validation', 422);
        $this->assertContract($this->postJson('/api/v1/app/auth/login', [
            'username' => $owner->email, 'password' => 'wrong', 'device_id' => 'd1', 'platform' => 'ios',
        ]), self::DIR, 'login.422-credentials', 422);

        $owner->forceFill(['status' => 0])->save();
        $this->assertContract($this->postJson('/api/v1/app/auth/login', [
            'username' => $owner->email, 'password' => self::PASSWORD, 'device_id' => 'd1', 'platform' => 'ios',
        ]), self::DIR, 'login.403-blocked', 403);

        // auth-login: 6 per minute per username|ip — the 7th attempt for the same username is refused.
        $victim = $this->owner();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/app/auth/login', ['username' => $victim->email, 'password' => 'x', 'device_id' => 'd', 'platform' => 'ios'])
                ->assertStatus(422);
        }
        $this->assertContract($this->postJson('/api/v1/app/auth/login', [
            'username' => $victim->email, 'password' => self::PASSWORD, 'device_id' => 'd', 'platform' => 'ios',
        ]), self::DIR, 'login.429', 429);
    }

    public function test_handoff_is_a_single_use_redirect_and_throttled(): void
    {
        $owner = $this->owner();
        $raw = $this->token($owner);
        $url = (string) $this->postJson('/api/v1/app/session', [], $this->bearer($raw))->assertOk()->json('url');
        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertRedirect();
        $this->assertAuthenticatedAs($owner, 'web');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($path)->assertRedirect('/login'); // consumed

        $bogus = '/api/v1/app/handoff/'.str_repeat('a', 64);
        for ($i = 0; $i < 28; $i++) {
            $this->get($bogus)->assertRedirect('/login');
        }
        $this->get($bogus)->assertStatus(429);
    }

    // ── app-token routes: success shapes ──────────────────────────────────────

    public function test_token_route_success_contracts(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $raw = $this->token($owner);
        $h = $this->bearer($raw);

        $this->assertContract($this->getJson('/api/v1/app/me', $h), self::DIR, 'me.200', 200);
        $this->assertContract($this->postJson('/api/v1/app/session', ['to' => '/dashboard', 'store_id' => $store->id], $h), self::DIR, 'session.200', 200);
        $this->assertContract($this->postJson('/api/v1/app/devices', [
            'store_id' => $store->id, 'token' => 'push-a', 'platform' => 'ios', 'device_name' => 'iPhone',
        ], $h), self::DIR, 'devices.200', 200);
        $this->assertContract($this->postJson('/api/v1/app/devices/unregister', ['token' => 'push-a'], $h), self::DIR, 'devices.unregister.200', 200);
        $this->assertContract($this->postJson('/api/v1/app/auth/logout', [], $h), self::DIR, 'logout.200', 200);
    }

    public function test_me_contract_for_an_owner_without_a_store(): void
    {
        $this->assertContract($this->getJson('/api/v1/app/me', $this->bearer($this->token($this->owner()))), self::DIR, 'me.200-no-store', 200);
    }

    // ── app-token routes: auth ────────────────────────────────────────────────

    #[DataProvider('tokenRoutes')]
    public function test_token_routes_answer_401_without_a_token_or_with_a_bad_one(string $method, string $uri): void
    {
        $this->assertContract($this->json($method, $uri), self::DIR, 'token-route.401', 401);
        $this->json($method, $uri, [], $this->bearer(Str::random(64)))->assertStatus(401);
    }

    #[DataProvider('tokenRoutes')]
    public function test_token_routes_answer_403_to_a_non_app_token(string $method, string $uri): void
    {
        $owner = $this->owner();
        $this->store($owner);
        $res = $this->json($method, $uri, [], $this->bearer($this->integrationToken($owner)));
        $this->assertContract($res, self::DIR, 'token-route.403', 403);
        $res->assertJsonPath('code', 'app_token_required');
    }

    public function test_an_expired_app_token_is_refused(): void
    {
        $owner = $this->owner();
        $raw = $this->token($owner);
        ApiToken::where('user_id', $owner->id)->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/app/me', $this->bearer($raw))->assertStatus(401);
    }

    // ── app-token routes: cross-account scoping ───────────────────────────────

    public function test_a_token_never_reads_or_changes_another_owners_store(): void
    {
        $a = $this->owner();
        $mine = $this->store($a);
        $b = $this->owner();
        $theirs = $this->store($b);
        $ha = $this->bearer($this->token($a, 'dev-a'));
        $hb = $this->bearer($this->token($b, 'dev-b'));

        // B has a push device on B's store.
        $this->postJson('/api/v1/app/devices', ['store_id' => $theirs->id, 'token' => 'push-b', 'platform' => 'android'], $hb)->assertOk();

        // me: B's store id is ignored; nothing of B's leaks into the payload.
        $me = $this->getJson('/api/v1/app/me?store_id='.$theirs->id, $ha)->assertOk();
        $me->assertJsonPath('store.id', (int) $mine->id);
        $this->assertSame([(int) $mine->id], array_column((array) $me->json('stores'), 'id'));
        $this->assertStringNotContainsString((string) $theirs->slug, (string) $me->getContent());

        // session: cannot pre-select B's store for the web session.
        $this->assertContract($this->postJson('/api/v1/app/session', ['store_id' => $theirs->id], $ha), self::DIR, 'session.404-foreign-store', 404);

        // devices: cannot register a push token on B's store.
        $this->assertContract($this->postJson('/api/v1/app/devices', ['store_id' => $theirs->id, 'token' => 'push-x', 'platform' => 'ios'], $ha), self::DIR, 'devices.404-foreign-store', 404);
        $this->assertSame(0, PushSubscription::where('store_id', $theirs->id)->where('user_id', $a->id)->count());

        // unregister / logout with B's push token: B's device survives.
        $this->postJson('/api/v1/app/devices/unregister', ['token' => 'push-b'], $ha)->assertOk()->assertJsonPath('removed', false);
        $this->postJson('/api/v1/app/auth/logout', ['push_token' => 'push-b'], $ha)->assertOk();
        $this->assertSame(1, PushSubscription::where('endpoint_hash', hash('sha256', 'push-b'))->where('user_id', $b->id)->count());

        // A's logout revoked only A's token.
        $this->getJson('/api/v1/app/me', $hb)->assertOk()->assertJsonPath('user.id', (int) $b->id);
        $this->assertSame(0, ApiToken::where('user_id', $a->id)->count());
    }

    // ── app-token routes: validation + throttle ───────────────────────────────

    public function test_token_route_validation_error_shapes(): void
    {
        $owner = $this->owner();
        $h = $this->bearer($this->token($owner));

        $this->assertContract($this->postJson('/api/v1/app/session', ['to' => 'https://evil.test/x'], $h), self::DIR, 'session.422', 422);
        $this->assertContract($this->postJson('/api/v1/app/devices', [], $h), self::DIR, 'devices.422', 422);
        $this->assertContract($this->postJson('/api/v1/app/devices/unregister', [], $h), self::DIR, 'devices.unregister.422', 422);
    }

    public function test_token_routes_share_the_per_token_owner_api_budget(): void
    {
        config(['api.owner_rate' => 3]);
        $owner = $this->owner();
        $h = $this->bearer($this->token($owner));

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/app/me', $h)->assertOk();
        }
        $this->assertContract($this->postJson('/api/v1/app/session', [], $h), self::DIR, 'token-route.429', 429);

        // Another owner's token has its own bucket.
        $this->getJson('/api/v1/app/me', $this->bearer($this->token($this->owner(), 'other')))->assertOk();
    }
}
