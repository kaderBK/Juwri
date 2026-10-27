<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\Marn\FakeMarnClient;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnClientInterface;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnImporter;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnProvider;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N4 — Marn two-way POS: import (dry-run + PosItemMap), PAID/HOLD push + OrderNo,
 * unmapped soft-fail, webhook state matrix + services-update, owner UI + tenant scope.
 */
class MarnTest extends TestCase
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        config(['settings.admin_allow_pos_bridge' => true]);
        $this->withoutMiddleware();
        FakeMarnClient::reset();
        $this->app->bind(MarnClientInterface::class, fn () => new FakeMarnClient);
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

    private function conn(Store $r, bool $hold = false): PosBridgeConnection
    {
        Credential::create(['store_id' => $r->id, 'feature' => 'marn', 'provider' => 'marn', 'secrets' => ['client_id' => 'c', 'client_secret' => 's', 'brand_key' => 'BK'], 'active' => true]);
        Credential::create(['store_id' => $r->id, 'feature' => 'marn_webhook', 'provider' => 'marn', 'secrets' => ['webhook_token' => 'mtok'], 'active' => true]);

        return PosBridgeConnection::create([
            'provider' => 'marn', 'store_id' => $r->id, 'status' => 'active', 'external_location_id' => 'LK1',
            'settings' => ['brand_key' => 'BK', 'hold_mode' => $hold],
        ]);
    }

    private function paidOrder(Store $r, Menu $menu): Order
    {
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return $order->load('items');
    }

    public function test_import_writes_map_and_is_idempotent(): void
    {
        [$user, $r] = $this->owner();
        $importer = new MarnImporter(new FakeMarnClient);

        $preview = $importer->preview($r->id, 'LK1');
        $this->assertSame(2, $preview['total']);

        $first = $importer->import($r->id, 'LK1');
        $this->assertSame(2, $first['created']);
        $this->assertSame(2, PosItemMap::where('provider', 'marn')->count());
        $this->assertSame('شاورما', Menu::where('name', 'Shawarma')->first()->translations->ar->title);

        $second = $importer->import($r->id, 'LK1');
        $this->assertSame(2, $second['updated']);
        $this->assertSame(0, $second['created']);
    }

    public function test_paid_push_persists_orderno(): void
    {
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shawarma', 'price' => 18, 'active' => '1']);
        PosItemMap::create(['provider' => 'marn', 'store_id' => $r->id, 'menu_id' => $menu->id, 'external_id' => 'MP_SHAWARMA']);
        $conn = $this->conn($r);
        $order = $this->paidOrder($r, $menu);

        $res = new MarnProvider()->pushOrder($order, $conn);
        $this->assertTrue($res->ok);
        $this->assertStringStartsWith('MARN-P-', $res->externalId);
        $this->assertNotEmpty(FakeMarnClient::$paid);
        $this->assertEmpty(FakeMarnClient::$hold);
    }

    public function test_webhook_token_lives_in_vault_not_settings(): void
    {
        [$user, $r] = $this->owner();
        $conn = PosBridgeConnection::create([
            'provider' => 'marn', 'store_id' => $r->id, 'status' => 'active',
            'external_location_id' => 'LK1', 'settings' => ['brand_key' => 'BK'],
        ]);

        $token = $conn->ensureWebhookToken();
        $this->assertNotEmpty($token);
        // Not in the plaintext settings column.
        $this->assertArrayNotHasKey('webhook_token', $conn->fresh()->settings);
        // Stored encrypted: the raw DB value is not the plaintext token.
        $raw = DB::table('credentials')
            ->where('feature', 'marn_webhook')->where('store_id', $r->id)->value('secrets');
        $this->assertStringNotContainsString($token, (string) $raw);
        // Resolves the tenant by constant-time compare; wrong token → null.
        $this->assertTrue($conn->is(PosBridgeConnection::resolveByWebhookToken('marn', $token)));
        $this->assertNull(PosBridgeConnection::resolveByWebhookToken('marn', 'nope'));
        $this->assertSame($token, $conn->webhookToken());
    }

    public function test_retry_after_lost_response_does_not_duplicate_paid_order(): void
    {
        // Simulates: HTTP succeeded at Marn but the response was lost, so pushOrder runs
        // again for the same order. The deterministic idempotency key must make Marn dedupe
        // (dup → success), never a second PAID order.
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shawarma', 'price' => 18, 'active' => '1']);
        PosItemMap::create(['provider' => 'marn', 'store_id' => $r->id, 'menu_id' => $menu->id, 'external_id' => 'MP_SHAWARMA']);
        $conn = $this->conn($r);
        $order = $this->paidOrder($r, $menu);

        $first = new MarnProvider()->pushOrder($order, $conn);
        $second = new MarnProvider()->pushOrder($order, $conn);

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok, 'retry must succeed, not hard-fail');
        $this->assertCount(1, FakeMarnClient::$paid, 'exactly one PAID order reaches Marn across a retry');
        $this->assertTrue((bool) ($second->raw['duplicate'] ?? false), 'retry flagged as duplicate');
    }

    public function test_hold_mode_pushes_hold_order(): void
    {
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shawarma', 'price' => 18, 'active' => '1']);
        PosItemMap::create(['provider' => 'marn', 'store_id' => $r->id, 'menu_id' => $menu->id, 'external_id' => 'MP_SHAWARMA']);
        $conn = $this->conn($r, hold: true);
        $order = $this->paidOrder($r, $menu);

        $res = new MarnProvider()->pushOrder($order, $conn);
        $this->assertTrue($res->ok);
        $this->assertStringStartsWith('MARN-H-', $res->externalId);
        $this->assertNotEmpty(FakeMarnClient::$hold);
    }

    public function test_unmapped_item_soft_fails(): void
    {
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Nope', 'price' => 5, 'active' => '1']);
        $conn = $this->conn($r);
        $order = $this->paidOrder($r, $menu);

        $res = new MarnProvider()->pushOrder($order, $conn);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('unmapped_items', (string) $res->error);
    }

    public function test_webhook_state_matrix_services_update_and_cross_tenant(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shawarma', 'price' => 18, 'active' => '1']);
        $conn = $this->conn($r);
        $order = $this->paidOrder($r, $menu);
        $push = PosOrderPush::create(['order_id' => $order->id, 'provider' => 'marn', 'store_id' => $r->id, 'status' => 'pushed', 'external_id' => 'MARN-P-1']);

        $this->postJson(route('webhook.marn.inbound', 'mtok'), [
            'orderNo' => 'MARN-P-1', 'orderState' => 3, 'status' => 'Paid', 'locationKey' => 'LK1',
        ])->assertOk();
        $this->assertSame('ready', $push->fresh()->provider_meta['marn_state']);
        $this->assertSame('Paid', $push->fresh()->provider_meta['marn_status']);

        // P6-100: a late "waiting" update (retried, out of order) never moves a ready order back.
        $this->postJson(route('webhook.marn.inbound', 'mtok'), [
            'orderNo' => 'MARN-P-1', 'orderState' => 1, 'status' => 'Open', 'locationKey' => 'LK1',
        ])->assertOk();
        $this->assertSame('ready', $push->fresh()->provider_meta['marn_state']);
        $this->assertSame('Paid', $push->fresh()->provider_meta['marn_status']);

        $this->postJson(route('webhook.marn.inbound', 'mtok'), ['servicesUpdate' => true])->assertOk();
        $this->assertTrue($conn->fresh()->settings['menu_drift']);

        $this->postJson(route('webhook.marn.inbound', 'wrong'), ['orderNo' => 'x'])->assertNotFound();
    }

    public function test_owner_page_renders_and_tenant_scoped(): void
    {
        [$userA, $rA] = $this->owner();
        [$userB, $rB] = $this->owner();

        $this->actingAs($userA)->get(route('stores.marn.index', ['store_id' => $rA->id]))->assertOk()->assertSeeHtml('Marn');
        $this->actingAs($userA)->get(route('stores.marn.index', ['store_id' => $rB->id]))
            ->assertForbidden();
    }

    public function test_connect_stores_connection_and_masked_secret(): void
    {
        [$user, $r] = $this->owner();
        $this->actingAs($user)->post(route('stores.marn.connect', ['store_id' => $r->id]), [
            'client_id' => 'CID', 'client_secret' => 'SECRET', 'brand_key' => 'BK', 'location_key' => 'LK1',
        ])->assertRedirect();

        $this->assertDatabaseHas('pos_bridge_connections', ['provider' => 'marn', 'store_id' => $r->id, 'external_location_id' => 'LK1']);
        $this->assertSame('SECRET', Credential::for('marn', $r->id)['client_secret']);
    }
}
