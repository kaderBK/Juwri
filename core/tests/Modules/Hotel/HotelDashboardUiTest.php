<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HP lane B — dashboard UI behaviours: hotel_mode wind-down guard, rooms admin
 * (edit payload / guarded delete / dedupe / pagination), stays & folio surfaces,
 * orders-board chip, requests board, hotel settings and alert-channel toggles.
 *
 * Rendered as the owner (is_staff_dashboard() false → the permission middleware
 * early-returns); the staff permission matrix lives in HotelFolioStaffPermissionTest.
 */
class HotelDashboardUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34']; // P6-68: test hosts resolve public
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // SPEC §4: use the shared helper (not a hand-rolled context) — ViewComposerContractTest.
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);

        // StoreOption's read memo binds to spl_object_id(app()), which the runtime can
        // reuse across tests once an app is GC'd — leaking a prior test's option reads.
        // Flush it so default/read-back assertions see this test's data only.
        StoreOption::flushOptionMemo();
    }

    /** @return array{0: User, 1: Store} */
    protected function store(bool $hotel = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($hotel) {
            StoreSettings::set($store->id, 'hotel_mode', '1');
        }

        return [$owner, $store];
    }

    protected function room(Store $store, int $no = 101, string $type = 'room'): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10), 'area_type' => $type,
        ]);
    }

    protected function chargedStay(Store $store, StoreTable $room, float $price): HotelStay
    {
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room '.$room->table_no,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        HotelFolioService::chargeOrder($order->fresh(), $stay);

        return $stay->fresh();
    }

    // -------------------------------------------------------------- B1 wind-down

    public function test_turning_hotel_mode_off_is_refused_when_an_open_folio_exists(): void
    {
        [$owner, $store] = $this->store();
        $this->chargedStay($store, $this->room($store, 101), 40.00);

        $this->actingAs($owner)
            ->post(route('hotel.mode'), ['hotel_mode' => '0'])
            ->assertRedirect();

        // Mode was NOT turned off — the open folio stays reachable.
        $this->assertSame('1', (string) StoreSettings::legacy((int) $store->id, 'hotel_mode'));
    }

    public function test_turning_hotel_mode_off_proceeds_with_explicit_confirm(): void
    {
        [$owner, $store] = $this->store();
        $this->chargedStay($store, $this->room($store, 101), 40.00);

        $this->actingAs($owner)
            ->post(route('hotel.mode'), ['hotel_mode' => '0', 'confirm' => '1'])
            ->assertRedirect();

        $this->assertSame('0', (string) StoreSettings::legacy((int) $store->id, 'hotel_mode'));
    }

    public function test_turning_hotel_mode_off_is_allowed_with_no_open_folios(): void
    {
        [$owner, $store] = $this->store();
        // A checked-in guest with a ZERO balance does not block the toggle.
        HotelFolioService::checkIn($store->id, $this->room($store, 102), 'Zero');

        $this->actingAs($owner)
            ->post(route('hotel.mode'), ['hotel_mode' => '0'])
            ->assertRedirect();

        $this->assertSame('0', (string) StoreSettings::legacy((int) $store->id, 'hotel_mode'));
    }

    // -------------------------------------------------------------- B2 rooms admin

    public function test_edit_room_button_renders_a_compiled_js_payload(): void
    {
        [$owner, $store] = $this->store();
        $this->room($store, 207);

        $res = $this->actingAs($owner)->get(route('hotel.rooms'))->assertOk();
        $html = $res->getContent();

        // The Edit button opens with a real JS object, NOT a literal @js(...) directive.
        $this->assertStringContainsString('openEdit(', $html);
        $this->assertStringNotContainsString('@js(', $html);
        // The room's own data is present in the compiled payload.
        $this->assertStringContainsString('207', $html);
    }

    public function test_deleting_a_room_with_a_checked_in_guest_is_refused(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store, 210);
        HotelFolioService::checkIn($store->id, $room, 'Guest');

        $this->actingAs($owner)
            ->post(route('hotel.rooms.delete'), ['id' => $room->id])
            ->assertRedirect();

        // Room survives — the stranded-folio / 500-on-Stays bug is prevented.
        $this->assertDatabaseHas('store_tables', ['id' => $room->id]);
    }

    public function test_delete_ignores_non_room_tables(): void
    {
        [$owner, $store] = $this->store();
        $table = $this->room($store, 9, 'table'); // ordinary dining table

        $this->actingAs($owner)
            ->post(route('hotel.rooms.delete'), ['id' => $table->id])
            ->assertNotFound();

        $this->assertDatabaseHas('store_tables', ['id' => $table->id]);
    }

    public function test_single_create_rejects_a_duplicate_number(): void
    {
        [$owner, $store] = $this->store();
        $this->room($store, 301);

        $this->actingAs($owner)
            ->post(route('hotel.rooms.store'), ['table_no' => 301, 'area_type' => 'room'])
            ->assertRedirect();

        $this->assertSame(1, StoreTable::where('store_id', $store->id)->where('table_no', 301)->count());
    }

    public function test_update_cannot_renumber_onto_an_existing_number(): void
    {
        [$owner, $store] = $this->store();
        $this->room($store, 401);
        $r2 = $this->room($store, 402);

        $this->actingAs($owner)
            ->post(route('hotel.rooms.store'), ['id' => $r2->id, 'table_no' => 401, 'area_type' => 'room'])
            ->assertRedirect();

        // 402 was not renumbered onto 401.
        $this->assertSame(402, (int) $r2->fresh()->table_no);
    }

    public function test_rooms_list_paginates_past_100(): void
    {
        [$owner, $store] = $this->store();
        for ($n = 1; $n <= 105; $n++) {
            StoreTable::create(['store_id' => $store->id, 'table_no' => $n, 'key' => Str::random(10), 'area_type' => 'room', 'label' => 'RM'.$n]);
        }

        // Page 1 holds the first 100 (1..100); room 105 is on page 2.
        $this->actingAs($owner)->get(route('hotel.rooms'))
            ->assertOk()
            ->assertSee('RM100')
            ->assertDontSee('RM105');

        $this->actingAs($owner)->get(route('hotel.rooms', ['page' => 2]))
            ->assertOk()
            ->assertSee('RM105');
    }

    public function test_rooms_search_filters_the_list(): void
    {
        [$owner, $store] = $this->store();
        $this->room($store, 111);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 222, 'key' => Str::random(10), 'area_type' => 'room', 'label' => 'Penthouse']);

        $this->actingAs($owner)->get(route('hotel.rooms', ['q' => 'Penthouse']))
            // Assert on the room-number cell, not on the bare text "#111": that is also a CSS colour (the cookie
            // banner's ink, the store colour above), so a bare match proved nothing about the list.
            ->assertOk()->assertSee('Penthouse')
            ->assertSeeHtml('<span class="ui-ltr">#222</span>')
            ->assertDontSeeHtml('<span class="ui-ltr">#111</span>');
    }

    // -------------------------------------------------------------- B3 stays & folio

    public function test_stays_list_renders_the_folio_currency_on_balances(): void
    {
        [$owner, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $this->chargedStay($store, $this->room($store, 101), 40.00);

        $this->actingAs($owner)->get(route('hotel.stays'))
            ->assertOk()
            ->assertSee('USD 40.00');
    }

    public function test_folio_statement_renders_and_arms_write_off_when_balance_due(): void
    {
        [$owner, $store] = $this->store();
        $stay = $this->chargedStay($store, $this->room($store, 102), 25.00);

        $this->actingAs($owner)->get(route('hotel.stays.show', $stay->id))
            ->assertOk()
            ->assertSee(___('Write off & check out'))
            // Type-to-confirm: the disable-bind references the exact balance string.
            ->assertSee('25.00');
    }

    // NOTE (merge reconcile): the former test_folio_statement_hides_lane_a_forms_when_routes_absent
    // asserted the Route::has() guard's OFF branch (lane A's hotel.folio.adjust / .stay.update
    // routes absent). Post-merge those routes are always registered on main, so that state is
    // unreachable and the assertion is dead. The blade guard remains as pre-merge/defensive code;
    // test_folio_statement_shows_lane_a_forms_when_routes_exist covers the live wired behavior.

    public function test_folio_statement_shows_lane_a_forms_when_routes_exist(): void
    {
        Route::post('/_t/folio/{stay}/adjust', fn () => '')->name('hotel.folio.adjust');
        Route::post('/_t/folio/{stay}/stay-update', fn () => '')->name('hotel.folio.stay.update');
        // ->name() is fluent AFTER add(), so the name lookup must be refreshed for Route::has().
        app('router')->getRoutes()->refreshNameLookups();

        [$owner, $store] = $this->store();
        $stay = $this->chargedStay($store, $this->room($store, 104), 25.00);

        $res = $this->actingAs($owner)->get(route('hotel.stays.show', $stay->id))->assertOk();
        $res->assertSee(___('Manual charge / adjustment'));
        $res->assertSee(___('Edit stay'));
    }

    public function test_late_charge_indicator_on_a_settled_folio(): void
    {
        [$owner, $store] = $this->store();
        $stay = $this->chargedStay($store, $this->room($store, 105), 30.00);
        // Simulate a charge that landed AFTER check-out (the late-charge race).
        $stay->update(['status' => 'checked_out', 'checked_out_at' => now()->subHour()]);

        $this->actingAs($owner)->get(route('hotel.stays.show', $stay->id))
            ->assertOk()
            ->assertSee(___('Late charge'));
    }

    // -------------------------------------------------------------- B4 orders surfaces

    private function renderOrderRow(Store $store, Order $order): string
    {
        $opts = (object) [];

        return view('owner.orders.order-row', [
            'order' => $order->fresh('items'),
            'postOptions' => $opts,
        ])->render();
    }

    public function test_charge_to_room_order_shows_room_folio_chip_not_unpaid(): void
    {
        [, $store] = $this->store();
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'preparing',
            'payment_method' => 'charge_to_room', 'service_location' => 'Room 204',
            'table_number' => 204, 'subtotal' => 40, 'grand_total' => 40,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);

        $html = $this->renderOrderRow($store, $order);

        $this->assertStringContainsString(___('Room · Folio'), $html);
        $this->assertStringContainsString('Room 204', $html);
        $this->assertStringContainsString(___('Charged to room folio'), $html);
    }

    public function test_receipt_breakdown_includes_the_tray_charge_line(): void
    {
        [, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'hotel_tray_charge', '5');
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'preparing',
            'payment_method' => 'charge_to_room', 'service_location' => 'Room 9',
            'table_number' => 9, 'subtotal' => 40, 'grand_total' => 45,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);

        $html = $this->renderOrderRow($store, $order);

        // Tray charge line renders; and equals the store's flat tray amount.
        $this->assertStringContainsString(___('Tray charge'), $html);
        $this->assertSame(5.0, Order::trayCharge('room_service', 40.0, $store->id));
    }

    public function test_non_room_order_has_no_tray_line(): void
    {
        [, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'hotel_tray_charge', '5');
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'preparing',
            'subtotal' => 40, 'grand_total' => 40, 'track_token' => Str::random(16), 'created_at' => now(),
        ]);

        $this->assertStringNotContainsString(___('Tray charge'), $this->renderOrderRow($store, $order));
    }

    public function test_folio_print_carries_rtl_dir_under_arabic(): void
    {
        [, $store] = $this->store();
        $stay = $this->chargedStay($store, $this->room($store, 301), 30.00);

        app()->setLocale('ar');
        $statement = HotelFolioService::statement($stay);
        $html = view('owner.hotel.folio-print', compact('store', 'stay', 'statement'))->render();
        app()->setLocale('en');

        $this->assertStringContainsString('dir="rtl"', $html);
    }

    // -------------------------------------------------------------- B5 requests board

    private function staffMember(Store $store, string $role = 'waiter'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'St '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    public function test_board_json_is_status_scoped_and_never_drops_an_open_request(): void
    {
        [$owner, $store] = $this->store();
        // The one open request is created FIRST (lowest id) — the old single-window
        // query ordered by id desc would push it out behind a wall of done rows.
        $open = ServiceRequest::create(['store_id' => $store->id, 'type' => 'maintenance', 'status' => 'open']);
        for ($i = 0; $i < 55; $i++) {
            ServiceRequest::create(['store_id' => $store->id, 'type' => 'housekeeping', 'status' => 'done']);
        }

        $res = $this->actingAs($owner)->getJson(route('hotel.requests'))->assertOk();
        $res->assertJsonStructure(['open', 'in_progress', 'done', 'counts' => ['open', 'in_progress', 'done']]);

        $json = $res->json();
        $this->assertSame(1, $json['counts']['open']);
        $this->assertContains($open->id, array_column($json['open'], 'id'));
        $this->assertCount(50, $json['done']); // done capped, open never dropped
    }

    public function test_board_assign_persists_the_staff_member(): void
    {
        [$owner, $store] = $this->store();
        $req = ServiceRequest::create(['store_id' => $store->id, 'type' => 'amenity', 'status' => 'open']);
        $staff = $this->staffMember($store);

        $this->actingAs($owner)
            ->postJson(route('hotel.requests.status'), ['id' => $req->id, 'status' => 'open', 'assigned_staff_id' => $staff->id])
            ->assertOk();

        $this->assertSame($staff->id, (int) $req->fresh()->assigned_staff_id);
    }

    public function test_board_assign_rejects_a_foreign_staff_member(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();
        $req = ServiceRequest::create(['store_id' => $store->id, 'type' => 'amenity', 'status' => 'open']);
        $foreign = $this->staffMember($other);

        $this->actingAs($owner)
            ->postJson(route('hotel.requests.status'), ['id' => $req->id, 'status' => 'open', 'assigned_staff_id' => $foreign->id])
            ->assertOk();

        // A staff member from another store is rejected → unassigned.
        $this->assertNull($req->fresh()->assigned_staff_id);
    }

    public function test_board_cancel_action_removes_it_from_the_columns(): void
    {
        [$owner, $store] = $this->store();
        $req = ServiceRequest::create(['store_id' => $store->id, 'type' => 'staff_call', 'status' => 'open']);

        $this->actingAs($owner)
            ->postJson(route('hotel.requests.status'), ['id' => $req->id, 'status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('cancelled', $req->fresh()->status);

        $json = $this->actingAs($owner)->getJson(route('hotel.requests'))->json();
        $this->assertNotContains($req->id, array_column($json['open'], 'id'));
    }

    public function test_board_html_wires_the_polling_component(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->get(route('hotel.requests'))->assertOk()->assertSeeHtml('hotelRequestsBoard(');
    }

    public function test_department_editor_labels_an_inactive_mapped_station(): void
    {
        [$owner, $store] = $this->store();
        $station = KitchenStation::create(['store_id' => $store->id, 'name' => 'OldHousekeeping', 'active' => false, 'position' => 1]);
        StoreOption::updateStoreOption($store->id, 'hotel_settings', json_encode(['departments' => ['housekeeping' => $station->id]]));

        $this->actingAs($owner)->get(route('hotel.info'))
            ->assertOk()->assertSee('OldHousekeeping')->assertSeeHtml('('.___('inactive').')');
    }

    public function test_department_editor_shows_empty_state_when_no_stations(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->get(route('hotel.info'))
            ->assertOk()
            ->assertSee(___('Departments are managed as kitchen stations. Create a station first, then route request types to it here.'));
    }

    // -------------------------------------------------------------- B6 hotel settings

    private function saveSettings(User $owner, array $extra = []): TestResponse
    {
        return $this->actingAs($owner)->post(route('hotel.info.save'), array_merge(['wifi_name' => 'Net'], $extra));
    }

    public function test_tray_charge_round_trips_flat_and_percent(): void
    {
        [$owner, $store] = $this->store();

        $this->saveSettings($owner, ['hotel_tray_charge' => '10%'])->assertRedirect();
        $this->assertSame('10%', (string) StoreSettings::legacy((int) $store->id, 'hotel_tray_charge'));

        $this->saveSettings($owner, ['hotel_tray_charge' => '5'])->assertRedirect();
        $this->assertSame('5', (string) StoreSettings::legacy((int) $store->id, 'hotel_tray_charge'));
    }

    public function test_invalid_tray_charge_is_rejected(): void
    {
        [$owner, $store] = $this->store();

        $this->saveSettings($owner, ['hotel_tray_charge' => 'abc'])
            ->assertSessionHasErrors('hotel_tray_charge');

        $this->assertNull(StoreSettings::legacy((int) $store->id, 'hotel_tray_charge'));
    }

    public function test_token_binding_flag_round_trips(): void
    {
        [$owner, $store] = $this->store();

        $this->saveSettings($owner, ['token_required' => '1'])->assertRedirect();
        $this->assertSame('1', (string) StoreSettings::legacy((int) $store->id, 'hotel_charge_room_token_required'));

        $this->saveSettings($owner, ['token_required' => '0'])->assertRedirect();
        $this->assertSame('0', (string) StoreSettings::legacy((int) $store->id, 'hotel_charge_room_token_required'));
    }

    public function test_pms_credentials_are_stored_and_never_re_displayed(): void
    {
        [$owner, $store] = $this->store();

        $this->saveSettings($owner, [
            'pms_provider' => 'mews', 'pms_base_url' => 'https://api.mews.com',
            'pms_service_id' => 'SVC1', 'pms_client_token' => 'CT-SECRET', 'pms_access_token' => 'AT-SECRET',
        ])->assertRedirect();

        $cred = Credential::where('store_id', $store->id)->where('feature', 'pms')->first();
        $this->assertNotNull($cred);
        $this->assertSame('mews', $cred->provider);
        $this->assertSame('CT-SECRET', $cred->secrets['client_token']);
        $this->assertSame('AT-SECRET', $cred->secrets['access_token']);
        $this->assertSame('https://api.mews.com', $cred->secrets['base_url']);

        // Re-render must NOT leak the secrets, but shows the non-secret config.
        $res = $this->actingAs($owner)->get(route('hotel.info'))->assertOk();
        $res->assertDontSee('CT-SECRET');
        $res->assertDontSee('AT-SECRET');
        $res->assertSee('https://api.mews.com');
    }

    public function test_blank_pms_tokens_keep_the_stored_secret(): void
    {
        [$owner, $store] = $this->store();
        $this->saveSettings($owner, ['pms_provider' => 'mews', 'pms_client_token' => 'KEEPME'])->assertRedirect();

        // Re-save changing only the base URL, tokens left blank → secret preserved.
        $this->saveSettings($owner, ['pms_provider' => 'mews', 'pms_base_url' => 'https://x.test'])->assertRedirect();

        $cred = Credential::where('store_id', $store->id)->where('feature', 'pms')->first();
        $this->assertSame('KEEPME', $cred->secrets['client_token']);
        $this->assertSame('https://x.test', $cred->secrets['base_url']);
    }

    public function test_mode_off_warning_shows_open_folio_count(): void
    {
        [$owner, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $this->chargedStay($store, $this->room($store, 111), 40.00);

        $this->actingAs($owner)->get(route('hotel.info'))
            ->assertOk()
            ->assertSee(___('Review stays'))
            ->assertSee('USD 40.00');
    }

    // -------------------------------------------------------------- B7 alert channels

    public function test_alert_channel_defaults_notif_on_others_off(): void
    {
        [$owner, $store] = $this->store();

        $alerts = $this->actingAs($owner)->get(route('hotel.info'))->assertOk()->viewData('alerts');

        $this->assertTrue($alerts['notif']);
        $this->assertFalse($alerts['whatsapp']);
        $this->assertFalse($alerts['push']);
    }

    public function test_alert_channel_toggles_persist(): void
    {
        [$owner, $store] = $this->store();

        $this->saveSettings($owner, ['alerts_notif' => '1', 'alerts_whatsapp' => '1', 'alerts_push' => '0'])->assertRedirect();

        // The contract keys lane C reads are written by the existing save action.
        $val = fn ($k) => (string) StoreSettings::legacy((int) $store->id, $k);
        $this->assertSame('1', $val('hotel_alerts_notif'));
        $this->assertSame('1', $val('hotel_alerts_whatsapp'));
        $this->assertSame('0', $val('hotel_alerts_push'));
    }

    public function test_pms_base_url_must_be_public_https(): void
    {
        // P6-68: rejected at save time (loopback, metadata, private-resolving, IPv6 local, plain http).
        SsrfGuard::$resolver = static fn (string $h): array => $h === 'internal.example' ? ['10.0.0.5'] : ['93.184.216.34'];
        [$owner, $store] = $this->store();
        foreach (['https://127.0.0.1', 'https://169.254.169.254', 'https://internal.example', 'https://[::1]', 'https://[fc00::1]', 'http://api.mews.com'] as $url) {
            $this->saveSettings($owner, ['pms_provider' => 'mews', 'pms_base_url' => $url])->assertSessionHasErrors('pms_base_url');
        }
        $this->assertNull(Credential::where('store_id', $store->id)->where('feature', 'pms')->first());

        $this->saveSettings($owner, ['pms_provider' => 'mews', 'pms_base_url' => 'https://api.mews.com'])->assertSessionHasNoErrors();
    }
}
