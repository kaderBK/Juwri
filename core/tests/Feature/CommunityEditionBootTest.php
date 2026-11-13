<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\AdminDashboardService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Content\PlatformSite;
use Juwri\Core\Contracts\Editions\Edition;
use Juwri\Core\Contracts\Marketplace\ShopperBag;
use Juwri\Core\Database\Seeders\CuisineSeeder;
use Juwri\Core\Editions\Community\NoShopperBag;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\PassThrough;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Providers\CoreServiceProvider;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleMap;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Accounting\Providers\AccountingServiceProvider;
use Juwri\Modules\Channels\Providers\ChannelsServiceProvider;
use Juwri\Modules\Hotel\Providers\HotelServiceProvider;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Laundry\Providers\LaundryServiceProvider;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Contracts\TransactionFulfiller;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\StoreTransactionFulfiller;
use Juwri\Modules\Pharmacy\Providers\PharmacyServiceProvider;
use Tests\CommunityTestCase;
use Tests\Support\PharmacyMarket;

/**
 * P1-13 13c-R (owner decision A): Hotel, Pharmacy, Laundry, Accounting and Channels are Community modules — without a
 * licence they register their providers, routes and commands, their owner pages and the storefronts of every vertical
 * (incl. the hotel room QR with the in-room guest layer, laundry booking, the pharmacy OTC notice) work, and the nav,
 * the integrations hub and the 86 toggle reach them. (13c had moved them to ee; nothing was released in between.)
 * 13e: the same for the Platform module's ee part (platform announcements, the merchant support desk); the instance
 * admin a single business needs (plugins, alerts, audit trail, abuse guard, backups, ops commands) stays.
 * 13f: the same for Reseller (white-label partners): no portal, partner pages, admin reseller screens or reseller API;
 * the marketing home page, the App Store and the admin dashboard render without them; reseller payments are not settled.
 * 13g: the same for Billing (platform plans, subscriptions, add-ons, checkout, invoices, upsell) and the platform's SaaS
 * analytics; the admin payments ledger stays, and a business's own customer payments still settle (Payments).
 * 13d: the same for Marketplace (Fusion + business directory): no shopper guard, no /fusion or directory routes, the
 * owner store pages hide the marketplace and directory entries, the App Store hides "Order food", and the Community
 * cross-store cart (/order-hub) still adds and checks out without marketplace shoppers.
 *
 * CommunityTestCase empties the process-wide registries first, so a licensed test earlier in the same worker cannot
 * leak ee routes/hooks into this one.
 */
class CommunityEditionBootTest extends CommunityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, TrackOwnerScreenView::class]);
        $this->shareLayoutGlobals();
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    public function test_no_ee_provider_route_or_command_is_registered(): void
    {
        $this->assertTrue(app(Edition::class)->isCommunity());

        // P1-13 13i: edition code = the module folders of the installed edition packages (none in the Community mirror,
        // where this test proves the plain boot); none of their providers, routes or commands may register unlicensed.
        $isEditionClass = $this->editionClassCheck();
        $this->assertSame([], array_values(array_filter(array_keys(app()->getLoadedProviders()), $isEditionClass)),
            'edition module providers must not register without a licence');

        $eeRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if ($isEditionClass(ltrim($route->getActionName(), '\\'))) {
                $eeRoutes[] = $route->uri();
            }
        }
        $this->assertSame([], $eeRoutes, 'ee routes registered in Community');
        // 13c-R: the five specialised modules are Community — provider, routes and commands without a licence.
        foreach ([HotelServiceProvider::class, PharmacyServiceProvider::class, LaundryServiceProvider::class,
            AccountingServiceProvider::class, ChannelsServiceProvider::class] as $provider) {
            $this->assertNotNull(app()->getProvider($provider), "$provider must register without a licence");
        }
        foreach (['stores.aggregators.index', 'stores.posbridge.index', 'stores.zomato.index', 'hungerstation.page',
            'integrations.deliverect.index', 'webhook.hungerstation', 'webhook.delivery.inbound', 'hotel.guest.info',
            'hotel.rooms.book', 'hotel.rooms', 'hotel.pms.bookings', 'hotel.folio.statement', 'pharmacy.compliance.index',
            'laundry.book.place', 'laundry.book.addLine', 'stores.service_board.index', 'stores.accounting.index'] as $name) {
            $this->assertTrue(Route::has($name), "Community route $name must be registered");
        }
        foreach ([
            // 13d Marketplace: the Fusion shell + assistant, the owner opt-in + directory tab, the two admin consoles.
            'mk.home', 'mk.store', 'mk.assistant.message', 'stores.marketplaceSettings', 'stores.directory.index',
            'admin.fusion.geo', 'admin.directory.overview',
            // 13e Platform: platform announcements, the merchant → platform support desk (owner + admin side).
            'admin.announcements.index', 'admin.announcements.toggle', 'admin.support.index', 'support.index', 'support.thread'] as $name) {
            $this->assertFalse(Route::has($name), "route $name must be absent in Community");
        }
        // 13e: the instance admin a single business needs stays (Community).
        foreach (['admin.plugins.index', 'admin.alerts.index', 'admin.activity.index', 'admin.abuse-guard.index',
            'admin.security.accounts', 'admin.ownerMobileUsage', 'admin.stores.verified.toggle', 'activity-log.index',
            'dash.clientError', 'admin.backup-health.index'] as $name) {
            $this->assertTrue(Route::has($name), "Community route $name must stay");
        }
        foreach (['platform:db-restore-check', 'platform:supply-chain-audit', 'secrets:encrypt'] as $command) {
            $this->assertArrayHasKey($command, Artisan::all(), "Community command $command must stay");
        }

        $eeCommands = array_keys(array_filter(Artisan::all(), fn ($command) => $isEditionClass($command::class)));
        $this->assertSame([], $eeCommands, 'ee commands registered in Community');
        $this->assertArrayHasKey('aggregator:validate-payload', Artisan::all()); // 13c-R: Channels is Community
        $this->assertArrayNotHasKey('dir:import', Artisan::all());
        // 13d: no shopper guard and no /mk redirect; the shopper help pillar (Content) stays.
        $this->assertNull(config('auth.guards.shopper'));
        $this->assertInstanceOf(NoShopperBag::class, app(ShopperBag::class));
        $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === 'mk'));
        $this->assertTrue(Route::has('mk.help.index'));
        // The legacy cart-recovery command and the WhatsApp team inbox stay Community.
        $this->assertArrayHasKey('whatsapp:cart-recovery', Artisan::all());
        $this->assertTrue(Route::has('stores.whatsappInbox'));
    }

    public function test_owner_dashboard_renders_and_the_nav_offers_the_channels_module(): void
    {
        // The marketplace keys are on and plan-gated, and this owner's plan lacks them: Channels is installed in
        // Community (13c-R), so the nav shows them as locked upsell rows, exactly as with a licence.
        foreach (['aggregator_hub', 'aggregator_zomato', 'aggregator_swiggy'] as $key) {
            config(['settings.admin_allow_'.$key => 1]);
            Feature::updateOrCreate(['key' => $key], ['label' => $key, 'plan_gated' => true]);
        }
        app()->forgetInstance('features.plan_gated');
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        [$owner, $store] = $this->ownerWithStore('restaurant');
        // Item 86 toggle: Channels fills the marketplaces' hook slot in Community (India sync, then the other channels).
        $this->assertTrue(Hooks::has('menu.item-availability-changed'));
        $this->assertTrue(Hooks::has('order.paid.pos-push'), 'a paid order reaches the connected POS (Channels)');
        $item = $this->item($store);
        $item->update(['is_sold_out' => true]);
        $this->assertTrue((bool) $item->fresh()->is_sold_out);

        $html = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        foreach (['HungerStation', 'Zomato', 'Swiggy'] as $label) {
            $this->assertStringContainsString($label, $html, "the Community nav offers $label");
        }
    }

    public function test_integrations_hub_renders_with_the_deliverect_card(): void
    {
        config(['settings.admin_allow_integrations' => true]);
        FeatureAccess::flush();
        [$owner] = $this->ownerWithStore('restaurant');

        $res = $this->actingAs($owner)->get(route('integrations.index'))->assertOk();
        $res->assertSee('Zapier')->assertSeeHtml('data-integration="deliverect"');
    }

    public function test_the_five_specialised_modules_owner_pages_render_without_a_licence(): void
    {
        // The admin feature switches of these pages are on (feature gating is unchanged by the edition).
        config(['settings.admin_allow_integrations' => true, 'settings.admin_allow_accounting_sync' => 1, 'settings.admin_allow_aggregator_hub' => 1,
            'settings.admin_allow_pos_bridge' => 1, 'settings.admin_allow_aggregator_deliverect' => 1]);
        FeatureAccess::flush();
        foreach ([
            'hotel' => ['hotel.rooms', 'hotel.pms.bookings'],
            'pharmacy' => ['pharmacy.compliance.index'],
            'laundry' => ['stores.service_board.index'],
            'restaurant' => ['stores.accounting.index', 'stores.aggregators.index', 'stores.posbridge.index', 'integrations.deliverect.index'],
        ] as $type => $names) {
            [$owner, $store] = $this->ownerWithStore($type);
            StoreOption::updateStoreOption($store->id, 'hotel_mode', $type === 'hotel' ? '1' : '0');
            foreach ($names as $name) {
                $url = $name === 'pharmacy.compliance.index' ? route($name, $store->id) : route($name).'?store_id='.$store->id;
                $res = $this->actingAs($owner)->withSession(['restaurant_id' => $store->id])->get($url);
                $this->assertSame(200, $res->status(), "$name -> ".$res->headers->get('Location'));
            }
        }
    }

    public function test_storefronts_of_every_vertical_render(): void
    {
        foreach (['restaurant' => 'crave', 'retail' => 'shelf', 'pharmacy' => 'shelf', 'laundry' => 'bookla', 'hotel' => 'aurelia'] as $type => $template) {
            [, $store] = $this->ownerWithStore($type, $template);
            $item = $this->item($store);
            if ($type === 'pharmacy') {
                PharmacyMarket::open($store); // P1-23: published only in an enabled market with an approved licence
            }

            $this->get(route('publicView', $store->slug))->assertOk()->assertSee($item->name);
        }

        // Both laundry themes post to the Laundry module's booking endpoint (Community).
        foreach (['bookla', 'freshly'] as $template) {
            [, $laundry] = $this->ownerWithStore('laundry', $template);
            $this->item($laundry);
            $this->get(route('publicView', $laundry->slug))->assertOk()
                ->assertSee('var BOOK_ROUTE = '.json_encode(route('laundry.book.place', ['slug' => $laundry->slug])), false);
        }
        // The pharmacy storefront shows the Pharmacy module's OTC notice.
        [, $pharmacy] = $this->ownerWithStore('pharmacy', 'shelf');
        $this->item($pharmacy);
        PharmacyMarket::open($pharmacy);
        $this->get(route('publicView', $pharmacy->slug))->assertOk()->assertSee('over-the-counter (OTC)', false);
    }

    public function test_hotel_room_qr_opens_the_storefront(): void
    {
        [, $store] = $this->ownerWithStore('hotel', 'crave'); // a theme with the shared guest layer
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_on_table_order', '1');
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '1');
        $this->item($store);
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Suite 204',
        ]);

        // 13c-R: the Hotel module is Community — the room QR carries its signed room token (store flag on) and, with a
        // guest checked in, the room page shows the in-room guest layer (service requests), without a licence.
        $url = store_public_qr_url($store, $room->key);
        $this->assertStringContainsString('rt='.HotelRoomToken::mint($room), $url, 'the Hotel module mints the room token');
        HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        // The scan counts once and redirects to the clean URL (qr-id dropped, the room key kept).
        $this->get($url)->assertRedirect();
        $this->get(route('publicView', $store->slug).'?table='.$room->key)->assertOk()
            ->assertSee('id="mbHotelSvcForm"', false)->assertSee(route('hotel.guest.request', $store->slug), false);
    }

    public function test_owner_store_pages_hide_the_marketplace_and_the_directory(): void
    {
        // The directory switches are on and cuisines exist — without the Marketplace module there is still no
        // directory (Community Directory: off), so no Directory nav, no Marketplace chip and no cuisine picker.
        config(['settings.admin_allow_directory' => 1, 'settings.dir_enabled' => '1']);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        Cuisine::create(['name' => 'Pizza', 'slug' => 'pizza', 'active' => true, 'position' => 1]);
        [$owner, $store] = $this->ownerWithStore('restaurant');

        $dashboard = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/directory', $dashboard);
        $index = (string) $this->actingAs($owner)->get(route('stores.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('marketplace-settings', $index);
        $this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()
            ->assertDontSee('name="cuisines[]"', false);
    }

    public function test_app_store_hides_the_fusion_link(): void
    {
        $html = (string) $this->get(route('apps.store.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('href="'.url('/fusion').'"', $html, 'no "Order food" link to a missing marketplace');
    }

    public function test_cross_store_cart_adds_and_checks_out_without_marketplace_shoppers(): void
    {
        // The Community cross-store cart used to read the Marketplace's shopper guard directly (auth('shopper')),
        // which does not exist without the module ("Auth guard [shopper] is not defined").
        config(['settings.admin_allow_multivendor_cart' => 1]);
        Feature::updateOrCreate(['key' => 'multivendor_cart'], ['label' => 'multivendor_cart', 'plan_gated' => false]);
        app()->forgetInstance('features.plan_gated');
        FeatureRegistry::clearCache();
        [, $store] = $this->ownerWithStore('restaurant');
        $item = $this->item($store);

        $this->postJson(route('orderhub.add'), ['store_id' => $store->id, 'item_id' => $item->id, 'quantity' => 2])
            ->assertOk()->assertJsonPath('ok', true);
        $this->post(route('orderhub.placeOrder'), ['name' => 'Guest', 'phone' => '0500000000'])->assertOk();

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order, 'the cross-store checkout placed the store order');
    }

    public function test_migrations_without_ee_create_the_tables_community_code_reads(): void
    {
        // A Community install without the Enterprise package runs every migration folder but the package ones. The tables Community
        // code reads (cuisines + the store link, fleet dispatch offers, the shopper ↔ customer link) used to be created
        // by the ee Marketplace migration only.
        config(['database.connections.community_only' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        // P1-13 13i: the edition packages' module folders (none in the Community mirror, where every folder runs).
        $packageDirs = array_map(fn (array $folder) => ModuleMap::dir($folder['path']).DIRECTORY_SEPARATOR, ModuleMap::packageFolders());
        $paths = array_values(array_filter(CoreServiceProvider::migrationPaths(), function (string $path) use ($packageDirs): bool {
            foreach ($packageDirs as $dir) {
                if (str_starts_with($path, $dir)) {
                    return false;
                }
            }

            return true;
        }));
        if ($packageDirs !== []) {
            $this->assertNotSame(count($paths), count(CoreServiceProvider::migrationPaths()), 'the edition migration folders are left out');
        }
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'community_only', '--path' => [database_path('migrations'), ...$paths], '--realpath' => true, '--force' => true,
        ]));

        $schema = Schema::connection('community_only');
        // 13g: the payments ledger, the feature catalogue + grants/overrides, the usage counters and the legacy plan
        // memberships came with the (ee) Billing migration.
        foreach (['cuisines', 'cuisine_store', 'marketplace_dispatch_offers', 'shopper_customers', 'transaction',
            'billing_receipts', 'features', 'feature_grants', 'entitlement_overrides', 'entitlement_audit_logs',
            'tenant_usage_daily', 'account_usage', 'upgrades'] as $table) {
            $this->assertTrue($schema->hasTable($table), "$table is created without ee");
        }
        foreach (['shoppers', 'directory_listings', 'cuisine_suggestions', 'shopper_wallet_ledger_entries'] as $table) {
            $this->assertFalse($schema->hasTable($table), "$table stays the Marketplace module's");
        }
        foreach (['plans', 'subscriptions', 'subscription_events', 'account_addons', 'addon_products', 'plan_prices'] as $table) {
            $this->assertFalse($schema->hasTable($table), "$table stays the Billing module's");
        }
        $this->assertGreaterThan(0, DB::connection('community_only')->table('features')->count(), 'Core ships the feature catalogue');

        // The taxonomy's reference data is seeded by Core (DatabaseSeeder's default seeders), not by the Marketplace.
        $this->assertContains(CuisineSeeder::class, ModuleSeeders::ordered('default'));

        // The app on that database: the store edit page renders, the storefront's structured data names its cuisine,
        // the Community code paths that query the moved tables run.
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('community_only');
        try {
            Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
            [$owner, $store] = $this->ownerWithStore('restaurant');
            $cuisine = Cuisine::factory()->create(['name' => 'Pizza', 'slug' => 'pizza']);
            $store->cuisines()->sync([$cuisine->id]);
            $this->item($store); // the menu page emits the Restaurant JSON-LD
            $this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk();
            $this->get(route('publicView', $store->slug))->assertOk()->assertSee('"servesCuisine"', false)->assertSee('Pizza');
            $this->assertSame(0, MkDispatchOffer::where('store_id', $store->id)->count());
            $this->assertSame(0, DB::table('shopper_customers')->where('store_id', $store->id)->count());
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    public function test_the_community_table_migrations_are_idempotent_on_an_install_that_has_the_tables(): void
    {
        // An install upgraded from the ee Marketplace migration that created these tables: re-running is a no-op.
        foreach (['app/Core/Database/Migrations/2026_10_01_100600_create_cuisine_tables.php',
            'app/Modules/Delivery/Database/Migrations/2026_10_01_117100_create_marketplace_dispatch_offers_table.php',
            'app/Modules/Customers/Database/Migrations/2026_10_01_108100_create_shopper_customers_table.php',
            'app/Core/Database/Migrations/2026_10_01_111800_create_entitlement_and_usage_tables.php',
            'app/Modules/Payments/Database/Migrations/2026_10_01_111900_create_payment_ledger_tables.php',
            'app/Core/Database/Migrations/2026_10_01_811900_insert_feature_reference_data.php'] as $file) {
            (require base_path($file))->up();
        }
        $this->assertTrue(Schema::hasTable('cuisine_store'));
        Cuisine::factory()->create(['slug' => 'idempotent']);
        $this->assertSame(1, Cuisine::where('slug', 'idempotent')->count());
        $this->assertSame(DB::table('features')->count(), DB::table('features')->distinct()->count('key'), 'the feature catalogue is not inserted twice');
    }

    public function test_order_hub_pages_redirect_to_working_pages_without_the_marketplace(): void
    {
        // They used to 301 to /fusion (the Marketplace module), a 404 in Community.
        [, $store] = $this->ownerWithStore('restaurant');

        $this->get('/order-hub')->assertRedirect(url('/'));
        $this->get('/order-hub/store/'.$store->slug)->assertRedirect(route('publicView', $store->slug));
        $this->get('/order-hub/store/no-such-store')->assertRedirect(url('/'));
        $this->get('/order-hub/checkout')->assertRedirect(url('/')); // cross-store cart off
        $this->get(route('publicView', $store->slug))->assertOk();

        // With the cross-store cart on, the hub's own cart page serves /order-hub/checkout.
        config(['settings.admin_allow_multivendor_cart' => 1]);
        Feature::updateOrCreate(['key' => 'multivendor_cart'], ['label' => 'multivendor_cart', 'plan_gated' => false]);
        app()->forgetInstance('features.plan_gated');
        FeatureRegistry::clearCache();
        $item = $this->item($store);
        $this->postJson(route('orderhub.add'), ['store_id' => $store->id, 'item_id' => $item->id, 'quantity' => 1])->assertOk();
        $this->get('/order-hub/checkout')->assertOk()->assertSee($item->name)->assertSee(route('orderhub.placeOrder'), false);
        $this->assertStringNotContainsString('/fusion', (string) $this->get('/order-hub/checkout')->getContent());
    }

    public function test_a_shopper_wallet_topup_confirmed_without_marketplace_is_left_unsettled(): void
    {
        // A top-up whose payment was pending when the licence lapsed: Billing must not settle it (nothing would credit
        // the shopper's wallet) — no credited_at claim, no receipt, the credit message kept for a retry, an alert.
        $txn = Transaction::create([
            'product_name' => 'Wallet top-up', 'product_id' => 7, 'user_id' => null,
            'base_amount' => 100, 'amount' => 100, 'currency_code' => 'SAR',
            'transaction_method' => 'mk_wallet_topup', 'details' => ['shopper_id' => 7, 'currency' => 'SAR'],
        ]);
        $this->assertInstanceOf(StoreTransactionFulfiller::class, app(TransactionFulfiller::class));

        try {
            Settlement::settle($txn, 'stripe', 'pi_9', claim: true);
            $this->fail('the unsettled top-up must be reported');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('credit pending retry', $e->getMessage());
        }

        $txn->refresh();
        $this->assertNull($txn->credited_at);
        $this->assertSame(0, BillingReceipt::where('transaction_id', $txn->id)->count());
        $this->assertSame('pending', DB::table('outbox_messages')->where('dedupe_key', 'payments.credit:'.$txn->id)->value('status'));
        $this->assertTrue(PlatformAlert::where('kind', 'payment')->where('message', 'like', '%#'.$txn->id.' not credited%')->exists());

        // The admin "mark as paid" of such a payment leaves it pending (one DB transaction), so it can be done again
        // later — and the admin sees why (an error message, not a 500).
        $offline = Transaction::create([
            'product_name' => 'Wallet top-up', 'product_id' => 7, 'user_id' => null,
            'base_amount' => 50, 'amount' => 50, 'currency_code' => 'SAR', 'status' => Transaction::STATUS_PENDING,
            'transaction_method' => 'mk_wallet_topup', 'details' => ['shopper_id' => 7, 'currency' => 'SAR'],
        ]);
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($admin)->from(route('admin.transactions.show', $offline))
            ->put(route('admin.transactions.update', $offline))
            ->assertRedirect(route('admin.transactions.show', $offline))
            ->assertSessionHas('app_alert_type', 'error')
            ->assertSessionHas('app_alert_message', 'This payment cannot be marked as paid while the Marketplace module is not active. It stays pending.');
        $this->assertSame(Transaction::STATUS_PENDING, $offline->fresh()->status);
        $this->assertNull($offline->fresh()->credited_at);
        $this->assertSame(0, BillingReceipt::where('transaction_id', $offline->id)->count());
    }

    public function test_admin_and_owner_screens_render_without_the_platform_module(): void
    {
        // 13e: the admin dashboard read platform announcements directly (tile + "New announcement" button), the owner
        // dashboard included their banners, the owner nav links the support desk.
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        AdminDashboardService::flush();
        $html = (string) $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/admin/announcements', $html);
        $this->assertStringNotContainsString('/admin/support', $html);
        $ops = collect(app(AdminDashboardService::class)->overview()['ops'])->pluck('key');
        $this->assertNotContains('announcements', $ops->all());
        foreach (['admin.plugins.index', 'admin.alerts.index', 'admin.activity.index', 'admin.ownerMobileUsage'] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();
        }

        config(['settings.admin_allow_support_desk' => 1]);
        Feature::updateOrCreate(['key' => 'support_desk'], ['label' => 'support_desk', 'plan_gated' => false]);
        app()->forgetInstance('features.plan_gated');
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        [$owner, $store] = $this->ownerWithStore('restaurant');
        $html = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ann-id', $html);
        $this->assertStringNotContainsString('href="'.url('/help').'"', $html, 'no link to the platform support desk');
        $this->assertNull(Hooks::first('platform.support.ticket-handled', (int) $owner->id));
        $this->actingAs($owner)->get(route('activity-log.index'))->assertOk();
    }

    public function test_reseller_routes_are_absent_and_the_platform_pages_render_without_them(): void
    {
        // 13f: the Reseller module (white-label partners) is ee — the marketing home page, the App Store and the admin
        // screens linked its programme, tile and pages unconditionally.
        foreach (['reseller.portal', 'reseller.security', 'reseller.store.settings.identity', 'reseller.team.resend',
            'reseller.help.index', 'reseller.public.lang', 'reseller.announcement.dismiss', 'reseller.impersonate.exit',
            'partners.index', 'partners.signup', 'partners.signup.post', 'reseller-managed.index',
            'admin.resellers.index', 'admin.reseller-tiers.index', 'admin.resellers.golive.index', 'api.v1.reseller.stats'] as $name) {
            $this->assertFalse(Route::has($name), "route $name must be absent in Community");
        }
        foreach (['home', 'contact', 'help.index'] as $name) {
            $this->assertTrue(Route::has($name), "Community route $name must stay");
        }
        $this->assertSame(PassThrough::class, app('router')->getMiddleware()['reseller.suspension']);
        $this->assertArrayNotHasKey('reseller.can', app('router')->getMiddleware());
        $this->assertArrayNotHasKey('reseller:dunning', Artisan::all());

        $home = $this->get(route('home'));
        $home->assertRedirect(route('login'));
        $home->assertDontSee('Start free', false);
        $home->assertDontSee('/partners', false);
        $this->get(route('contact'))->assertOk()->assertDontSee(url('/partners'), false);
        $this->get(route('apps.store.index'))->assertOk()->assertDontSee(url('/partners'), false);

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        AdminDashboardService::flush();
        $this->assertNotContains('resellers', collect(app(AdminDashboardService::class)->overview()['ops'])->pluck('key')->all());
        $html = (string) $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/admin/resellers', $html);
        $this->actingAs($admin)->get(route('admin.security.accounts'))->assertOk();

        // An owner whose users.reseller_id survives from a licensed past (no reseller rows here): dashboard as usual.
        [$owner] = $this->ownerWithStore('restaurant');
        $owner->forceFill(['reseller_id' => 77])->save();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_guest_home_falls_through_to_login_when_no_platform_site_is_installed(): void
    {
        $this->assertTrue(Route::has('home'));
        $this->assertTrue(Route::has('contact'));
        foreach ([
            'pricing', 'features', 'themes', 'whatsapp.landing', 'no-commission',
            'tools.reseller-calculator', 'tools.commission-calculator', 'tools.qr-generator',
            'tools.qr-generator.render', 'tools.menu-scanner', 'testimonials',
            'country.landing', 'compare.show', 'guides.show', 'industries.show',
            'switch.gloriafood', 'studio.landing.v1', 'studio.landing.v2',
        ] as $name) {
            $this->assertFalse(Route::has($name), "route $name must be absent in Community");
        }

        $home = $this->get(route('home'));
        $home->assertRedirect(route('login'));
        $home->assertDontSee('Start free', false);
        $home->assertDontSee('/partners', false);

        $site = app(PlatformSite::class);
        $this->assertFalse($site->available());
        $this->assertFalse($site->enabled());

        $html = view('admin.settings.general', ['settings' => (object) [], 'active' => true])->render();
        $this->assertStringNotContainsString('Show the platform marketing site', $html);
    }

    public function test_the_desktop_pairing_card_is_absent_without_companion_clients(): void
    {
        $this->assertFalse(app(Edition::class)->companionClients());
        config(['settings.admin_allow_print_routing' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        [$owner, $store] = $this->ownerWithStore('restaurant');
        $html = (string) $this->actingAs($owner)->get(route('stores.printing.index', ['store_id' => $store->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Desktop app pairing', $html);
    }

    public function test_a_reseller_payment_marked_paid_without_the_module_stays_pending_with_a_message(): void
    {
        // 13f: reseller top-ups / tiers / wholesale invoices are credited by the Reseller module (ee); without it Billing
        // must not settle them — the admin sees why, the payment stays pending, a platform alert names it.
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        foreach (['reseller_topup', 'reseller_tier', 'reseller_invoice'] as $method) {
            $txn = Transaction::create([
                'product_name' => 'Reseller payment', 'product_id' => 0, 'user_id' => $admin->id,
                'base_amount' => 30, 'amount' => 30, 'currency_code' => 'SAR', 'status' => Transaction::STATUS_PENDING,
                'transaction_method' => $method, 'details' => ['reseller_id' => 5],
            ]);
            $this->actingAs($admin)->from(route('admin.transactions.show', $txn))
                ->put(route('admin.transactions.update', $txn))
                ->assertRedirect(route('admin.transactions.show', $txn))
                ->assertSessionHas('app_alert_type', 'error')
                ->assertSessionHas('app_alert_message', 'This payment cannot be marked as paid while the Reseller module is not active. It stays pending.');
            $this->assertSame(Transaction::STATUS_PENDING, $txn->fresh()->status);
            $this->assertNull($txn->fresh()->credited_at);
            $this->assertSame(0, BillingReceipt::where('transaction_id', $txn->id)->count());
            $this->assertTrue(PlatformAlert::where('kind', 'payment')->where('message', 'like', '%#'.$txn->id.' not credited%')->exists());
        }

        // 13g: without Billing a platform purchase (here a plan payment) is not settled either: the admin sees why.
        $plan = Transaction::create([
            'product_name' => 'Pro', 'product_id' => 3, 'user_id' => $admin->id, 'frequency' => 'MONTHLY',
            'base_amount' => 10, 'amount' => 10, 'currency_code' => 'SAR', 'status' => Transaction::STATUS_PENDING,
            'transaction_method' => 'stripe',
        ]);
        $this->actingAs($admin)->from(route('admin.transactions.index'))
            ->put(route('admin.transactions.update', $plan))
            ->assertRedirect(route('admin.transactions.index'))
            ->assertSessionHas('app_alert_message', 'This payment cannot be marked as paid while the Billing module is not active. It stays pending.');
        $this->assertSame(Transaction::STATUS_PENDING, $plan->fresh()->status);
        $this->assertNull($plan->fresh()->credited_at);
    }

    /** @return list<string> the tables an installed edition package's migrations create, dropped here */
    private function dropPackageTables(): array
    {
        $tables = [];
        foreach (ModuleMap::packageFolders() as $folder) {
            foreach (glob(ModuleMap::dir($folder['path']).'/Database/Migrations/*.php') ?: [] as $file) {
                preg_match_all("/Schema::create\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
                foreach ($m[1] as $table) {
                    $tables[basename($file).'#'.str_pad((string) count($tables), 5, '0', STR_PAD_LEFT)] = $table;
                }
            }
        }
        // Newest first, like a rollback: a later table's foreign key (hosted_messaging_optouts → hosted_installs)
        // must go before its parent, or MySQL / PostgreSQL refuse the drop.
        krsort($tables);
        $dropped = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
                $dropped[] = $table;
            }
        }

        return $dropped;
    }

    public function test_a_store_ownership_transfer_re_keys_without_the_enterprise_package_tables(): void
    {
        // Found by the mirror (13i-2b): the re-key walked every tenant table, including ones only an edition package
        // creates (directory suggestions) — a Community install without the package answered a transfer with a 500.
        $this->dropPackageTables();
        [, $store] = $this->ownerWithStore('restaurant');
        $account = (int) $store->account_id;
        $this->assertGreaterThan(0, $account);
        TenantFill::rekeyStore(DB::connection(), $store->id, $account);
        $this->assertSame($account, (int) DB::table('branches')->where('store_id', $store->id)->value('account_id'));
    }

    public function test_admin_pages_render_without_the_enterprise_package_tables(): void
    {
        // P1-13 13i (found by the mirror build): a Community install WITHOUT the Enterprise package has none of its tables
        // (Billing's subscriptions, plans, …), while this private suite always migrates them. Drop every table the
        // installed edition packages' migrations create, then render the Community admin pages that used to read them.
        $dropped = $this->dropPackageTables();
        if (ModuleMap::packageFolders() !== []) {
            $this->assertContains('subscriptions', $dropped);
        }

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        [$owner] = $this->ownerWithStore('restaurant');
        AdminDashboardService::flush();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->getJson(route('admin.users.index', ['draw' => 1, 'start' => 0, 'length' => 10]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->actingAs($admin)->get(route('admin.users.edit', $owner))->assertOk();
    }

    public function test_billing_routes_are_absent_and_the_admin_and_owner_pages_render_without_them(): void
    {
        // 13g: platform billing (plans, subscriptions, add-ons, platform wallet, checkout, taxes, invoices, upsell,
        // SaaS analytics) is ee; the admin payments ledger stays.
        foreach (['checkout.index', 'subscription', 'subscription.cancel', 'transactions', 'invoice', 'addons.index',
            'wallet.index', 'wallet.topup', 'feature.locked', 'spotlight.beacon', 'spotlight.help',
            'spotlight.trial-emails.unsubscribe', 'admin.plans.index', 'admin.planoption.index', 'admin.taxes.index',
            'admin.feature-matrix.index', 'admin.feature-catalog.index', 'admin.addon-products.index',
            'admin.spotlight.index', 'admin.users.plan', 'admin.users.subscription.pause', 'admin.transactions.refund',
            'admin.analytics', 'admin.analytics.export'] as $name) {
            $this->assertFalse(Route::has($name), "route $name must be absent in Community");
        }
        foreach (['admin.transactions.index', 'admin.transactions.show', 'admin.transactions.update', 'admin.receipts', 'admin.wallet.index'] as $name) {
            $this->assertTrue(Route::has($name), "Community route $name must stay");
        }
        $this->assertArrayNotHasKey('subscriptions:tick', Artisan::all());

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        [$owner, $store] = $this->ownerWithStore('restaurant');
        AdminDashboardService::flush();
        $html = (string) $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        foreach (['/admin/plans', '/admin/feature-matrix', '/admin/analytics', '/admin/taxes'] as $path) {
            $this->assertStringNotContainsString(url($path), $html);
        }
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->assertStringNotContainsString('change-plan-panel', (string) $this->actingAs($admin)->get(route('admin.users.edit', $owner))->assertOk()->getContent());
        $txn = Transaction::create([
            'product_name' => 'Gift card', 'product_id' => 0, 'user_id' => $owner->id, 'base_amount' => 20, 'amount' => 20,
            'currency_code' => 'USD', 'status' => Transaction::STATUS_SUCCESS, 'transaction_method' => 'gift_card_purchase',
        ]);
        $this->actingAs($admin)->get(route('admin.transactions.index'))->assertOk()->assertDontSee(url('/invoice/'.$txn->id), false);
        $this->actingAs($admin)->get(route('admin.transactions.show', $txn))->assertOk();
        $this->actingAs($admin)->get(route('admin.receipts'))->assertOk();

        $html = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="'.url('/subscription').'"', $html);
        $this->assertStringNotContainsString(url('/spotlight/beacon'), $html);
    }

    public function test_hotel_and_pharmacy_demo_seeders_run_without_writing_plans(): void
    {
        // Merge of 13c-R into 13g: the two demo seeders are Community again and used to write a throwaway plan (ee
        // Billing); without a licence there are no plans, so the demo owner runs on the edition plan instead.
        $plans = Schema::hasTable('plans') ? DB::table('plans')->count() : 0;
        $this->seed(ModuleSeeders::find('HotelDemoSeeder'));
        $this->seed(ModuleSeeders::find('PharmacyDemoSeeder'));
        $this->assertNull(ModuleSeeders::find('DemoThrowawayPlanSeeder'));
        $this->assertSame($plans, Schema::hasTable('plans') ? DB::table('plans')->count() : 0);
        foreach (['hotel-owner@demo.test' => 'hotel-demo', 'pharmacy-demo-owner@demo.test' => 'pharmacy-otc-demo'] as $email => $slug) {
            $owner = User::where('email', $email)->firstOrFail();
            $this->assertSame('free', (string) $owner->group_id);
            $this->assertSame('Community', $owner->plan()->name);
            $this->assertTrue(Store::where('slug', $slug)->where('user_id', $owner->id)->exists());
        }
    }

    public function test_a_business_own_customer_payment_settles_without_billing_once(): void
    {
        // 13g: a gift card bought from the store (its own gateway keys) is credited by Payments' StorePayments — the
        // same once-only claim and durable receipt Billing used — and an admin can mark an offline one paid.
        [, $store] = $this->ownerWithStore('restaurant');
        StoreOption::updateStoreOption($store->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($store->id, 40.0, 'stripe');

        app(TransactionFulfiller::class)->fulfil($txn->fresh());
        $this->assertSame('active', $card->fresh()->status);
        $this->assertEqualsWithDelta(40.0, $card->fresh()->balance(), 0.001);
        $this->assertNotNull($txn->fresh()->credited_at);
        $this->assertSame(1, BillingReceipt::where('transaction_id', $txn->id)->count());

        app(TransactionFulfiller::class)->fulfil($txn->fresh()); // a replayed confirmation credits nothing twice
        $this->assertEqualsWithDelta(40.0, $card->fresh()->balance(), 0.001);
        $this->assertSame(1, BillingReceipt::where('transaction_id', $txn->id)->count());

        ['card' => $offlineCard, 'transaction' => $offline] = GiftCardService::initiate($store->id, 15.0, 'wire_transfer');
        $offline->forceFill(['status' => Transaction::STATUS_PENDING])->save(); // an offline (bank transfer) payment awaiting confirmation
        // P1-23 part 6: the money went to the STORE's bank account — the platform admin cannot mark it paid (before: it
        // could); the store owner confirms it.
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($admin)->from(route('admin.transactions.index'))
            ->put(route('admin.transactions.update', $offline))
            ->assertRedirect(route('admin.transactions.index'))
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(Transaction::STATUS_PENDING, $offline->fresh()->status);
        $this->assertTrue(app(StoreTransfers::class)->confirm($offline->fresh(), (int) $store->user_id));
        $this->assertSame(Transaction::STATUS_SUCCESS, $offline->fresh()->status);
        $this->assertSame('active', $offlineCard->fresh()->status);
        $this->assertEqualsWithDelta(15.0, $offlineCard->fresh()->balance(), 0.001);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(string $type, string $template = 'crave'): array
    {
        // 13g: no platform plans in Community — every owner runs on the edition plan (no plan limits, no plan gating).
        $owner = User::factory()->create([
            'group_id' => 'free', 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
        $title = 'S '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 't', 'description' => 't',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }

    private function item(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Main', 'active' => '1']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item '.Str::random(5), 'price' => 5, 'active' => 1]);
    }

    /** @return \Closure(string): bool is this class in a module folder of an installed edition package? */
    private function editionClassCheck(): \Closure
    {
        $prefixes = array_map(fn (array $folder) => $folder['namespace'].'\\', ModuleMap::packageFolders());

        return function (string $class) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    return true;
                }
            }

            return false;
        };
    }
}
