<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\Entitlements;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Content\ResellerContent;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Editions\Community\CommunityEntitlementSource;
use Juwri\Core\Editions\Community\CommunityPlanCatalog;
use Juwri\Core\Editions\Community\NoAddonTax;
use Juwri\Core\Editions\Community\NoDirectory;
use Juwri\Core\Editions\Community\NoFeatureCommerce;
use Juwri\Core\Editions\Community\NoResellers;
use Juwri\Core\Editions\Community\NoShopperBag;
use Juwri\Core\Editions\CommunityDefaults;
use Juwri\Core\Http\Middleware\EditionSlot;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\BrandContext;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ImpersonationRoutes;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ScheduleSlots;
use Juwri\Modules\Accounting\Services\Edition\AccountingLedger;
use Juwri\Modules\Hotel\Services\Edition\HotelRoomCharge;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-13 13a: the edition extension points Community code calls instead of ee classes — Community defaults, the
 * Kernel middleware slots, absent ee route slots and the store backup registry extension.
 */
class EditionExtensionPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_extension_point_resolves(): void
    {
        foreach (array_keys(CommunityDefaults::BINDINGS) as $contract) {
            $this->assertInstanceOf($contract, app($contract), $contract);
        }
    }

    public function test_the_community_plan_name_is_shown_in_the_owners_language(): void
    {
        $this->app->instance(PlanCatalog::class, new CommunityPlanCatalog);
        $user = User::factory()->create();

        app()->setLocale('en');
        $this->assertSame('Community', (new CommunityPlanCatalog)->planFor($user)->name);
        app()->setLocale('ar');
        $this->assertSame('المجتمع', (new CommunityPlanCatalog)->planFor($user)->name);
        $this->assertSame('community', (new CommunityPlanCatalog)->planFor($user)->id, 'the id is the stable key, never translated');
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            app()->setLocale($locale);
            $this->assertTrue(Lang::has('lang.community', $locale, false), "lang.community missing for {$locale}");
        }
        app()->setLocale('en');
    }

    public function test_community_entitlements_grant_every_feature_with_no_limits(): void
    {
        $this->app->instance(PlanCatalog::class, new CommunityPlanCatalog);
        $this->app->instance(EntitlementSource::class, new CommunityEntitlementSource);
        Entitlements::flushBundleMemo();
        $user = User::factory()->create();

        $this->assertNull($user->liveSubscription());
        $this->assertSame('community', $user->plan()->id);
        $this->assertNull($user->plan()->settings->restaurant_limit, 'unset settings read as null, never a warning');

        $this->assertTrue(Entitlements::has($user, 'pos_enabled'));
        $this->assertNull(Entitlements::limit($user, 'restaurant'), 'no limit = unlimited');
        $this->assertNull(Entitlements::planValue($user, 'sms_monthly_limit'), 'value keys stay unset (module default)');

        // The kill switch and the ban still veto in Community.
        config(['settings.feature_pos_enabled' => false]);
        $this->assertFalse(Entitlements::has($user, 'pos_enabled'));
        config(['settings.feature_pos_enabled' => true]);
        $user->forceFill(['status' => 0])->save();
        $this->assertFalse(Entitlements::has($user->fresh(), 'pos_enabled'));
    }

    public function test_community_sells_nothing_and_adds_no_tax(): void
    {
        $user = User::factory()->create();
        $commerce = new NoFeatureCommerce;
        $this->assertFalse($commerce->sellable('pos_enabled'));
        $this->assertSame([], $commerce->sellableKeys());
        $this->assertNull($commerce->addonSkuFor('pos_enabled'));
        $this->assertSame(['tax' => 0.0, 'breakdown' => []], (new NoAddonTax)->exclusiveTaxFor($user, 10.0, 'USD'));
    }

    public function test_edition_slot_runs_its_middleware_in_order_and_an_empty_slot_passes(): void
    {
        $seen = [];
        $this->app->instance(EditionSlotProbeA::class, new EditionSlotProbeA($seen));
        $this->app->instance(EditionSlotProbeB::class, new EditionSlotProbeB($seen));
        EditionSlot::add('test-slot', EditionSlotProbeB::class, 20);
        EditionSlot::add('test-slot', EditionSlotProbeA::class, 10);

        $out = (new EditionSlot)->handle(Request::create('/x'), fn () => 'next', 'test-slot');
        $this->assertSame('next', $out);
        $this->assertSame(['A', 'B'], $seen);

        $this->assertSame('next', (new EditionSlot)->handle(Request::create('/x'), fn () => 'next', 'empty-slot'));
    }

    public function test_a_slot_of_a_module_that_is_not_installed_is_skipped_and_a_community_one_still_fails(): void
    {
        ModuleRoutes::slot('NoSuchEditionModule/no-such-slot'); // not in the module map (edition not installed): silently absent
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        ModuleRoutes::slot('Menu/no-such-slot');
    }

    public function test_without_resellers_the_brand_is_the_platform_and_owner_notices_render(): void
    {
        $none = new NoResellers;
        foreach ([ResellerContext::class, ResellerSite::class, ResellerContent::class] as $contract) {
            $this->app->instance($contract, $none);
        }
        $this->app->instance(PlanCatalog::class, new CommunityPlanCatalog);
        config(['settings.site_title' => 'Juwri Test']);

        $brand = BrandContext::current();
        $this->assertFalse($brand->isReseller());
        $this->assertSame('Juwri Test', $brand->name());
        $this->assertFalse($brand->showPoweredBy());

        // A user that still carries a reseller_id (data from an ee install) renders the shell notices without ee.
        $user = User::factory()->create(['reseller_id' => 77]);
        $this->actingAs($user);
        $this->assertIsString((string) $this->blade('<x-ui.shell.notices />'));
        $this->assertSame([], $none->sitemapEntries($user));
        $this->assertSame([], $none->publishedPosts(77, 5));
    }

    public function test_impersonation_route_guard_lives_in_core(): void
    {
        $this->assertTrue(ImpersonationRoutes::blocks('transfer.store.offer'));
        $this->assertFalse(ImpersonationRoutes::blocks('transfer.index'));
        $this->assertFalse(ImpersonationRoutes::blocksNow('transfer.store.offer'), 'no impersonation session');
        session(['impersonating' => ['started_at' => now()->timestamp]]);
        $this->assertTrue(ImpersonationRoutes::blocksNow('api.webhook.create'));
    }

    public function test_hooks_run_in_order_best_effort_and_first_answers(): void
    {
        $seen = [];
        Hooks::on('p113.test', 'a', function (string $x) use (&$seen) {
            $seen[] = 'a'.$x;
        });
        Hooks::on('p113.test', 'boom', fn () => throw new RuntimeException('ignored'));
        Hooks::on('p113.test', 'b', function (string $x) use (&$seen) {
            $seen[] = 'b'.$x;

            return 'B';
        });
        Hooks::run('p113.test', '1');
        $this->assertSame(['a1', 'b1'], $seen, 'a throwing hook never stops the slot');
        $this->assertSame('B', Hooks::first('p113.test', '2'));
        Hooks::run('p113.empty');
        $this->assertNull(Hooks::first('p113.empty'));
    }

    public function test_community_has_no_directory(): void
    {
        $this->assertFalse((new NoDirectory)->enabled());
        $this->assertFalse((new NoDirectory)->navVisible(1, null));
        $this->assertSame([], (new NoDirectory)->sitemapEntries());
        // 13d: no marketplace shoppers — the cross-store cart is the guest token's, nothing linked or quoted.
        $bag = new NoShopperBag;
        $this->assertNull($bag->shopperId());
        $this->assertNull($bag->shopper());
        $this->assertNull($bag->linkStoreCustomer((object) ['id' => 1, 'phone' => '1'], 1, []));
        $this->assertSame([], $bag->quote(new \stdClass, 1.0, 2.0));
        $this->assertSame([], $bag->quoteWithCoupons(null, null));
    }

    public function test_the_hotel_and_accounting_modules_bind_their_extension_points_and_compliance_reads_store_options(): void
    {
        // P1-13 13c-R: Hotel and Accounting are Community modules again; their contracts stay (Core models, Ordering,
        // Hr, Pos and Verticals may not import them) and are bound by the modules themselves — no Community no-ops.
        $this->assertInstanceOf(HotelRoomCharge::class, app(RoomCharge::class));
        $this->assertInstanceOf(AccountingLedger::class, app(GeneralLedger::class));
        $this->assertArrayNotHasKey(RoomCharge::class, CommunityDefaults::BINDINGS);
        $this->assertArrayNotHasKey(GeneralLedger::class, CommunityDefaults::BINDINGS);
        // Hotel relations on Core/Tables models are Hotel dynamic relations now.
        $this->assertSame('hotel_stays', (new Store)->hotelStays()->getRelated()->getTable());
        $this->assertSame('hotel_stays', (new StoreTable)->activeStay()->getRelated()->getTable());
        $this->assertSame('room_type_id', (new StoreTable)->roomType()->getForeignKeyName());

        // The storefront OTC / age-gate copy comes straight from the Pharmacy module again (Storefront may import it).
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        $compliance = app(PharmacyComplianceService::class);
        $this->assertTrue($compliance->entryGateEnabled($store), 'compliance-safe default');
        $this->assertStringContainsString('OTC', $compliance->disclaimerText($store));
        StoreOption::updateStoreOption($store->id, 'pharmacy_disclaimer_text', 'Ask the pharmacist.');
        StoreOption::updateStoreOption($store->id, 'pharmacy_show_entry_gate', '0');
        $this->assertSame('Ask the pharmacist.', $compliance->disclaimerText($store->fresh()));
        $this->assertFalse($compliance->entryGateEnabled($store->fresh()));
    }

    public function test_schedule_slots_run_by_order_then_registration(): void
    {
        $seen = [];
        ScheduleSlots::add('p113.test', 'b', function () use (&$seen) {
            $seen[] = 'b';
        }, order: 20);
        ScheduleSlots::add('p113.test', 'a', function () use (&$seen) {
            $seen[] = 'a';
        }, order: 10);
        ScheduleSlots::add('p113.test', 'c', function () use (&$seen) {
            $seen[] = 'c';
        }, order: 20);
        ScheduleSlots::apply('p113.test', new Schedule, fn (string $name) => fn () => null);
        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertSame(['a', 'b', 'c'], ScheduleSlots::names('p113.test'));
    }
}

final class EditionSlotProbeA
{
    /** @param list<string> $seen */
    public function __construct(private array &$seen) {}

    public function handle(Request $request, \Closure $next): mixed
    {
        $this->seen[] = 'A';

        return $next($request);
    }
}

final class EditionSlotProbeB
{
    /** @param list<string> $seen */
    public function __construct(private array &$seen) {}

    public function handle(Request $request, \Closure $next): mixed
    {
        $this->seen[] = 'B';

        return $next($request);
    }
}
