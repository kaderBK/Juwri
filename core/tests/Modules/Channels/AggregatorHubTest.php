<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class AggregatorHubTest extends TestCase
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

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'custom', 'provider' => 'custom',
            'secrets' => ['webhook_secret' => 'k'], 'active' => true,
        ]);

        return [$r, $item];
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', route('webhook.delivery.inbound', 'custom'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGGREGATOR_SIGNATURE' => hash_hmac('sha256', $body, 'k'),
        ], $body);
    }

    public function test_replayed_webhook_does_not_duplicate_order(): void
    {
        [$r, $item] = $this->storeFixture();
        $payload = ['id' => 'dup-1', 'store_id' => $r->id, 'items' => [['item_id' => $item->id, 'quantity' => 1]]];

        $first = $this->signedPost($payload)->assertStatus(201)->json('order_id');
        $second = $this->signedPost($payload)->assertStatus(201)->json('order_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    public function test_channel_fee_is_recorded(): void
    {
        [$r, $item] = $this->storeFixture();
        $res = $this->signedPost([
            'id' => 'fee-1', 'store_id' => $r->id, 'channel_fee' => 3.50,
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertEqualsWithDelta(3.50, (float) $order->channel_fee, 0.001);
        $this->assertSame('custom', $order->source);
    }

    public function test_unknown_store_is_rejected(): void
    {
        $this->storeFixture();
        $body = json_encode(['id' => 'x', 'store_id' => 99999, 'items' => []]);
        $this->call('POST', route('webhook.delivery.inbound', 'custom'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGGREGATOR_SIGNATURE' => hash_hmac('sha256', $body, 'k'),
        ], $body)->assertStatus(404);
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->postJson(route('webhook.delivery.inbound', 'nope-provider'), ['id' => 'x'])
            ->assertStatus(404);
    }
}
