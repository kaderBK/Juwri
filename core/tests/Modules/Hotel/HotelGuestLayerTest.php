<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\TableKeyGuard;
use Juwri\Modules\Hotel\Http\Controllers\HotelGuestController;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WIRING-RUN D1/D2.
 *
 * The hotel guest layer renders inside every storefront template. Two spec rows were
 * unbuilt: H4 (scheduled room service / breakfast pre-order) and H5 (a hotel services
 * sheet on the room-QR menu itself, rather than only on the separate guest-info page).
 *
 * The partial is rendered directly rather than through a full storefront request: the
 * layout's $settings/$activeTheme come from a view composer ViewServiceProvider only
 * registers when APP_INSTALLED is set, and it is deliberately unset in the test env.
 */
class HotelGuestLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        config(['settings.admin_allow_hotel' => true]);
    }

    /** A hotel-enabled store with one room, reachable by room QR. */
    private function hotelStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'PH'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'hotel' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'h-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Hotel R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');

        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => '204', 'label' => 'Room 204',
            'area_type' => 'room', 'key' => 'room204',
        ]);

        return [$store, $room];
    }

    /** The partial renders the room the page controller resolved (guarded lookup), passed as $table. */
    private function renderGuestLayer(Store $store, ?StoreTable $table = null, bool $roomVerified = false, bool $guestStayVerified = false): string
    {
        return Blade::render(
            "@include('store_templates._partials.hotel-guest')",
            ['store' => $store, 'table' => $table, 'roomVerified' => $roomVerified, 'guestStayVerified' => $guestStayVerified],
            deleteCachedView: true
        );
    }

    public function test_room_qr_renders_the_guest_layer_with_the_services_sheet(): void
    {
        [$store, $room] = $this->hotelStore();

        $html = $this->renderGuestLayer($store, $room);

        $this->assertStringContainsString('Room 204', $html, 'Room greeting should render for a room QR visit.');
        // D2 — the services sheet must post to the same endpoint the guest-info page uses.
        $this->assertStringContainsString(route('hotel.guest.request', $store->slug), $html);
    }

    /** D1 — the slot picker appears only when the owner enabled room_service scheduling. */
    public function test_slot_picker_follows_the_room_service_scheduling_toggle(): void
    {
        [$store, $room] = $this->hotelStore();
        $this->app['request']->merge(['table' => $room->key]);

        // Slots are generated on an interval grid; without these the day has no bookable
        // slot and the picker correctly stays hidden even with the toggle on. Options are
        // set BEFORE the first render — store_options() memoizes per request, so flipping
        // them between two renders in one test would read stale state.
        foreach ([
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 0,
            'timezone' => 'UTC',
        ] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00', 'UTC'));
        $on = $this->renderGuestLayer($store, $room);
        Carbon::setTestNow();

        $this->assertStringContainsString('mbHotelSchedAt', $on, 'Enabling room-service scheduling should surface the slot picker.');
        $this->assertStringContainsString('room_service', $on, 'The picker must request room_service slots, not the default order type.');
    }

    /**
     * C9 — while breakfast is selected the day select is LOCKED (so a day change can't
     * fetch the full dinner-inclusive list and let a "breakfast" order be placed for
     * 21:30), and a fully-booked morning shows a distinct message from a passed cutoff.
     */
    public function test_breakfast_locks_the_day_and_has_a_distinct_capacity_message(): void
    {
        [$store, $room] = $this->hotelStore();
        $this->app['request']->merge(['table' => $room->key]);

        foreach ([
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 0,
            'timezone' => 'UTC',
        ] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00', 'UTC'));
        $on = $this->renderGuestLayer($store, $room);
        Carbon::setTestNow();

        $this->assertStringContainsString('mbHotelSchedAt', $on, 'the scheduling picker must render for this setup');
        // The day select is disabled whenever breakfast is the chosen mode.
        $this->assertStringContainsString("dSel.disabled = (m === 'breakfast')", $on);
        // A distinct "fully booked" message, separate from the cutoff-passed one.
        $this->assertStringContainsString('BF.full ? L.booked', $on);
        $this->assertStringContainsString('booked:', $on);
    }

    /** The layer must render nothing at all for a non-hotel store — it ships in every theme. */
    public function test_non_hotel_store_renders_nothing(): void
    {
        [$store, $room] = $this->hotelStore();
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '0');
        $this->assertSame('', trim($this->renderGuestLayer($store, $room)));
    }

    /** A normal table visit (no room QR) must not trigger the hotel layer either. */
    public function test_non_room_visit_renders_nothing(): void
    {
        [$store] = $this->hotelStore();
        $this->app['request']->merge(['table' => 'not-a-room']);

        $this->assertSame('', trim($this->renderGuestLayer($store)));
        $plain = StoreTable::create(['store_id' => $store->id, 'table_no' => '5', 'key' => 'tbl5', 'area_type' => 'table']);
        $this->assertSame('', trim($this->renderGuestLayer($store, $plain)), 'a dine-in table is not a room');
    }

    /** J52b review S4: guessing room keys on the guest-info page is cut off per store + IP (no room, no Wi-Fi). */
    public function test_guessing_room_keys_on_the_guest_info_page_is_cut_off(): void
    {
        config(['abuse.table_key_misses_per_minute' => 2, 'abuse.table_key_misses_per_minute_network' => 2]);
        [$store, $room] = $this->hotelStore();
        // One fresh request per call, as in real traffic (a miss counts once per request).
        $info = function (string $key) use ($store): array {
            $request = Request::create('/hotel/'.$store->slug.'/info?table='.urlencode($key));
            $this->app->instance('request', $request);

            return app(HotelGuestController::class)->info($request, $store->slug)->getData();
        };

        $this->assertSame($room->id, $info($room->key)['room']?->id, 'a real key resolves before any miss');
        $this->assertNull($info('guess-1')['room']);
        $this->assertNull($info('guess-2')['room']);
        $this->assertNull($info($room->key)['room'], 'over the limit even a real key answers unknown');
        $this->assertFalse($info($room->key)['wifiVisible']);

        TableKeyGuard::clear((int) $store->id);
        $this->assertSame($room->id, $info($room->key)['room']?->id);
    }

    /** An array `table[]=` on the guest-info page is no room (and no 500). */
    public function test_an_array_table_on_the_guest_info_page_is_no_room(): void
    {
        [$store] = $this->hotelStore();

        $data = app(HotelGuestController::class)
            ->info(Request::create('/hotel/'.$store->slug.'/info?table[]=x'), $store->slug)
            ->getData();

        $this->assertNull($data['room']);
        $this->assertFalse($data['wifiVisible']);
    }

    /** A guest cut off by the key guard cannot file a service request without its room: refused, nothing stored. */
    public function test_a_blocked_guest_service_request_is_refused_not_filed_without_a_room(): void
    {
        config(['abuse.table_key_misses_per_minute' => 1, 'abuse.table_key_misses_per_minute_network' => 1]);
        [$store, $room] = $this->hotelStore();
        $guess = Request::create('/hotel/'.$store->slug.'/info?table=guess');
        $this->app->instance('request', $guess);
        app(HotelGuestController::class)->info($guess, $store->slug);

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class])
            ->postJson(route('hotel.guest.request', $store->slug), ['table' => $room->key, 'type' => ServiceRequest::TYPES[0]])
            ->assertStatus(429)->assertJson(['ok' => false]);

        $this->assertSame(0, ServiceRequest::where('store_id', $store->id)->count());
    }

    /** J52b review P0 + SF2: the guest's name and the folio link need the room token AND the current stay's token. */
    public function test_the_guest_name_and_folio_link_need_the_current_stay_token(): void
    {
        [$store, $room] = $this->hotelStore();
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        $html = $this->renderGuestLayer($store, $room);
        $this->assertStringContainsString('id="mbHotelChip"', $html, 'the room layer itself still renders');
        $this->assertStringNotContainsString('Sam Carter', $html);
        $this->assertStringNotContainsString((string) $stay->token, $html, 'no folio link without a verified token');

        // SF2: the room token alone (permanent, every past guest has it) shows no name, no prefill, no folio link.
        $roomOnly = $this->renderGuestLayer($store, $room, true);
        $this->assertStringContainsString('id="mbHotelChargeToggle"', $roomOnly, 'charge-to-room stays offered');
        $this->assertStringNotContainsString('Sam Carter', $roomOnly);
        $this->assertStringNotContainsString((string) $stay->token, $roomOnly);

        // The page controller computes both flags once (StoreController); the partial only reads them.
        $verified = $this->renderGuestLayer($store, $room, true, true);
        $this->assertStringContainsString('Sam Carter', $verified);
        $this->assertStringContainsString((string) $stay->token, $verified);

        // The stay flag never stands in for the room token.
        $stayOnly = $this->renderGuestLayer($store, $room, false, true);
        $this->assertStringNotContainsString('Sam Carter', $stayOnly);
    }

    /** J52b review P0 + SF2: through the real page, a room id never resolves a room; name only with room + stay tokens. */
    public function test_the_storefront_page_never_resolves_a_room_by_id_or_leaks_the_guest(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        [$store, $room] = $this->hotelStore();
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $rt = app(RoomCharge::class)->mintToken($room);

        $byId = $this->get('/'.$store->slug.'?table='.$room->id)->assertOk();
        $byId->assertDontSee('Sam Carter')->assertDontSee($room->key)->assertDontSee('id="mbHotelChip"', false);

        $byKey = $this->get('/'.$store->slug.'?table='.$room->key)->assertOk();
        $byKey->assertSee('id="mbHotelChip"', false)->assertDontSee('Sam Carter');

        // SF2: the room token alone shows no name and no folio link.
        $roomOnly = $this->get('/'.$store->slug.'?table='.$room->key.'&rt='.$rt)->assertOk();
        $roomOnly->assertViewHas('roomVerified', true)->assertViewHas('guestStayVerified', false)
            ->assertDontSee('Sam Carter')->assertDontSee((string) $stay->token);

        // With the current stay's token (stashed by the folio link) the guest sees their name and bill link.
        $this->withSession(['hotel_stay_token' => (string) $stay->token])
            ->get('/'.$store->slug.'?table='.$room->key.'&rt='.$rt)->assertOk()
            ->assertViewHas('guestStayVerified', true)->assertSee('Sam Carter')->assertSee((string) $stay->token, false);
    }

    /** SF2: a previous stay's token in the session never shows the current guest. */
    public function test_a_previous_stays_token_never_shows_the_current_guest(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        [$store, $room] = $this->hotelStore();
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        $old = HotelFolioService::checkIn($store->id, $room, 'Pat Former', '555-0');
        $old->forceFill(['status' => 'checked_out', 'checked_out_at' => now()])->save();
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $rt = app(RoomCharge::class)->mintToken($room);

        $this->withSession(['hotel_stay_token' => (string) $old->token])
            ->get('/'.$store->slug.'?table='.$room->key.'&rt='.$rt)->assertOk()
            ->assertViewHas('guestStayVerified', false)
            ->assertDontSee('Sam Carter')->assertDontSee((string) $stay->token)->assertDontSee('Pat Former');
    }

    /** SF2: opening the folio link stashes the active stay's token; a checked-out stay's link does not. */
    public function test_the_folio_link_stashes_only_an_active_stay_token(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        [$store, $room] = $this->hotelStore();
        $old = HotelFolioService::checkIn($store->id, $room, 'Pat Former', '555-0');
        $old->forceFill(['status' => 'checked_out', 'checked_out_at' => now()])->save();

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $old->token]))->assertOk();
        $this->assertNull(session('hotel_stay_token'), 'a checked-out stay is not stashed');

        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))->assertOk();
        $this->assertSame((string) $stay->token, session('hotel_stay_token'));
    }

    /** Round 5 note 1: a hotel without the table_qr entitlement still resolves its rooms (guarded) and keeps the panel. */
    public function test_a_hotel_without_table_qr_keeps_its_room_panel(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        [$store, $room] = $this->hotelStore(); // plan has hotel but not table_qr
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        $this->get('/'.$store->slug.'?table='.$room->key)->assertOk()->assertSee('id="mbHotelChip"', false);
        // ...but a dine-in table key does not resolve without table_qr.
        $plain = StoreTable::create(['store_id' => $store->id, 'table_no' => '5', 'key' => 'tbl5', 'area_type' => 'table']);
        $this->get('/'.$store->slug.'?table='.$plain->key)->assertOk()->assertViewHas('table', null);
    }

    /** Round 5 BLOCKER 2 + SF2: Aurelia shows the occupant's statement / name / balance / folio link only with room + stay tokens. */
    public function test_aurelia_shows_the_folio_only_for_a_verified_room(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        [$store, $room] = $this->hotelStore();
        StoreOption::updateStoreOption($store->id, 'store_template', 'aurelia');
        $old = HotelFolioService::checkIn($store->id, $room, 'Pat Former', '555-0');
        $old->forceFill(['status' => 'checked_out', 'checked_out_at' => now()])->save();
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $rt = app(RoomCharge::class)->mintToken($room);

        $keyOnly = $this->get('/'.$store->slug.'?table='.$room->key)->assertOk();
        $keyOnly->assertDontSee('Sam Carter')->assertDontSee((string) $stay->token);

        $byId = $this->get('/'.$store->slug.'?table='.$room->id)->assertOk();
        $byId->assertDontSee('Sam Carter')->assertDontSee((string) $stay->token);

        // SF2: the room token alone, or with a previous stay's token, shows the sample panel only.
        $this->get('/'.$store->slug.'?table='.$room->key.'&rt='.$rt)->assertOk()
            ->assertDontSee('Sam Carter')->assertDontSee((string) $stay->token);
        $this->withSession(['hotel_stay_token' => (string) $old->token])
            ->get('/'.$store->slug.'?table='.$room->key.'&rt='.$rt)->assertOk()
            ->assertDontSee('Sam Carter')->assertDontSee((string) $stay->token);

        $this->withSession(['hotel_stay_token' => (string) $stay->token])
            ->get('/'.$store->slug.'?table='.$room->key.'&rt='.$rt)->assertOk()
            ->assertSee('Sam Carter')->assertSee((string) $stay->token, false);
    }

    /** Round 5 note 3: the request chips show the CURRENT stay's requests only. */
    public function test_service_request_chips_are_scoped_to_the_current_stay(): void
    {
        [$store, $room] = $this->hotelStore();
        $old = HotelFolioService::checkIn($store->id, $room, 'Old Guest', '555-0');
        ServiceRequest::create(['store_id' => $store->id, 'table_id' => $room->id, 'stay_id' => $old->id, 'type' => 'housekeeping', 'status' => 'open']);
        HotelFolioService::checkOut($old);
        $new = HotelFolioService::checkIn($store->id, $room, 'New Guest', '555-1');

        $this->assertCount(0, app(RoomCharge::class)->serviceRequests((int) $store->id, (int) $room->id, 6), "the previous occupant's request is not shown");

        ServiceRequest::create(['store_id' => $store->id, 'table_id' => $room->id, 'stay_id' => $new->id, 'type' => 'amenity', 'status' => 'open']);
        $this->assertSame(['amenity'], app(RoomCharge::class)->serviceRequests((int) $store->id, (int) $room->id, 6)->pluck('type')->all());
    }
}
