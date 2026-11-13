<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAP-3 (platform, security-critical): storefront token auth + scopes + plan gate
 * + tenant isolation for the public Storefront API.
 */
class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(bool $apiEnabled = true, string $dish = 'Dish'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            // Plan.settings is cast to 'object' — pass an array (Laravel encodes on save).
            // Do NOT json_encode here or it double-encodes and reads back as a string.
            'settings' => [
                'restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1',
                'storefront_api' => $apiEnabled ? '1' : '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $dish, 'price' => 9, 'active' => 1]);

        return $store;
    }

    /** @return array{0:StorefrontToken,1:string} */
    private function token(Store $store, array $scopes = ['menu:read'], bool $active = true): array
    {
        $raw = Str::random(48);
        $t = StorefrontToken::create([
            'store_id' => $store->id,
            'token' => hash('sha256', $raw),
            'scopes' => $scopes,
            'active' => $active,
        ]);

        return [$t, $raw];
    }

    private function getMenu(string $raw)
    {
        return $this->getJson(route('api.v1.storefront.menu'), ['X-Storefront-Token' => $raw]);
    }

    private function firstItemId(Store $store): int
    {
        return (int) Menu::where('store_id', $store->id)->value('id');
    }

    private function postOrder(string $raw, array $body)
    {
        return $this->postJson(route('api.v1.storefront.orders.create'), $body, ['X-Storefront-Token' => $raw]);
    }

    public function test_valid_token_returns_menu(): void
    {
        $r = $this->storeFixture(true, 'Alpha');
        [, $raw] = $this->token($r);

        $this->getMenu($raw)->assertOk()
            ->assertJsonPath('categories.0.items.0.name', 'Alpha');
    }

    public function test_production_header_only_mode_rejects_query_token_but_accepts_header(): void
    {
        config(['auth.token_query_fallback' => false]);
        $store = $this->storeFixture();
        [, $raw] = $this->token($store);

        $this->getJson(route('api.v1.storefront.menu').'?storefront_token='.$raw)
            ->assertUnauthorized();
        $this->getMenu($raw)->assertOk();
    }

    public function test_legacy_query_fallback_is_logged_and_marked_deprecated_when_enabled(): void
    {
        config(['auth.token_query_fallback' => true]);
        $store = $this->storeFixture();
        [, $raw] = $this->token($store);
        Log::spy();

        $this->getJson(route('api.v1.storefront.menu').'?storefront_token='.$raw)
            ->assertOk()
            ->assertHeader('Deprecation', 'true');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => $message === 'auth.token_in_query'
                && ($context['mw'] ?? null) === 'storefront_token')
            ->once();
    }

    public function test_missing_token_is_401(): void
    {
        $this->getJson(route('api.v1.storefront.menu'))->assertStatus(401);
    }

    public function test_invalid_token_is_401(): void
    {
        $this->getMenu('not-a-real-token')->assertStatus(401);
    }

    public function test_revoked_token_is_401(): void
    {
        $r = $this->storeFixture();
        [, $raw] = $this->token($r, ['menu:read'], active: false);
        $this->getMenu($raw)->assertStatus(401);
    }

    public function test_token_without_scope_is_403(): void
    {
        $r = $this->storeFixture();
        [, $raw] = $this->token($r, ['agent']); // no menu:read
        $this->getMenu($raw)->assertStatus(403);
    }

    public function test_plan_without_storefront_api_is_403(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $r = $this->storeFixture(apiEnabled: false);
        [, $raw] = $this->token($r);
        $this->getMenu($raw)->assertStatus(403);
    }

    public function test_token_only_reaches_its_own_restaurant(): void
    {
        $a = $this->storeFixture(true, 'Alpha');
        $b = $this->storeFixture(true, 'Beta');
        [, $rawA] = $this->token($a);

        $res = $this->getMenu($rawA)->assertOk();
        $names = collect($res->json('categories'))->flatMap(fn ($c) => collect($c['items'])->pluck('name'));
        $this->assertTrue($names->contains('Alpha'));
        $this->assertFalse($names->contains('Beta'), 'Token must not reach another restaurant.');
    }

    /**
     * Verify the 'storefront' limiter definition directly: the budget is the
     * configured value and the key is per-token (not per-IP). We assert the Limit
     * the closure produces rather than firing 100+ HTTP requests — the array cache
     * store does not persist across separate test requests, so a cross-request 429
     * is not reliably observable here; Laravel's ThrottleRequests enforces the Limit.
     */
    public function test_storefront_limiter_is_per_token_and_configurable(): void
    {
        config(['api.storefront_rate' => 7]);
        $r = $this->storeFixture();
        [$tokenModel, $raw] = $this->token($r);

        // The key is per token, resolved from the raw header (the limiter runs before StorefrontTokenAuth binds it).
        $limiter = RateLimiter::limiter('storefront');
        $request = Request::create('/api/v1/storefront/menu', 'GET', server: ['HTTP_X_STOREFRONT_TOKEN' => $raw]);
        $limit = $limiter($request);
        $limit = is_array($limit) ? $limit[0] : $limit;

        $this->assertSame(7, $limit->maxAttempts, 'budget comes from config api.storefront_rate');
        $this->assertSame('sft:'.$tokenModel->id, $limit->key, 'per-token key');

        // Without a token — or with a token bound in the container by an earlier request but none sent — per IP.
        app()->instance('api.storefront_token', $tokenModel);
        $limit2 = $limiter(Request::create('/api/v1/storefront/menu'));
        $limit2 = is_array($limit2) ? $limit2[0] : $limit2;
        $this->assertStringStartsWith('sft-ip:', $limit2->key);
    }

    // --- P1-7 storefront order-write --------------------------------------

    public function test_order_write_token_creates_unpaid_order(): void
    {
        $r = $this->storeFixture(true, 'Alpha');
        [, $raw] = $this->token($r, ['order:write']);
        $item = $this->firstItemId($r);

        $res = $this->postOrder($raw, [
            'type' => 'takeaway',
            'customer_name' => 'Sam',
            'items' => [['item_id' => $item, 'quantity' => 2]],
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('ok', true);
        $this->assertNotEmpty($res->json('track_token'));
        $this->assertDatabaseHas('orders', ['store_id' => $r->id, 'source' => 'storefront_api', 'is_paid' => 0]);
    }

    public function test_order_write_requires_order_write_scope(): void
    {
        $r = $this->storeFixture();
        [, $raw] = $this->token($r, ['menu:read']); // no order:write
        $item = $this->firstItemId($r);
        $this->postOrder($raw, ['type' => 'takeaway', 'items' => [['item_id' => $item, 'quantity' => 1]]])
            ->assertStatus(403);
    }

    public function test_order_write_gated_by_plan_feature(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $r = $this->storeFixture(apiEnabled: false); // plan lacks storefront_api
        [, $raw] = $this->token($r, ['order:write']);
        $item = $this->firstItemId($r);
        $this->postOrder($raw, ['type' => 'takeaway', 'items' => [['item_id' => $item, 'quantity' => 1]]])
            ->assertStatus(403);
    }

    public function test_order_write_rejects_foreign_item(): void
    {
        $r = $this->storeFixture(true, 'Alpha');
        $other = $this->storeFixture(true, 'Beta');
        [, $raw] = $this->token($r, ['order:write']);
        $foreign = $this->firstItemId($other);

        // an item from another restaurant must reject the whole order (422)
        $this->postOrder($raw, ['type' => 'takeaway', 'items' => [['item_id' => $foreign, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'code'])
            ->assertJsonPath('code', 'unprocessable')
            ->assertJsonMissingPath('error');
        $this->assertDatabaseMissing('orders', ['store_id' => $r->id, 'source' => 'storefront_api']);
    }

    public function test_order_write_errors_use_the_api_error_envelope(): void
    {
        $r = $this->storeFixture(true, 'Alpha');
        [, $raw] = $this->token($r, ['order:write']);
        $item = $this->firstItemId($r);

        // P3-06b (kit/api README §6, API-4): the contract's codes
        $this->postOrder($raw, ['type' => 'takeaway', 'items' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', 'items must be an array.')
            ->assertJsonMissingPath('error');

        $bad = $this->postOrder($raw, ['type' => 'teleport', 'items' => [['item_id' => $item, 'quantity' => 1]]]);
        $bad->assertStatus(422)->assertJsonPath('code', 'invalid_order_type')->assertJsonMissingPath('error');
        $this->assertStringContainsString('Allowed types: ', $bad->json('message'));
    }

    public function test_order_write_idempotency_conflict_is_enveloped_409(): void
    {
        $r = $this->storeFixture(true, 'Alpha');
        [, $raw] = $this->token($r, ['order:write']);
        $item = $this->firstItemId($r);
        $uuid = (string) Str::uuid();
        $headers = ['X-Storefront-Token' => $raw, 'Idempotency-Key' => $uuid];

        $this->postJson(route('api.v1.storefront.orders.create'), ['type' => 'takeaway', 'items' => [['item_id' => $item, 'quantity' => 1]]], $headers)
            ->assertStatus(201);
        $this->postJson(route('api.v1.storefront.orders.create'), ['type' => 'takeaway', 'items' => [['item_id' => $item, 'quantity' => 3]]], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict')
            ->assertJsonStructure(['message', 'code'])
            ->assertJsonMissingPath('error');
    }

    public function test_order_write_route_is_csrf_exempt(): void
    {
        $prop = new \ReflectionProperty(PreventRequestForgery::class, 'except');
        $prop->setAccessible(true);
        $this->assertContains('api/v1/*', $prop->getDefaultValue());
    }
}
