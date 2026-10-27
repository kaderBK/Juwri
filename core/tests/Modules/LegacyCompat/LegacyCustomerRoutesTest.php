<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyCustomerRoutes;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use Juwri\Modules\Payments\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J52b M3: the old signed-in customer paths `/restaurant/{id}/…`. Today core still answers every one of them, so the
 * module adds nothing (B2: never shadow core). The J52 rename moves them to new paths; {@see simulateCoreRename()}
 * moves the core routes to `store/{id}/…` the way that batch will, then the module adds the legacy paths back:
 * GETs 301 to the new path (query kept), POSTs and the wallet JWT GETs are served by a copy of the core route with
 * the same middleware (CSRF, auth:customer, throttles) and the same handler (its store/tenant checks).
 */
class LegacyCustomerRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    private Customer $attacker;

    private Customer $victim;

    private CustomerNotification $victimNote;

    private Order $victimOrder;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_loyalty' => true, 'settings.admin_allow_customer_wallet' => true]);
    }

    // ---- module off / core still serving --------------------------------------------------------------------------

    public function test_nothing_is_added_when_the_module_is_off(): void
    {
        $this->simulateCoreRename();

        $this->assertSame([], LegacyCompatRoutes::registerCustomer());
        $this->assertSame([], LegacyCustomerRoutes::register(app(Router::class)));
        foreach (LegacyCustomerRoutes::MAP as [$method, $uri]) {
            $this->assertNull($this->matchedName($method, $this->path($uri)), $method.' '.$uri.' answered while off');
        }
    }

    public function test_nothing_is_added_while_core_still_serves_the_legacy_paths(): void
    {
        config(['legacy_compat.enabled' => true]);

        $this->assertSame([], LegacyCompatRoutes::registerCustomer());
        foreach (LegacyCustomerRoutes::MAP as [$method, $uri, $coreName]) {
            $this->assertSame($coreName, $this->matchedName($method, $this->path($uri)), $method.' '.$uri);
        }
    }

    public function test_no_legacy_route_without_a_core_handler(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename(drop: ['publicView.loyalty.redeem', 'publicView.wallet.balance']);

        $added = LegacyCompatRoutes::registerCustomer();

        $this->assertCount(count(LegacyCustomerRoutes::MAP) - 2, $added);
        $this->assertNull($this->matchedName('POST', '/restaurant/7/loyalty/redeem'));
        $this->assertNull($this->matchedName('GET', '/restaurant/7/wallet/balance'));
    }

    // ---- after the rename -----------------------------------------------------------------------------------------

    public function test_after_the_rename_every_legacy_path_is_routed_by_the_module(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();

        $added = LegacyCompatRoutes::registerCustomer();
        $this->assertCount(count(LegacyCustomerRoutes::MAP), $added);

        foreach (LegacyCustomerRoutes::MAP as [$method, $uri, $coreName]) {
            $this->assertSame(LegacyCustomerRoutes::routeName($method, $coreName), $this->matchedName($method, $this->path($uri)), $method.' '.$uri);
            // The new path still reaches core.
            $this->assertSame($coreName, $this->matchedName($method, $this->path(Str::replaceFirst('restaurant/', 'store/', $uri))), $coreName);
        }

        // Non-numeric ids never reach a handler.
        $this->assertNull($this->matchedName('GET', '/restaurant/abc/wallet/balance'));
        $this->assertNull($this->matchedName('POST', '/restaurant/1/orders/x/refund-request'));
    }

    public function test_served_routes_run_the_core_action_with_the_same_middleware(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();
        LegacyCompatRoutes::registerCustomer();
        $router = app(Router::class);
        $routes = Route::getRoutes();

        foreach (LegacyCustomerRoutes::MAP as [$method, $uri, $coreName, $mode]) {
            if ($mode !== LegacyCustomerRoutes::SERVE) {
                continue;
            }
            $core = $routes->getByName($coreName);
            $legacy = $routes->getByName(LegacyCustomerRoutes::routeName($method, $coreName));
            $this->assertInstanceOf(RoutingRoute::class, $core);
            $this->assertInstanceOf(RoutingRoute::class, $legacy);

            $this->assertSame($core->getActionName(), $legacy->getActionName(), $coreName);
            $this->assertSame($core->gatherMiddleware(), $legacy->gatherMiddleware(), $coreName);
            $resolved = $router->gatherRouteMiddleware($legacy);
            $this->assertSame($router->gatherRouteMiddleware($core), $resolved, $coreName);
            $this->assertContains(PreventRequestForgery::class, $resolved, $coreName.' lost CSRF');
            $this->assertContains('auth:customer', $legacy->gatherMiddleware(), $coreName.' lost auth:customer');
            $this->assertNotEmpty(array_filter($legacy->gatherMiddleware(), static fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:')), $coreName.' lost its throttle');
        }
    }

    public function test_get_paths_301_to_the_new_path_with_the_query_kept(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();
        LegacyCompatRoutes::registerCustomer();
        $this->withoutMiddleware([Installed::class]);

        foreach (LegacyCustomerRoutes::MAP as [$method, $uri, , $mode]) {
            if ($mode !== LegacyCustomerRoutes::REDIRECT) {
                continue;
            }
            $response = $this->get($this->path($uri).'?src=print&page=2');
            $response->assertStatus(301);
            $this->assertSame(url($this->path(Str::replaceFirst('restaurant/', 'store/', $uri))).'?src=print&page=2', $response->headers->get('Location'), $uri);
            $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_redirect_routes_404_once_the_module_is_switched_off(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();
        LegacyCompatRoutes::registerCustomer();
        $this->withoutMiddleware([Installed::class]);

        config(['legacy_compat.enabled' => false]); // a cached route table still lists them
        $this->get('/restaurant/7/wallet/balance')->assertNotFound();
        $this->get('/restaurant/7/my-orders')->assertNotFound();
    }

    public function test_served_routes_still_require_a_signed_in_customer(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();
        LegacyCompatRoutes::registerCustomer();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seedCustomerStores();

        $id = $this->storeB->id;
        $this->postJson('/restaurant/'.$id.'/loyalty/redeem')->assertUnauthorized();
        $this->getJson('/restaurant/'.$id.'/google-wallet/loyalty-jwt')->assertUnauthorized();
        $this->postJson('/restaurant/'.$id.'/wallet/topup', ['amount' => 10])->assertUnauthorized();
        $this->postJson('/restaurant/'.$id.'/notifications/read')->assertUnauthorized();
        $this->postJson('/restaurant/'.$id.'/orders/'.$this->victimOrder->id.'/refund-request')->assertUnauthorized();
        $this->assertSame(50, $this->victimPoints());
    }

    public function test_served_routes_keep_the_cross_store_checks(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();
        LegacyCompatRoutes::registerCustomer();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seedCustomerStores();
        $before = Transaction::count();
        $b = $this->storeB->id;

        $as = $this->actingAs($this->attacker, 'customer');
        $as->postJson('/restaurant/'.$b.'/loyalty/redeem')->assertNotFound();
        $as->getJson('/restaurant/'.$b.'/google-wallet/loyalty-jwt')->assertNotFound()->assertJsonMissingPath('save_url');
        $as->post('/restaurant/'.$b.'/wallet/topup', ['amount' => 10, 'payment_method' => 'stripe'])->assertNotFound();
        $as->postJson('/restaurant/'.$b.'/orders/'.$this->victimOrder->id.'/refund-request', ['reason' => 'x'])->assertNotFound();
        $as->postJson('/restaurant/'.$b.'/notifications/read', ['ids' => [$this->victimNote->id]])->assertOk();

        $this->assertSame(50, $this->victimPoints());
        $this->assertSame(0, LoyaltyAccount::where('store_id', $b)->where('customer_id', $this->attacker->id)->count());
        $this->assertSame($before, Transaction::count(), 'no top-up transaction opened in store B');
        $this->assertEqualsWithDelta(40.0, CustomerWalletService::balanceFor($this->victim->fresh()), 0.000001);
        $this->assertSame('completed', $this->victimOrder->fresh()->status);
        $this->assertNull($this->victimNote->fresh()->read_at, "the victim's notification stays unread");
    }

    public function test_served_routes_work_for_the_stores_own_customer(): void
    {
        config(['legacy_compat.enabled' => true]);
        $this->simulateCoreRename();
        LegacyCompatRoutes::registerCustomer();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seedCustomerStores();
        $b = $this->storeB->id;

        $as = $this->actingAs($this->victim, 'customer');
        $as->postJson('/restaurant/'.$b.'/notifications/read', ['ids' => [$this->victimNote->id]])->assertOk();
        $this->assertNotNull($this->victimNote->fresh()->read_at);

        $as->postJson('/restaurant/'.$b.'/loyalty/redeem')->assertSuccessful();
        $this->assertLessThan(50, $this->victimPoints());
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /**
     * Move the core customer routes from `restaurant/{storeId}/…` to `store/{storeId}/…` (what the J52 rename batch
     * does), keeping their names, actions and middleware. Names in $drop are removed instead.
     *
     * @param  list<string>  $drop
     */
    private function simulateCoreRename(array $drop = []): void
    {
        $router = app(Router::class);
        $old = $router->getRoutes();
        $old->refreshNameLookups();
        $coreNames = array_unique(array_column(LegacyCustomerRoutes::MAP, 2));

        $fresh = new RouteCollection;
        foreach ($old->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (in_array($name, $drop, true)) {
                continue;
            }
            if (in_array($name, $coreNames, true)) {
                $this->assertStringStartsWith('restaurant/{storeId}/', $route->uri(), $name);
                $route->setUri(Str::replaceFirst('restaurant/', 'store/', $route->uri()));
            }
            $fresh->add($route);
        }
        $router->setRoutes($fresh);
        $fresh->refreshNameLookups();
    }

    /** A concrete request path for a route uri (every parameter = 7). */
    private function path(string $uri): string
    {
        return '/'.preg_replace('/\{\w+\??\}/', '7', $uri);
    }

    private function matchedName(string $method, string $path): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create($path, $method))->getName();
        } catch (HttpException) {
            return null;
        }
    }

    private function seedCustomerStores(): void
    {
        $this->storeA = $this->store();
        $this->storeB = $this->store();
        $this->attacker = Customer::create(['store_id' => $this->storeA->id, 'name' => 'Mallory', 'phone' => '55-'.random_int(1000, 9999)]);
        $this->victim = Customer::create(['store_id' => $this->storeB->id, 'name' => 'Victim', 'phone' => '66-'.random_int(1000, 9999)]);

        LoyaltyAccount::forCustomer($this->victim->id, $this->storeB->id)->earn(990001, 50, 'seed');
        CustomerWalletService::credit($this->victim, 40, 'seed', 'seed:'.$this->victim->id);

        $order = new Order;
        $order->forceFill([
            'store_id' => $this->storeB->id, 'customer_id' => $this->victim->id, 'type' => 'takeaway',
            'status' => 'completed', 'is_paid' => 1, 'source' => 'qr', 'track_token' => Str::random(32), 'created_at' => now(),
        ])->save();
        $this->victimOrder = $order;
        $this->victimNote = CustomerNotification::create([
            'customer_id' => $this->victim->id, 'store_id' => $this->storeB->id, 'order_id' => $order->id,
            'type' => 'order_status', 'title' => 'VICTIM-MARKER', 'body' => 'secret',
        ]);

        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'lc-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1, 'reward_threshold' => 5,
            'reward_description' => 'Free side', 'reward_discount' => 5, 'active' => true,
        ]);
        EntitlementWriter::grant($owner, 'loyalty', 'admin', null, '1', null, 'test');

        return $store;
    }

    private function victimPoints(): int
    {
        return (int) LoyaltyAccount::forCustomer($this->victim->id, $this->storeB->id)->fresh()->balance;
    }
}
