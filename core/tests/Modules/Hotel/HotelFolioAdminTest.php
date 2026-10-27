<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelEventItem;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\EventService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;
use Juwri\Modules\Hotel\Services\Hotel\Pms\GuestDto;
use Juwri\Modules\Hotel\Services\Hotel\Pms\MewsConnector;
use Juwri\Modules\Hotel\Services\Hotel\Pms\NullPmsConnector;
use Juwri\Modules\Hotel\Services\Hotel\Pms\PmsConnectorInterface;
use Juwri\Modules\Hotel\Services\Hotel\Pms\PmsManager;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * FEATURES-RUN-4 T1 — H7 (folio & stays admin UI), H10 (PMS seam), H11 (gating).
 * Verifies the owner stays/folio screens render + settle, that the superadmin
 * per-plan feature flag gates the whole module, and that the PMS stub resolves.
 */
class HotelFolioAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(bool $hotelMode = true, bool $planGrantsHotel = true): array
    {
        $planSettings = ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1'];
        if ($planGrantsHotel) {
            $planSettings['hotel'] = '1';
        }
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => $planSettings, 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        if ($hotelMode) {
            StoreSettings::set($store->id, 'hotel_mode', '1');
        }

        return [$owner, $store];
    }

    private function room(Store $store, int $no = 101): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10), 'area_type' => 'room',
        ]);
    }

    private function order(Store $store, StoreTable $room, float $price): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room '.$room->table_no,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);

        return $order->fresh();
    }

    // ------------------------------------------------------------------ H7

    public function test_stays_index_renders_active_stay_with_balance(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter');
        HotelFolioService::chargeOrder($this->order($store, $room, 25.00), $stay);

        $this->actingAs($owner)->get(route('hotel.stays'))
            ->assertOk()
            ->assertSee('Sam Carter')
            ->assertSee('#101');
    }

    public function test_folio_statement_page_renders_charges(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.50), $stay);

        $this->actingAs($owner)->get(route('hotel.stays.show', $stay->id))
            ->assertOk()
            ->assertSee('40.50');
    }

    public function test_settle_action_reduces_balance(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        $this->actingAs($owner)->post(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'method' => 'cash'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(30.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_checkout_requires_zero_balance_then_succeeds(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 20.00), $stay);

        // Non-zero balance: redirect back (error), stay still active.
        $this->actingAs($owner)->post(route('hotel.folio.checkout', $stay->id))->assertRedirect();
        $this->assertSame('checked_in', $stay->fresh()->status);

        HotelFolioService::settle($stay, 20.00, 'cash');
        $this->actingAs($owner)->post(route('hotel.folio.checkout', $stay->id))->assertRedirect();
        $this->assertSame('checked_out', $stay->fresh()->status);
    }

    public function test_checkin_via_http_opens_a_stay(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);

        $this->actingAs($owner)->post(route('hotel.folio.checkin'), [
            'table_id' => $room->id, 'guest_name' => 'Dana',
        ])->assertRedirect();

        $this->assertNotNull(HotelFolioService::activeStayForRoom($store->id, $room->id));
    }

    // ------------------------------------------------------------------ H11 gating

    public function test_module_hidden_when_plan_does_not_grant_hotel(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        // hotel_mode ON but the plan does NOT sell the hotel feature → module off.
        [$owner, $store] = $this->store(hotelMode: true, planGrantsHotel: false);

        $this->assertFalse(HotelService::enabled($store->id));
        $this->room($store);
        // HP wind-down reconcile: the DRAIN routes (stays/statement/settle/checkout)
        // deliberately dropped `entitled:hotel` so an owner can still close an OPEN
        // folio after a downgrade. With no open stay there is nothing to wind down, so
        // the controller's hotelRestaurantForWindDown() 403s here rather than showing
        // the upsell paywall (see FeaturePlatformFix7HotelRoutesTest + HotelWindDownTest).
        $this->actingAs($owner)->get(route('hotel.stays'))
            ->assertForbidden();
        // hotel.rooms is a STRICT admin route — it keeps entitled:hotel and still
        // lands the unentitled owner on the upsell paywall (show-locked beats hidden).
        $this->actingAs($owner)->get(route('hotel.rooms'))
            ->assertRedirect(route('feature.locked', 'hotel'));
    }

    public function test_module_hidden_when_owner_toggle_off(): void
    {
        // plan grants hotel but the owner has not switched it on → module off.
        [$owner, $store] = $this->store(hotelMode: false, planGrantsHotel: true);

        $this->assertFalse(HotelService::enabled($store->id));
        // ...but the settings entry (available) IS reachable so the owner can turn it on.
        $this->assertTrue(HotelService::available($store->id));
        $this->actingAs($owner)->get(route('hotel.info'))->assertOk();
    }

    public function test_module_enabled_when_plan_and_toggle_on(): void
    {
        [$owner, $store] = $this->store(hotelMode: true, planGrantsHotel: true);

        $this->assertTrue(HotelService::enabled($store->id));
        $this->room($store);
        $this->actingAs($owner)->get(route('hotel.stays'))->assertOk();
    }

    // ------------------------------------------------------------------ H10 PMS seam

    public function test_pms_defaults_to_null_connector(): void
    {
        [, $store] = $this->store();

        $driver = PmsManager::for($store->id);
        $this->assertInstanceOf(PmsConnectorInterface::class, $driver);
        $this->assertInstanceOf(NullPmsConnector::class, $driver);
        $this->assertSame('null', $driver->key());
        $this->assertFalse(PmsManager::configured($store->id));
        $this->assertNull($driver->validateGuest('101', 'Sam'));
        $this->assertSame([], iterator_to_array((function () use ($driver) {
            yield from $driver->fetchStays();
        })()));
    }

    public function test_pms_manager_resolves_a_registered_fake_driver(): void
    {
        $fake = new class implements PmsConnectorInterface
        {
            public bool $posted = false;

            public function key(): string
            {
                return 'mews';
            }

            public function label(): string
            {
                return 'Fake Mews';
            }

            public function validateGuest(string $room, string $name): ?GuestDto
            {
                return new GuestDto($name, $room);
            }

            public function postCharge(HotelStay $stay, Order $order): void
            {
                $this->posted = true;
            }

            public function fetchStays(): iterable
            {
                return [];
            }
        };
        PmsManager::register($fake);

        $this->assertSame('mews', PmsManager::driver('mews')->key());
        PmsManager::flush();
        // After flush the builtins are rebuilt. A8 shipped the real MewsConnector, so
        // 'mews' now resolves that builtin (not the flushed fake); an UNKNOWN provider
        // key still falls back to the Null driver.
        $this->assertInstanceOf(MewsConnector::class, PmsManager::driver('mews'));
        $this->assertSame('mews', PmsManager::driver('mews')->key());
        $this->assertSame('null', PmsManager::driver('nonesuch')->key());
    }

    // ------------------------------------------------------------------ P6-181

    /** Another owner's stay id is a 404 on settle — no money moves on the foreign folio. */
    public function test_settle_on_another_stores_stay_is_404(): void
    {
        [$ownerA] = $this->store();
        [, $postB] = $this->store();
        $room = $this->room($postB);
        $stay = HotelFolioService::checkIn($postB->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($postB, $room, 40.00), $stay);

        $this->actingAs($ownerA)->postJson(route('hotel.folio.settle', $stay->id), ['amount' => 10])->assertNotFound();
        $this->assertEqualsWithDelta(40.0, HotelFolioService::balance($stay), 0.001);
    }

    /** A double POST without an idempotency key cannot settle twice / overpay: the second full settle is refused. */
    public function test_double_settle_without_key_cannot_overpay(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        $this->actingAs($owner)->postJson(route('hotel.folio.settle', $stay->id), ['amount' => 40])->assertOk();
        $this->actingAs($owner)->postJson(route('hotel.folio.settle', $stay->id), ['amount' => 40])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('hotel.folio.settle', $stay->id), ['amount' => 0.01])->assertStatus(422);

        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($stay), 0.001);
    }

    /** The same key replayed records the payment once. */
    public function test_settle_replay_with_key_records_once(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);
        $key = (string) Str::uuid();

        $this->actingAs($owner)->postJson(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'idempotency_key' => $key])->assertOk();
        $this->actingAs($owner)->postJson(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'idempotency_key' => $key])->assertOk();

        $this->assertEqualsWithDelta(30.0, HotelFolioService::balance($stay), 0.001);
    }

    private function confirmedEvent(Store $store): HotelEvent
    {
        $e = HotelEvent::create(['store_id' => $store->id, 'title' => 'Gala', 'status' => 'enquiry']);
        HotelEventItem::create(['event_id' => $e->id, 'description' => 'Hire', 'qty' => 1, 'unit_price' => 100]);
        EventService::confirm($e);

        return $e->fresh();
    }

    public function test_event_settle_cross_store_404_and_no_overpay_without_key(): void
    {
        [$ownerA, $postA] = $this->store();
        [, $postB] = $this->store();
        $foreign = $this->confirmedEvent($postB);
        $own = $this->confirmedEvent($postA);

        $this->actingAs($ownerA)->withSession(['dashboard_store_id' => $postA->id])
            ->postJson(route('hotel.pms.events.settle', $foreign->id), ['amount' => 10])->assertNotFound();
        $this->assertEqualsWithDelta(100.0, HotelFolioService::eventBalance($foreign), 0.001);

        $this->actingAs($ownerA)->withSession(['dashboard_store_id' => $postA->id])
            ->postJson(route('hotel.pms.events.settle', $own->id), ['amount' => 100])->assertOk();
        $this->actingAs($ownerA)->withSession(['dashboard_store_id' => $postA->id])
            ->postJson(route('hotel.pms.events.settle', $own->id), ['amount' => 100])->assertStatus(422);
        $this->assertEqualsWithDelta(0.0, HotelFolioService::eventBalance($own), 0.001);
    }
}
