<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\VehicleFitment;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\TestCase;

/**
 * P4-12 — Owner API v1 (bearer ApiToken): cross-account and cross-store IDOR on EVERY resource route, read from the
 * live router (a new owner-token route with a path parameter fails here until it has a fixture), plus the token
 * lifecycle: create (dashboard, re-auth), scopes, revoke (refused on the very next call), closed / suspended owner.
 *
 * The entitlement gates (feature:*) are lifted so every request reaches the controller's own scoping — a 403 from a
 * plan gate would otherwise hide a missing ownership check.
 */
class OwnerApiIdorCensusTest extends TestCase
{
    use RefreshDatabase;

    /** Route parameter → fixture key. `id` is the store, except on the routes named in ID_IS. */
    private const PARAMS = ['id' => 'store', 'categoryId' => 'category', 'itemId' => 'item', 'orderId' => 'order',
        'brandId' => 'brand', 'variantId' => 'variant', 'fitmentId' => 'fitment'];

    /** Routes whose `{id}` is not a store. */
    private const ID_IS = ['api.v1.drivers.' => 'driver', 'api.v1.webhooks.' => 'webhook'];

    /**
     * B1-a: a REST-Hook unsubscribe is idempotent — deleting an endpoint the caller does not own (or that is gone)
     * answers 204 and deletes nothing (the snapshot below proves the other account's endpoint is still there).
     */
    private const IDEMPOTENT = ['api.v1.webhooks.destroy' => 204];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureFeatureEnabled::class]);
        config(['api.owner_rate' => 100000, 'tenancy.rate_limits.owner_api_per_account' => 100000]);
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'email' => Str::lower(Str::random(8)).'@api.test']);
    }

    /** @return array<string, int> one of every resource in a fresh store of $owner, all marked with $tag */
    private function world(User $owner, string $tag): array
    {
        $store = Store::create(['user_id' => $owner->id, 'store_type' => 'carparts', 'slug' => 'api-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => "Store {$tag}", 'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => "Cat {$tag}", 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => "Item {$tag}", 'price' => 9, 'active' => 1]);
        $brand = Brand::create(['store_id' => $store->id, 'name' => "Brand {$tag}"]);
        $variant = MenuVariant::create(['store_id' => $store->id, 'menu_id' => $item->id, 'sku' => "SKU{$tag}", 'stock_qty' => 5]);
        $fitment = VehicleFitment::create(['store_id' => $store->id, 'menu_id' => $item->id, 'year' => 2016, 'make' => "Make{$tag}", 'model' => 'M']);
        $order = Order::create(['store_id' => $store->id, 'type' => 'delivery', 'customer_name' => "Buyer {$tag}", 'phone_number' => '0500',
            'address' => '1 St', 'status' => 'pending', 'seen' => 0, 'track_token' => Str::random(32)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        Shipment::create(['order_id' => $order->id, 'status' => 'shipped', 'carrier' => "Carrier{$tag}", 'tracking_number' => "TRK{$tag}"]);
        DB::table('customers')->insert(['store_id' => $store->id, 'account_id' => $store->fresh()->account_id, 'name' => "Guest {$tag}", 'phone' => '0511']);
        $driver = Driver::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => "Driver {$tag}", 'token' => hash('sha256', Str::random(40)), 'active' => true]);
        $hook = WebhookEndpoint::create(['user_id' => $owner->id, 'url' => "https://93.184.216.34/{$tag}", 'events' => ['order.created'], 'secret' => Str::random(32), 'active' => true]);

        return ['store' => $store->id, 'category' => $cat->id, 'item' => $item->id, 'order' => $order->id, 'brand' => $brand->id,
            'variant' => $variant->id, 'fitment' => $fitment->id, 'driver' => $driver->id, 'webhook' => $hook->id];
    }

    private function token(User $owner, array $abilities = ['read', 'write']): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);

        return $raw;
    }

    /** @return list<RoutingRoute> every owner-token route (ApiTokenAuth), app-token routes excluded */
    private function ownerRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), function (RoutingRoute $r) {
            $name = (string) $r->getName();

            return str_starts_with($name, 'api.v1.') && ! str_starts_with($name, 'api.v1.app.')
                && collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, ApiTokenAuth::class));
        }));
    }

    /** A rows snapshot of one world (every resource table it owns). */
    private function snapshot(array $w): string
    {
        $rows = [];
        foreach (['stores' => $w['store'], 'categories' => $w['category'], 'items' => $w['item'], 'orders' => $w['order'], 'brands' => $w['brand'],
            'menu_variants' => $w['variant'], 'vehicle_fitments' => $w['fitment'], 'drivers' => $w['driver'], 'webhook_endpoints' => $w['webhook']] as $table => $id) {
            $rows[$table] = (array) DB::table($table)->where('id', $id)->first();
        }
        foreach (['order_items' => 'order_id', 'shipments' => 'order_id'] as $table => $col) {
            $rows[$table] = DB::table($table)->where($col, $w['order'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $rows['customers'] = DB::table('customers')->where('store_id', $w['store'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $rows['counts'] = ['items' => DB::table('items')->where('store_id', $w['store'])->count(), 'categories' => DB::table('categories')->where('store_id', $w['store'])->count(),
            'orders' => DB::table('orders')->where('store_id', $w['store'])->count(), 'menu_variants' => DB::table('menu_variants')->where('store_id', $w['store'])->count()];

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, int> $ids */
    private function url(RoutingRoute $route, array $ids): ?string
    {
        $name = (string) $route->getName();
        $params = [];
        foreach ($route->parameterNames() as $p) {
            $key = $p === 'id' ? (collect(self::ID_IS)->first(fn ($k, $prefix) => str_starts_with($name, $prefix)) ?? 'store') : (self::PARAMS[$p] ?? null);
            $this->assertNotNull($key, "Route {$name} has a path parameter {{$p}} this census has no fixture for — add it (and check the controller scopes it).");
            $params[$p] = $ids[$key];
        }

        return $params === [] ? null : route($name, $params);
    }

    /** @param array<string, int> $victim */
    private function payload(array $victim): array
    {
        return ['name' => 'pwned', 'price' => 1, 'status' => 'completed', 'delivery_status' => 'delivered', 'driver_id' => $victim['driver'],
            'category_id' => $victim['category'], 'sold_out' => true, 'active' => 0, 'sku' => 'PWN', 'stock_qty' => 0,
            'type' => 'delivery', 'customer_name' => 'x', 'phone_number' => '0500', 'address' => 'x',
            'items' => [['item_id' => $victim['item'], 'quantity' => 1]], 'url' => 'https://93.184.216.34/p', 'events' => ['order.created']];
    }

    public function test_no_owner_token_route_reaches_another_account_or_another_store(): void
    {
        $alice = $this->owner();
        $a1 = $this->world($alice, 'A1');
        $a2 = $this->world($alice, 'A2'); // a second store of the same account
        $bob = $this->owner();
        $b = $this->world($bob, 'B1');
        $token = $this->token($alice);
        $before = [$this->snapshot($a2), $this->snapshot($b)];

        $routes = $this->ownerRoutes();
        $this->assertGreaterThan(30, count($routes), 'the owner API route census found too few routes');
        $probed = 0;
        foreach ($routes as $route) {
            $name = (string) $route->getName();
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $storeScoped = in_array('id', $route->parameterNames(), true) && ! collect(self::ID_IS)->keys()->contains(fn ($p) => str_starts_with($name, $p));
            $hasChild = count(array_diff($route->parameterNames(), ['id'])) > 0;

            // control: the same read with the caller's own ids answers 200, so the refusals below are not vacuous
            if ($method === 'GET' && ($own = $this->url($route, $a1))) {
                cache()->flush();
                $ok = $this->withToken($token)->getJson($own);
                $this->assertSame(200, $ok->getStatusCode(), "{$name} with the caller's own ids answered {$ok->getStatusCode()}: ".Str::limit((string) $ok->getContent(), 300));
            }

            $attacks = [];
            if ($url = $this->url($route, $b)) {
                $attacks['every id of another account'] = [$url, $b];
            }
            if ($storeScoped && $hasChild) {
                $attacks['own store, children of another account'] = [$this->url($route, array_merge($b, ['store' => $a1['store']])), $b];
                $attacks['own store, children of the account\'s other store'] = [$this->url($route, array_merge($a2, ['store' => $a1['store']])), $a2];
            }
            foreach ($attacks as $what => [$target, $victim]) {
                cache()->flush(); // the write limiters would answer 429 long before the census ends
                $res = $this->withToken($token)->json($method, $target, $method === 'GET' ? [] : $this->payload($victim));
                $this->assertContains($res->getStatusCode(), array_filter([403, 404, self::IDEMPOTENT[$name] ?? null]), "{$method} {$name} ({$what}) answered {$res->getStatusCode()}: ".Str::limit((string) $res->getContent(), 300));
                foreach (['B1', 'A2'] as $tag) {
                    if ($victim === ($tag === 'B1' ? $b : $a2)) {
                        $this->assertStringNotContainsString("Item {$tag}", (string) $res->getContent(), "{$name} ({$what}) leaked the victim's data");
                        $this->assertStringNotContainsString("Buyer {$tag}", (string) $res->getContent(), "{$name} ({$what}) leaked the victim's data");
                    }
                }
                $probed++;
            }
            $this->app['auth']->forgetGuards();
        }
        $this->assertGreaterThan(40, $probed);
        $this->assertSame($before[0], $this->snapshot($a2), "a request through store A1's routes changed the account's other store");
        $this->assertSame($before[1], $this->snapshot($b), "an owner-token request changed another account's data");

        // the collection routes list only the caller's own resources
        foreach (['api.v1.stores.index', 'api.v1.drivers.index', 'api.v1.webhooks.index', 'api.v1.me'] as $list) {
            $body = (string) $this->withToken($token)->getJson(route($list))->assertOk()->getContent();
            foreach (['Store B1', 'Driver B1', '/B1'] as $marker) {
                $this->assertStringNotContainsString($marker, $body, "{$list} lists another account's {$marker}");
            }
        }
    }

    public function test_a_token_made_in_the_dashboard_works_and_is_refused_right_after_revoking(): void
    {
        $alice = $this->owner();
        $w = $this->world($alice, 'A1');
        $res = $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api.token.create'), ['name' => 'Zapier', 'abilities' => ['menu:read']])->assertRedirectContains('new_token=');
        preg_match('#<code class="ui-api__secret">([^<]+)</code>#', (string) $this->get((string) $res->headers->get('Location'))->getContent(), $m);
        $raw = $m[1] ?? '';
        $this->assertSame(40, strlen($raw));
        $row = ApiToken::where('user_id', $alice->id)->sole();
        $this->assertNotSame($raw, $row->token, 'the token is stored hashed');
        $this->app['auth']->forgetGuards();

        $this->withToken($raw)->getJson(route('api.v1.stores.menu', $w['store']))->assertOk();
        $this->withToken($raw)->getJson(route('api.v1.stores.orders', $w['store']))->assertForbidden(); // scope
        $this->withToken($raw)->postJson(route('api.v1.menu.items.store', $w['store']), ['name' => 'x', 'price' => 1])->assertForbidden();

        // another owner cannot revoke it
        $bob = $this->owner();
        $this->actingAs($bob)->delete(route('api.token.delete', $row->id))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($raw)->getJson(route('api.v1.stores.menu', $w['store']))->assertOk();

        $this->actingAs($alice)->delete(route('api.token.delete', $row->id))->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->withToken($raw)->getJson(route('api.v1.stores.menu', $w['store']))->assertUnauthorized();
        $this->withToken($raw)->postJson(url('/mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $alice = $this->owner();
        $w = $this->world($alice, 'A1');
        $raw = $this->token($alice);
        ApiToken::where('user_id', $alice->id)->update(['expires_at' => now()->subMinute()]);
        $this->withToken($raw)->getJson(route('api.v1.stores.show', $w['store']))->assertUnauthorized();
    }

    public function test_a_suspended_or_closed_owner_s_token_is_refused(): void
    {
        $alice = $this->owner();
        $w = $this->world($alice, 'A1');
        $raw = $this->token($alice);
        $this->withToken($raw)->getJson(route('api.v1.stores.show', $w['store']))->assertOk();

        $alice->forceFill(['status' => 0])->save(); // suspended by the platform admin (sign-in is refused too)
        $this->withToken($raw)->getJson(route('api.v1.stores.show', $w['store']))->assertForbidden()->assertJsonPath('code', 'account_blocked');
        $this->withToken($raw)->getJson(route('api.v1.me'))->assertForbidden();
        $this->withToken($raw)->postJson(url('/mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();

        $alice->forceFill(['status' => 1])->save(); // reinstated: the same token works again
        $this->withToken($raw)->getJson(route('api.v1.stores.show', $w['store']))->assertOk();
    }
}
