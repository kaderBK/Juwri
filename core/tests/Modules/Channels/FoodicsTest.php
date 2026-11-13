<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FakeFoodicsClient;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsClientInterface;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsImporter;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsOAuth;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsProvider;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N1 — Foodics two-way POS: OAuth, import (dry-run + PosItemMap), order push
 * (calculate-first + UUID idempotency + unmapped soft-fail), webhooks, owner UI.
 */
class FoodicsTest extends TestCase
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
        FakeFoodicsClient::reset();
        $this->app->bind(FoodicsClientInterface::class, fn () => new FakeFoodicsClient);
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

    // ---- P1 ------------------------------------------------------------------

    public function test_oauth_state_and_authorize_url(): void
    {
        $url = FoodicsOAuth::authorizeUrl(true, 'cid', 'https://app.test/cb', 'st8', []);
        $this->assertStringContainsString('console-sandbox.foodics.com/authorize', $url);
        $this->assertStringContainsString('state=st8', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertTrue(FoodicsOAuth::stateMatches('st8', 'st8'));
        $this->assertFalse(FoodicsOAuth::stateMatches('st8', 'nope'));
        $this->assertFalse(FoodicsOAuth::stateMatches(null, 'x'));
    }

    public function test_provider_test_connection_and_list_items(): void
    {
        [$user, $r] = $this->owner();
        $conn = PosBridgeConnection::create(['provider' => 'foodics', 'store_id' => $r->id, 'status' => 'active']);
        $provider = new FoodicsProvider;
        $this->assertTrue($provider->testConnection($conn));
        $items = $provider->listItems($conn);
        $this->assertNotEmpty($items);
        $this->assertSame('p_burger', $items[0]['external_id']);
    }

    // ---- P2 ------------------------------------------------------------------

    public function test_import_preview_and_commit_writes_map_and_is_idempotent(): void
    {
        [$user, $r] = $this->owner();
        $importer = new FoodicsImporter(new FakeFoodicsClient);

        $preview = $importer->preview($r->id);
        $this->assertSame(2, $preview['total']);
        $this->assertSame(2, $preview['items_new']);

        $first = $importer->import($r->id);
        $this->assertSame(2, $first['created']);
        $this->assertSame(2, Menu::where('store_id', $r->id)->count());
        $this->assertSame(2, PosItemMap::where('provider', 'foodics')->where('store_id', $r->id)->count());
        // Arabic localized name carried over.
        $burger = Menu::where('store_id', $r->id)->where('name', 'Burger')->first();
        $this->assertSame('برجر', $burger->translations->ar->title);

        // Re-import = update mapped only, never duplicate.
        $second = $importer->import($r->id);
        $this->assertSame(2, $second['updated']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, Menu::where('store_id', $r->id)->count());
    }

    public function test_import_honours_the_plan_menu_limit(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        // Owner on a plan capped at 1 menu item; the fake catalog has 2 products.
        $plan = TestPlans::create([
            'name' => 'Cap'.Str::random(4), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => (object) ['restaurant_limit' => 999, 'menu_limit' => 1, 'category_limit' => 999, 'pos_bridge' => '1'],
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);

        $res = new FoodicsImporter(new FakeFoodicsClient)->import($r->id);

        $this->assertSame(1, $res['created'], 'import created past the plan menu limit');
        $this->assertSame(1, $res['skipped_items'], 'the over-limit item was not reported as skipped');
        $this->assertSame(1, Menu::where('store_id', $r->id)->count(), 'menu limit was bypassed by the POS import');
    }

    public function test_reimport_never_deletes_owner_items(): void
    {
        [$user, $r] = $this->owner();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Owner', 'active' => '1', 'position' => 1]);
        $ownItem = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Owner Dish', 'price' => 9, 'active' => '1', 'position' => 1]);

        new FoodicsImporter(new FakeFoodicsClient)->import($r->id);

        $this->assertNotNull(Menu::find($ownItem->id)); // owner item survives
    }

    // ---- P3 ------------------------------------------------------------------

    private function paidOrder(Store $r, Menu $menu): Order
    {
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1,
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return $order->load('items');
    }

    public function test_push_calculates_first_then_creates_with_uuid_and_dup_is_success(): void
    {
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 25, 'active' => '1']);
        PosItemMap::create(['provider' => 'foodics', 'store_id' => $r->id, 'menu_id' => $menu->id, 'external_id' => 'p_burger']);
        $conn = PosBridgeConnection::create(['provider' => 'foodics', 'store_id' => $r->id, 'status' => 'active', 'external_location_id' => 'br_1']);

        $order = $this->paidOrder($r, $menu);
        $provider = new FoodicsProvider;

        $res = $provider->pushOrder($order, $conn);
        $this->assertTrue($res->ok);
        $this->assertNotEmpty(FakeFoodicsClient::$calculated); // calculator ran
        $this->assertNotEmpty(FakeFoodicsClient::$created);
        $this->assertArrayHasKey('foodics_totals', $res->raw);

        // A retry with the same order → dup 422 → still success (idempotent).
        $res2 = $provider->pushOrder($order, $conn);
        $this->assertTrue($res2->ok);
        $this->assertSame(1, count(FakeFoodicsClient::$created)); // no second create
    }

    public function test_unmapped_item_soft_fails(): void
    {
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Unmapped', 'price' => 10, 'active' => '1']);
        $conn = PosBridgeConnection::create(['provider' => 'foodics', 'store_id' => $r->id, 'status' => 'active', 'external_location_id' => 'br_1']);
        $order = $this->paidOrder($r, $menu);

        $res = (new FoodicsProvider)->pushOrder($order, $conn);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('unmapped_items', (string) $res->error);
        $this->assertEmpty(FakeFoodicsClient::$created);
    }

    public function test_webhook_updates_push_and_flags_menu_drift_and_cross_tenant_404(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        [$user, $r] = $this->owner();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 25, 'active' => '1']);
        $conn = PosBridgeConnection::create(['provider' => 'foodics', 'store_id' => $r->id, 'status' => 'active']);
        Credential::create(['feature' => 'foodics_webhook', 'store_id' => $r->id, 'provider' => 'foodics', 'secrets' => ['webhook_token' => 'tok123'], 'active' => true]);
        $order = $this->paidOrder($r, $menu);
        $push = PosOrderPush::create(['order_id' => $order->id, 'provider' => 'foodics', 'store_id' => $r->id, 'status' => 'pushed', 'external_id' => 'fo_x']);

        // order.updated → status recorded on the push.
        $this->postJson(route('webhook.foodics.inbound', 'tok123'), [
            'event' => 'application.order.updated',
            'data' => ['meta' => ['reference' => (string) $order->id], 'id' => 'fo_x', 'status' => 4],
        ])->assertOk();
        $this->assertSame('closed', $push->fresh()->provider_meta['foodics_status']);

        // P6-100: a late, retried "active" update never reopens a closed order.
        $this->postJson(route('webhook.foodics.inbound', 'tok123'), [
            'event' => 'application.order.updated',
            'data' => ['meta' => ['reference' => (string) $order->id], 'id' => 'fo_x', 'status' => 2],
        ])->assertOk();
        $this->assertSame('closed', $push->fresh()->provider_meta['foodics_status']);

        // menu.updated → drift flag.
        $this->postJson(route('webhook.foodics.inbound', 'tok123'), ['event' => 'menu.updated'])->assertOk();
        $this->assertTrue($conn->fresh()->settings['menu_drift']);

        // Unknown token → 404 (no tenant leak).
        $this->postJson(route('webhook.foodics.inbound', 'wrong-token'), ['event' => 'menu.updated'])->assertNotFound();
    }

    // ---- P4 ------------------------------------------------------------------

    public function test_owner_page_renders_and_is_tenant_scoped(): void
    {
        [$userA, $rA] = $this->owner();
        [$userB, $rB] = $this->owner();

        $this->actingAs($userA)->get(route('stores.foodics.index', ['store_id' => $rA->id]))->assertOk()->assertSeeHtml('Foodics');

        // User A cannot view User B's restaurant.
        $this->actingAs($userA)->get(route('stores.foodics.index', ['store_id' => $rB->id]))
            ->assertForbidden();
    }

    public function test_connect_requires_configured_app_creds(): void
    {
        [$user, $r] = $this->owner();
        // No platform Foodics app creds → graceful redirect back (no crash).
        $this->actingAs($user)->post(route('stores.foodics.connect', ['store_id' => $r->id]), ['sandbox' => 1])
            ->assertRedirect();
    }
}
