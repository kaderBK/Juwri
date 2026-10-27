<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class AggregatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);
        $this->withoutMiddleware();
        // A2: the deliverect inbound path is gated on aggregator_deliverect (dark).
        config(['settings.admin_allow_aggregator_deliverect' => true]);
    }

    private function ownerRestaurant(): array
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

        return [$owner, $r];
    }

    public function test_connect_creates_connection_and_credential(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->actingAs($owner)->post(route('stores.aggregators.connect').'?store_id='.$r->id, [
            'provider' => 'deliverect', 'external_store_id' => 'CHLINK-9', 'webhook_secret' => 's3cr3t',
        ])->assertRedirect();

        $this->assertDatabaseHas('aggregator_connections', [
            'provider' => 'deliverect', 'external_store_id' => 'CHLINK-9', 'store_id' => $r->id,
        ]);
        $cred = Credential::for('deliverect', $r->id);
        $this->assertSame('s3cr3t', $cred['webhook_secret']);
    }

    public function test_pause_toggles_and_blocks_ingestion(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'D', 'price' => 10, 'active' => 1]);
        $conn = AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-P', 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'deliverect', 'provider' => 'deliverect',
            'secrets' => ['webhook_secret' => 'k'], 'active' => true,
        ]);

        // Pause it.
        $this->actingAs($owner)->post(route('stores.aggregators.pause', $conn->id))->assertRedirect();
        $this->assertSame('paused', $conn->fresh()->status);

        // A signed webhook to a paused channel is accepted but creates no order.
        $body = json_encode(['channelLinkId' => 'CHLINK-P', 'orderId' => 'P-1', 'items' => [['plu' => (string) $item->id, 'quantity' => 1]]]);
        $this->call('POST', route('webhook.delivery.inbound', 'deliverect'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERVER_AUTHORIZATION_HMAC_SHA256' => hash_hmac('sha256', $body, 'k'),
        ], $body)->assertStatus(202);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_index_shows_commission_report(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-R', 'status' => 'active',
        ]);
        // Two delivery orders with fees.
        foreach ([['O1', 2.0], ['O2', 3.5]] as [$ext, $fee]) {
            $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'new', 'source' => 'deliverect', 'channel_fee' => $fee, 'created_at' => now()]);
            DeliveryOrder::create(['provider' => 'deliverect', 'external_id' => $ext, 'order_id' => $order->id, 'store_id' => $r->id]);
        }

        $this->actingAs($owner)->get(route('stores.aggregators.index').'?store_id='.$r->id)
            ->assertOk()->assertSee('5.50'); // 2.00 + 3.50 commission
    }

    public function test_connect_is_tenant_scoped_pause(): void
    {
        [$ownerA, $rA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $foreign = AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $rB->id, 'external_store_id' => 'X', 'status' => 'active',
        ]);
        $this->actingAs($ownerA)->post(route('stores.aggregators.pause', $foreign->id))->assertNotFound();
        $this->assertSame('active', $foreign->fresh()->status);
    }
}
