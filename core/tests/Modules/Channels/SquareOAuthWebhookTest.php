<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareOAuth;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareTokenExchange;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N7 — Square OAuth (exchange/refresh/revoke, state, legacy BC) + webhooks (signature,
 * order.updated, catalog drift, cross-tenant).
 */
class SquareOAuthWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'pos_bridge' => '1']),
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $r];
    }

    // ---- OAuth (pure) --------------------------------------------------------

    public function test_authorize_url_and_state(): void
    {
        $url = SquareOAuth::authorizeUrl(true, 'sq-app', 'st', []);
        $this->assertStringContainsString('connect.squareupsandbox.com/oauth2/authorize', $url);
        $this->assertStringContainsString('state=st', $url);
        $this->assertTrue(SquareOAuth::stateMatches('st', 'st'));
        $this->assertFalse(SquareOAuth::stateMatches('st', 'x'));
    }

    public function test_token_exchange_refresh_revoke(): void
    {
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_at' => '2026-08-01T00:00:00Z', 'merchant_id' => 'MID'], 200),
            '*oauth2/revoke' => Http::response(['success' => true], 200),
        ]);

        $tok = SquareTokenExchange::exchange(true, 'app', 'secret', 'code');
        $this->assertSame('AT', $tok['access_token']);
        $this->assertSame('MID', $tok['merchant_id']);

        $ref = SquareTokenExchange::refresh(true, 'app', 'secret', 'RT');
        $this->assertSame('AT', $ref['access_token']);

        $this->assertTrue(SquareTokenExchange::revoke(true, 'app', 'secret', 'AT'));
    }

    public function test_oauth_callback_stores_token_and_connection(): void
    {
        Http::fake(['*oauth2/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'merchant_id' => 'MID'], 200)]);
        [$user, $r] = $this->owner();
        Credential::create(['store_id' => null, 'feature' => 'square', 'provider' => 'square', 'secrets' => ['app_id' => 'app', 'app_secret' => 'sec'], 'active' => true]);

        // Prime the session state via connect.
        $this->actingAs($user)->withSession(['square_oauth' => ['state' => 'S1', 'rid' => $r->id, 'sandbox' => true]])
            ->get(route('stores.square.callback', ['state' => 'S1', 'code' => 'auth-code']))
            ->assertRedirect();

        $secrets = Credential::for('square', $r->id);
        $this->assertSame('AT', $secrets['access_token']);
        $this->assertSame('oauth', $secrets['auth']);
        $this->assertDatabaseHas('pos_bridge_connections', ['provider' => 'square', 'store_id' => $r->id]);
    }

    public function test_callback_rejects_bad_state(): void
    {
        [$user, $r] = $this->owner();
        $this->actingAs($user)->withSession(['square_oauth' => ['state' => 'S1', 'rid' => $r->id, 'sandbox' => true]])
            ->get(route('stores.square.callback', ['state' => 'WRONG', 'code' => 'c']))
            ->assertForbidden();
    }

    // ---- Webhooks ------------------------------------------------------------

    private function webhook(array $payload, string $key = 'whkey', ?string $sigOverride = null)
    {
        $body = json_encode($payload);
        $url = route('webhook.square.inbound');
        $sig = $sigOverride ?? base64_encode(hash_hmac('sha256', $url.$body, $key, true));

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $sig,
        ], $body);
    }

    private function squareConn(Store $r, string $merchantId = 'MID'): PosBridgeConnection
    {
        Credential::updateOrCreate(
            ['store_id' => null, 'feature' => 'square', 'provider' => 'square'],
            ['secrets' => ['webhook_signature_key' => 'whkey'], 'active' => true]
        );

        return PosBridgeConnection::create(['provider' => 'square', 'store_id' => $r->id, 'status' => 'active', 'settings' => ['merchant_id' => $merchantId]]);
    }

    public function test_webhook_order_update_and_catalog_drift(): void
    {
        [$user, $r] = $this->owner();
        $conn = $this->squareConn($r);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $push = PosOrderPush::create(['order_id' => $order->id, 'provider' => 'square', 'store_id' => $r->id, 'status' => 'pushed', 'external_id' => 'sq_1']);

        $this->webhook(['type' => 'order.updated', 'merchant_id' => 'MID', 'data' => ['object' => ['order' => ['id' => 'sq_1', 'reference_id' => (string) $order->id, 'state' => 'COMPLETED']]]])
            ->assertOk();
        $this->assertSame('COMPLETED', $push->fresh()->provider_meta['square_state']);

        $this->webhook(['type' => 'catalog.version.updated', 'merchant_id' => 'MID'])->assertOk();
        $this->assertTrue($conn->fresh()->settings['catalog_drift']);
    }

    /** P6-100: Square retries and reorders — only a newer order version is kept; COMPLETED/CANCELED are final. */
    public function test_webhook_order_updates_are_forward_only(): void
    {
        [$user, $r] = $this->owner();
        $this->squareConn($r);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $push = PosOrderPush::create(['order_id' => $order->id, 'provider' => 'square', 'store_id' => $r->id, 'status' => 'pushed', 'external_id' => 'sq_2']);
        $update = fn (string $state, int $version) => $this->webhook(['type' => 'order.updated', 'merchant_id' => 'MID',
            'data' => ['object' => ['order_updated' => ['order_id' => 'sq_2', 'state' => $state, 'version' => $version]]]])->assertOk();

        $update('OPEN', 3);
        $update('DRAFT', 2); // older version arrives late
        $this->assertSame('OPEN', $push->fresh()->provider_meta['square_state']);
        $this->assertSame(3, $push->fresh()->provider_meta['square_version']);

        $update('COMPLETED', 5);
        $update('OPEN', 6); // never reopens a completed order
        $this->assertSame('COMPLETED', $push->fresh()->provider_meta['square_state']);
    }

    public function test_webhook_bad_signature_and_unknown_merchant(): void
    {
        [$user, $r] = $this->owner();
        $this->squareConn($r);
        $this->webhook(['type' => 'order.updated', 'merchant_id' => 'MID'], sigOverride: 'bad')->assertStatus(401);
        $this->webhook(['type' => 'order.updated', 'merchant_id' => 'OTHER'])->assertStatus(404);
    }
}
