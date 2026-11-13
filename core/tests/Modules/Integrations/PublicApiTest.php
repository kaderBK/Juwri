<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PublicApiTest extends TestCase
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

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'group_id' => (string) $this->makePlan()->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111',
            'title' => $title,
            'sub_title' => 'Test',
            'description' => 'Test',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test St',
            'phone' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Create a raw token and its hashed ApiToken model */
    private function makeToken(User $user, array $abilities = ['read']): array
    {
        $raw = Str::random(40);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Test Token',
            'token' => hash('sha256', $raw),
            'abilities' => $abilities,
        ]);

        return [$raw, $token];
    }

    // ── Authentication ──────────────────────────────────────────────────────

    /** No token → 401 */
    public function test_no_token_returns_401(): void
    {
        $this->getJson(route('api.v1.stores.index'))
            ->assertUnauthorized();
    }

    /** Invalid token → 401 */
    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('bad-token')
            ->getJson(route('api.v1.stores.index'))
            ->assertUnauthorized();
    }

    /** Valid read token → authenticated */
    public function test_valid_read_token_authenticated(): void
    {
        $user = $this->makeUser();
        [$raw] = $this->makeToken($user, ['read']);

        $this->withToken($raw)
            ->getJson(route('api.v1.stores.index'))
            ->assertOk();
    }

    /** Write endpoint requires write ability — read-only token → 403 */
    public function test_read_token_cannot_write(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'on-table',
            'created_at' => now(),
        ]);
        [$raw] = $this->makeToken($user, ['read']); // read-only

        $this->withToken($raw)
            ->putJson(route('api.v1.stores.orders.status', [$store->id, $order->id]), ['status' => 'preparing'])
            ->assertForbidden();
    }

    // ── Tenant scoping ───────────────────────────────────────────────────────

    /** /restaurants returns only owner's restaurants */
    public function test_restaurants_index_scoped_to_owner(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();

        $myRestaurant = $this->makeRestaurant($owner);
        $this->makeRestaurant($other); // should not appear

        [$raw] = $this->makeToken($owner);

        $response = $this->withToken($raw)
            ->getJson(route('api.v1.stores.index'))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($myRestaurant->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** /stores/{id} blocks access to another owner's restaurant */
    public function test_restaurant_show_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($other);

        [$raw] = $this->makeToken($owner);

        $this->withToken($raw)
            ->getJson(route('api.v1.stores.show', $otherRestaurant->id))
            ->assertNotFound();
    }

    /** /stores/{id}/orders scoped to restaurant owner */
    public function test_orders_endpoint_scoped(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'on-table',
            'created_at' => now(),
        ]);

        [$raw] = $this->makeToken($owner);

        $response = $this->withToken($raw)
            ->getJson(route('api.v1.stores.orders', $store->id))
            ->assertOk();

        $this->assertNotEmpty($response->json('data'));
    }

    // ── Write endpoint ───────────────────────────────────────────────────────

    /** PUT status updates order and returns new status */
    public function test_update_order_status_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'on-table',
            'created_at' => now(),
        ]);

        [$raw] = $this->makeToken($owner, ['read', 'write']);

        $this->withToken($raw)
            ->putJson(route('api.v1.stores.orders.status', [$store->id, $order->id]), ['status' => 'preparing'])
            ->assertOk()
            ->assertJson(['status' => 'preparing']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'preparing']);
    }

    // ── Webhook delivery row ──────────────────────────────────────────────────

    /** order.status_changed webhook delivery row written when endpoint exists */
    public function test_webhook_delivery_row_written_on_status_change(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'on-table',
            'created_at' => now(),
        ]);

        // Register a webhook endpoint (won't actually deliver in tests — curl will fail)
        $endpoint = WebhookEndpoint::create([
            'user_id' => $owner->id,
            'url' => 'http://localhost:9999/webhook-test',
            'events' => ['order.status_changed'],
            'active' => true,
        ]);

        [$raw] = $this->makeToken($owner, ['read', 'write']);

        $this->withToken($raw)
            ->putJson(route('api.v1.stores.orders.status', [$store->id, $order->id]), ['status' => 'ready'])
            ->assertOk();

        // A delivery row must be written regardless of curl outcome
        $this->assertDatabaseHas('webhook_deliveries', [
            'endpoint_id' => $endpoint->id,
            'event' => 'order.status_changed',
        ]);
    }

    // ── Expired token ─────────────────────────────────────────────────────────

    /** Expired token → 401 */
    public function test_expired_token_returns_401(): void
    {
        $user = $this->makeUser();
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Expired',
            'token' => hash('sha256', $raw),
            'abilities' => ['read'],
            'expires_at' => now()->subDay(),
        ]);

        $this->withToken($raw)
            ->getJson(route('api.v1.stores.index'))
            ->assertUnauthorized();
    }

    // ── Webhook signing + delivery metadata ──────────────────────────────────

    /** Standard-Webhooks signature is HMAC-SHA256(base64) over id.timestamp.body */
    public function test_standard_webhook_signature_is_verifiable(): void
    {
        $endpoint = WebhookEndpoint::create([
            'user_id' => $this->makeUser()->id,
            'url' => 'http://localhost:9999/hook',
            'events' => ['ping'],
            'active' => true,
            'secret' => 'whsec_test_secret',
        ]);

        $id = 'msg_'.Str::random(24);
        $ts = now()->getTimestamp();
        $body = '{"event":"ping"}';

        $sig = $endpoint->signStandard($id, $ts, $body);
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", 'whsec_test_secret', true));

        $this->assertSame($expected, $sig);
        // A tampered body must not verify.
        $this->assertNotSame($sig, $endpoint->signStandard($id, $ts, $body.'x'));
    }

    /** Delivery rows carry the attempt number and the webhook-id (event_id) */
    public function test_webhook_delivery_records_attempt_and_event_id(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'on-table',
            'created_at' => now(),
        ]);
        $endpoint = WebhookEndpoint::create([
            'user_id' => $owner->id,
            'url' => 'http://localhost:9999/hook',
            'events' => ['order.status_changed'],
            'active' => true,
        ]);
        [$raw] = $this->makeToken($owner, ['read', 'write']);

        $this->withToken($raw)
            ->putJson(route('api.v1.stores.orders.status', [$store->id, $order->id]), ['status' => 'ready'])
            ->assertOk();

        $delivery = WebhookDelivery::where('endpoint_id', $endpoint->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame(1, (int) $delivery->attempt);
        $this->assertStringStartsWith('msg_', (string) $delivery->event_id);
    }

    /** A paused endpoint receives no deliveries */
    public function test_paused_endpoint_is_not_delivered(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'on-table',
            'created_at' => now(),
        ]);
        $endpoint = WebhookEndpoint::create([
            'user_id' => $owner->id,
            'url' => 'http://localhost:9999/hook',
            'events' => ['order.status_changed'],
            'active' => false, // paused
        ]);
        [$raw] = $this->makeToken($owner, ['read', 'write']);

        $this->withToken($raw)
            ->putJson(route('api.v1.stores.orders.status', [$store->id, $order->id]), ['status' => 'ready'])
            ->assertOk();

        $this->assertDatabaseMissing('webhook_deliveries', ['endpoint_id' => $endpoint->id]);
    }
}
