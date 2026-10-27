<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Mcp\OwnerMcpServer;
use Juwri\Modules\Agents\Mcp\StorefrontMcpServer;
use Juwri\Modules\Agents\Tools\AgentTool;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;
use Juwri\Modules\Auth\Models\OAuthClient;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Laravel\Mcp\Server;
use Laravel\Passport\AccessToken;
use RuntimeException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-16: the owner and storefront MCP servers on laravel/mcp + the shared agent tool registry.
 *  - HTTP round trip (tools/list + tools/call) on both servers, served by laravel/mcp Server subclasses;
 *  - OAuth 2.1 via Passport: an authorization-code token with `mcp:use` works, its scopes are the tool abilities,
 *    a token without `mcp:use` or issued to a partner app is refused, 401 points at the discovery metadata;
 *  - tenancy: a token of account A (ApiToken or OAuth) never lists or calls on store B;
 *  - the storefront server no longer leaks an internal exception message (it answered -32601 + the message).
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://mcp-client.test/callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->artisan('passport:keys', ['--force' => true]);
        config(['settings.admin_allow_crm_suite' => 1]); // every owner tool listed (the CRM ones are feature-gated)
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** @return array{0: User, 1: Store} */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'storefront_api' => '1', 'agent_api' => '1'], 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'title' => 'Resto '.Str::random(3),
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1]);

        return [$user, $store];
    }

    private function apiToken(User $user, array $abilities = ['*']): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);

        return $raw;
    }

    private function oauthClient(): OAuthClient
    {
        return OAuthClient::create([
            'name' => 'MCP client', 'secret' => 'mcp-secret-mcp-secret-mcp-secret-123456', 'provider' => 'users',
            'redirect' => self::REDIRECT, 'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);
    }

    /** The authorization-code flow (consent, approve, code exchange, PKCE S256) — what an MCP client does. */
    private function oauthToken(User $user, string $scope, ?OAuthClient $client = null): string
    {
        $client ??= $this->oauthClient();
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $html = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => $scope,
            'state' => 'st', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]))->assertOk()->getContent();
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $html, $m);
        $location = $this->actingAs($user)->post('/oauth/authorize', ['state' => 'st', 'client_id' => $client->id, 'auth_token' => $m[1] ?? ''])
            ->assertRedirect()->headers->get('location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $q);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => 'mcp-secret-mcp-secret-mcp-secret-123456',
            'redirect_uri' => self::REDIRECT, 'code' => $q['code'] ?? '', 'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');

        // Fresh guards + no session user: the next requests authenticate by the bearer token only.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return (string) $token;
    }

    private function rpc(string $bearer, string $method, array $params = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params], ['Authorization' => 'Bearer '.$bearer]);
    }

    private function text($response): mixed
    {
        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    public function test_both_servers_are_laravel_mcp_servers(): void
    {
        $this->assertTrue(is_subclass_of(OwnerMcpServer::class, Server::class));
        $this->assertTrue(is_subclass_of(StorefrontMcpServer::class, Server::class));
    }

    public function test_owner_round_trip_over_http(): void
    {
        [$user, $store] = $this->owner();
        $raw = $this->apiToken($user);

        $list = $this->rpc($raw, 'tools/list')->assertOk()->assertJsonPath('id', 7);
        $names = array_column($list->json('result.tools'), 'name');
        $this->assertSame(array_keys(app(AgentToolRegistry::class)->tools(AgentTool::OWNER)), $names);
        $this->assertNull($list->json('result.resultType'), 'no laravel/mcp 2026 envelope keys');

        $menu = $this->rpc($raw, 'tools/call', ['name' => 'get_menu', 'arguments' => ['store_id' => $store->id]])->assertOk();
        $this->assertSame('Kabsa', $this->text($menu)[0]['items'][0]['name']);

        $created = $this->rpc($raw, 'tools/call', ['name' => 'add_category', 'arguments' => ['store_id' => $store->id, 'name' => 'Drinks']])->assertOk();
        $this->assertSame('Drinks', $this->text($created)['name']);
        $this->assertTrue(MenuCategory::where('store_id', $store->id)->where('name', 'Drinks')->exists());
    }

    public function test_storefront_round_trip_over_http(): void
    {
        [, $store] = $this->owner();
        $raw = Str::random(40);
        StorefrontToken::create(['store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);

        $list = $this->postJson(route('mcp.storefront'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['X-Storefront-Token' => $raw])->assertOk();
        $this->assertSame(array_keys(app(AgentToolRegistry::class)->tools(AgentTool::STOREFRONT, AgentTool::CHANNEL_MCP)), array_column($list->json('result.tools'), 'name'));

        $found = $this->postJson(route('mcp.storefront'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'search_menu', 'arguments' => ['query' => 'kab']]], ['X-Storefront-Token' => $raw])->assertOk();
        $this->assertSame('Kabsa', json_decode((string) $found->json('result.content.0.text'), true)[0]['name']);
    }

    public function test_storefront_does_not_leak_an_internal_exception_message(): void
    {
        [, $store] = $this->owner();
        $raw = Str::random(40);
        StorefrontToken::create(['store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);
        app(AgentToolRegistry::class)->register(new AgentTool(
            name: 'boom', description: 'test', inputSchema: ['type' => 'object', 'properties' => (object) []],
            audience: AgentTool::STOREFRONT, channels: [AgentTool::CHANNEL_MCP],
            handler: fn () => throw new RuntimeException('SQLSTATE[42S02]: secret table juwri_private'),
        ));

        $res = $this->postJson(route('mcp.storefront'), ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'boom']], ['X-Storefront-Token' => $raw])->assertOk();

        $res->assertJsonPath('error.code', -32603)->assertJsonPath('error.message', 'Internal error');
        $this->assertStringNotContainsString('SQLSTATE', (string) $res->getContent());
        // An unknown method is still -32601 with its name.
        $this->postJson(route('mcp.storefront'), ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'nope'], ['X-Storefront-Token' => $raw])
            ->assertOk()->assertJsonPath('error.code', -32601)->assertJsonPath('error.message', 'Method not found: nope');
    }

    public function test_oauth_token_with_mcp_use_lists_and_calls_with_its_scopes_as_abilities(): void
    {
        [$user, $store] = $this->owner();

        $readOnly = $this->oauthToken($user, 'mcp:use');
        $names = array_column($this->rpc($readOnly, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('get_menu', $names);
        $this->assertNotContains('add_category', $names, 'no menu:write scope, no write tool');
        $this->rpc($readOnly, 'tools/call', ['name' => 'add_category', 'arguments' => ['store_id' => $store->id, 'name' => 'X']])
            ->assertOk()->assertJsonPath('error.code', -32001);
        $this->assertSame([$store->id], array_column($this->text($this->rpc($readOnly, 'tools/call', ['name' => 'list_restaurants'])), 'id'));

        $writer = $this->oauthToken($user, 'mcp:use menu:write');
        $this->assertContains('add_category', array_column($this->rpc($writer, 'tools/list')->json('result.tools'), 'name'));
        $this->rpc($writer, 'tools/call', ['name' => 'add_category', 'arguments' => ['store_id' => $store->id, 'name' => 'Desserts']])
            ->assertOk()->assertJsonPath('error', null);
        $this->assertTrue(MenuCategory::where('store_id', $store->id)->where('name', 'Desserts')->exists());
    }

    public function test_oauth_token_without_mcp_use_or_of_a_partner_app_is_refused(): void
    {
        [$user] = $this->owner();

        $this->rpc($this->oauthToken($user, 'menu:read'), 'ping')->assertStatus(403)->assertJsonPath('message', "This token lacks the 'mcp:use' scope.");

        $client = $this->oauthClient();
        App::forceCreate(['name' => 'Partner', 'slug' => 'partner-'.Str::lower(Str::random(5)), 'oauth_client_id' => $client->id, 'scopes' => ['menu:read'], 'status' => 'approved']);
        $this->rpc($this->oauthToken($user, 'mcp:use', $client), 'ping')->assertStatus(403);
    }

    /**
     * Defence in depth: a Passport wildcard token (`*`, only from password / personal-access grants, none issued
     * today) would pass `mcp:use` and cover every ability including write — refused 403 before any tool runs.
     */
    /**
     * The api guard's user with an access token of these scopes (no grant issues a wildcard token, so the token is
     * built by hand; the default guard stays `web`).
     *
     * @param  list<string>  $scopes
     */
    private function actAsOAuth(User $user, array $scopes): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('api')->setUser($user->withAccessToken(new AccessToken(['oauth_scopes' => $scopes, 'oauth_client_id' => '0'])));
    }

    public function test_oauth_wildcard_token_is_refused(): void
    {
        [$user, $store] = $this->owner();
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'add_category', 'arguments' => ['store_id' => $store->id, 'name' => 'Wild']]];

        // Control: the same path with named scopes is served (the api guard user carries a hand-made token).
        $this->actAsOAuth($user, ['mcp:use', 'menu:write']);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer a.b.c'])->assertOk();

        $this->actAsOAuth($user, ['*']);
        $this->postJson('/mcp', $body, ['Authorization' => 'Bearer a.b.c'])
            ->assertStatus(403)->assertJsonPath('message', "Wildcard-scoped tokens cannot use the MCP server; request 'mcp:use' and the scopes you need.");
        $this->actAsOAuth($user, ['mcp:use', '*']);
        $this->postJson('/mcp', $body, ['Authorization' => 'Bearer a.b.c'])->assertStatus(403);

        $this->assertFalse(MenuCategory::where('name', 'Wild')->exists());
    }

    public function test_unauthenticated_owner_mcp_points_at_the_oauth_metadata(): void
    {
        $res = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401);
        $this->assertStringContainsString('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"', (string) $res->headers->get('WWW-Authenticate'));
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer a.b.c'])->assertStatus(401);

        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
            ->assertJsonPath('resource', url('/mcp'))->assertJsonPath('scopes_supported', ['mcp:use']);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonMissingPath('registration_endpoint');
    }

    public function test_a_token_of_account_a_never_lists_or_calls_on_store_b(): void
    {
        [$alice, $aStore] = $this->owner();
        [, $bStore] = $this->owner();
        $bItem = Menu::where('store_id', $bStore->id)->value('id');
        $bCategory = MenuCategory::where('store_id', $bStore->id)->value('id');

        foreach (['api' => $this->apiToken($alice), 'oauth' => $this->oauthToken($alice, 'mcp:use menu:write orders:read')] as $kind => $bearer) {
            $stores = array_column($this->text($this->rpc($bearer, 'tools/call', ['name' => 'list_restaurants'])), 'id');
            $this->assertSame([$aStore->id], $stores, "{$kind}: lists only Alice's stores");
            foreach ([
                ['get_menu', ['store_id' => $bStore->id]],
                ['list_orders', ['store_id' => $bStore->id]],
                ['get_order', ['store_id' => $bStore->id, 'order_id' => 1]],
                ['update_category', ['store_id' => $bStore->id, 'category_id' => $bCategory, 'name' => 'Hacked']],
                ['update_category', ['store_id' => $aStore->id, 'category_id' => $bCategory, 'name' => 'Hacked']],
                ['set_item_availability', ['store_id' => $aStore->id, 'item_id' => $bItem, 'available' => false]],
            ] as [$tool, $args]) {
                $this->rpc($bearer, 'tools/call', ['name' => $tool, 'arguments' => $args])
                    ->assertOk()->assertJsonPath('error.code', -32004);
            }
        }
        $this->assertSame('Kabsa', Menu::find($bItem)->name);
        $this->assertTrue((bool) Menu::find($bItem)->active);
        $this->assertSame('Mains', MenuCategory::find($bCategory)->name);
    }

    public function test_rate_limits_and_route_names_are_kept(): void
    {
        $owner = app('router')->getRoutes()->getByName('mcp');
        $this->assertSame(['POST'], $owner->methods());
        $this->assertTrue(collect($owner->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:60,1,')), 'a prefixed throttle:60,1 throttle');
        $this->assertContains('ratelimit:mcp', $owner->gatherMiddleware());

        $storefront = app('router')->getRoutes()->getByName('mcp.storefront');
        $this->assertSame('mcp/storefront', $storefront->uri());
        $this->assertContains('throttle:storefront', $storefront->gatherMiddleware());
        $this->assertContains('ratelimit:mcp', $storefront->gatherMiddleware());
    }
}
