<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\FakeJahezClient;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezMenuPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N5 — Jahez (DEFERRED-CERTIFICATION): shared-secret webhook, OpenItemCode mapping,
 * idempotency, bad-auth rejection, cross-tenant, auto-accept, menu push. All payload
 * contracts ASSUMED (JahezPayloadMapper) until portal access.
 */
class JahezTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        FakeJahezClient::reset();
        $this->app->bind(JahezClientInterface::class, fn () => new FakeJahezClient);
    }

    private function setupStore(string $branch = 'JZ-1'): array
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
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 12, 'active' => 1]);
        AggregatorConnection::create(['provider' => 'jahez', 'store_id' => $r->id, 'external_store_id' => $branch, 'status' => 'active']);
        Credential::create(['store_id' => $r->id, 'feature' => 'jahez', 'provider' => 'jahez', 'secrets' => ['api_key' => 'k', 'secret' => 's', 'webhook_secret' => 'jsecret'], 'active' => true]);
        return [$r, $item];
    }

    private function hook(array $payload, ?string $sigOverride = null)
    {
        $body = json_encode($payload);
        $sig = $sigOverride ?? hash_hmac('sha256', $body, 'jsecret');

        return $this->call('POST', route('webhook.delivery.inbound', 'jahez'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_JAHEZ_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_signed_order_ingests_maps_and_auto_accepts(): void
    {
        [$r, $item] = $this->setupStore();
        $res = $this->hook([
            'branch_id' => 'JZ-1', 'jahez_order_id' => 'J-1',
            'order' => ['commission' => 2.0, 'customer' => ['name' => 'Ali', 'phone' => '+96650'],
                'products' => [['product_id' => (string) $item->id, 'quantity' => 3, 'name' => 'Dish']]],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame('jahez', $order->source);
        $this->assertSame(3, (int) $order->items()->sum('quantity'));
        $this->assertContains('accept', array_column(FakeJahezClient::$calls, 'op'));
    }

    public function test_bad_signature_rejected(): void
    {
        $this->setupStore();
        $this->hook(['branch_id' => 'JZ-1', 'jahez_order_id' => 'J-2', 'order' => ['products' => []]], 'bad')
            ->assertStatus(401);
        $this->assertSame(0, Order::count());
    }

    public function test_replay_idempotent_and_cross_tenant_404(): void
    {
        [$r, $item] = $this->setupStore();
        $p = ['branch_id' => 'JZ-1', 'jahez_order_id' => 'J-3', 'order' => ['products' => [['product_id' => (string) $item->id, 'quantity' => 1]]]];
        $a = $this->hook($p)->assertStatus(201)->json('order_id');
        $b = $this->hook($p)->assertStatus(201)->json('order_id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Order::count());

        $this->hook(['branch_id' => 'OTHER', 'jahez_order_id' => 'J-9', 'order' => ['products' => []]])
            ->assertStatus(404);
    }

    public function test_menu_push_payload_bilingual_and_sold_out_disabled(): void
    {
        [$r, $item] = $this->setupStore();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => '1', 'position' => 1,
            'translations' => (object) ['ar' => (object) ['title' => 'كبسة']],
        ]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Gone', 'price' => 5, 'active' => '1', 'position' => 2, 'is_sold_out' => 1]);

        $payload = new JahezMenuPusher()->build($r->id, null, 'BR1');
        $this->assertSame('BR1', $payload['branch']);
        $kabsa = collect($payload['products'])->firstWhere('name', 'Kabsa');
        $this->assertSame('كبسة', $kabsa['name_ar']);
        $this->assertTrue($kabsa['available']);
        $gone = collect($payload['products'])->firstWhere('name', 'Gone');
        $this->assertFalse($gone['available']); // 86'd → disabled on Jahez
    }
}
