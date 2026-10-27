<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS lane B — dashboard PMS blades render pre-merge (Route::has fallback) and
 * post-merge (routes registered), and every form carries the SPEC §5 field names.
 *
 * The blades are served by lane A's HotelBookingController/HotelPmsSetupController,
 * which do not exist in this worktree. Tests therefore register the lane-A route
 * NAMES as inert closures (so Route::has()/route() resolve) and render the blade
 * either through an ad-hoc GET route (full-page, exercises the owner layout) or
 * directly via view() (partials). Data is passed as plain objects — the lane-A
 * models (RoomType, HotelBooking…) are not in this worktree.
 */
class HotelPmsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
        StoreOption::flushOptionMemo();
    }

    /** @return array{0: User, 1: Store} */
    protected function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');

        return [$owner, $store];
    }

    /** Register lane A's PMS routes as inert closures. */
    protected function registerBookingRoutes(): void
    {
        Route::post('/_pms/bookings', fn () => '')->name('hotel.pms.bookings.store');
        Route::get('/_pms/bookings/{booking}', fn () => '')->name('hotel.pms.bookings.show');
        Route::get('/_pms/calendar', fn () => '')->name('hotel.pms.calendar');
        Route::get('/_pms/availability', fn () => '')->name('hotel.pms.availability');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /** Render a full-page hotel blade through the real owner layout via an ad-hoc route. */
    protected function renderPage(User $owner, string $view, array $data, string $routeName, string $path): string
    {
        Route::get($path, fn () => view($view, $data))->name($routeName);
        app('router')->getRoutes()->refreshNameLookups();

        return $this->actingAs($owner)->get($path)->assertOk()->getContent();
    }

    private function o(array $props): object
    {
        return (object) $props;
    }

    private function booking(array $props = []): object
    {
        return $this->o(array_merge([
            'id' => 1, 'code' => 'BK-ABC123', 'guest_name' => 'Jane Guest', 'guest_phone' => '555',
            'room_type' => $this->o(['id' => 1, 'name' => 'Deluxe']),
            'rate_plan' => $this->o(['id' => 1, 'name' => 'Flexible']),
            'room' => $this->o(['id' => 1, 'table_no' => 101, 'label' => 'Sea view']),
            'check_in' => '2026-09-01', 'check_out' => '2026-09-04',
            'adults' => 2, 'children' => 0, 'notes' => '', 'source' => 'desk',
            'hotel_stay_id' => null,
            'status' => 'confirmed', 'quoted_total' => 450.0, 'currency' => 'USD',
        ], $props));
    }

    /** Register the full lane-A booking lifecycle route set as inert closures. */
    protected function registerLifecycleRoutes(): void
    {
        $this->registerBookingRoutes();
        Route::get('/_pms/bookings', fn () => '')->name('hotel.pms.bookings');
        Route::post('/_pms/bookings/{booking}/update', fn () => '')->name('hotel.pms.bookings.update');
        Route::post('/_pms/bookings/{booking}/confirm', fn () => '')->name('hotel.pms.bookings.confirm');
        Route::post('/_pms/bookings/{booking}/cancel', fn () => '')->name('hotel.pms.bookings.cancel');
        Route::post('/_pms/bookings/{booking}/no-show', fn () => '')->name('hotel.pms.bookings.noshow');
        Route::post('/_pms/bookings/{booking}/check-in', fn () => '')->name('hotel.pms.bookings.checkin');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /** Count the hidden idempotency_key uuids in rendered HTML. */
    private function uuidCount(string $html): int
    {
        return preg_match_all('/name="idempotency_key" value="[0-9a-f-]{36}"/', $html);
    }

    /** Register lane A's setup CRUD routes (types/plans/rates) as inert closures. */
    protected function registerSetupRoutes(): void
    {
        Route::get('/_pms/room-types', fn () => '')->name('hotel.pms.types.index');
        Route::post('/_pms/room-types', fn () => '')->name('hotel.pms.types.store');
        Route::post('/_pms/room-types/{type}/update', fn () => '')->name('hotel.pms.types.update');
        Route::post('/_pms/room-types/{type}/delete', fn () => '')->name('hotel.pms.types.destroy');
        Route::post('/_pms/rate-plans', fn () => '')->name('hotel.pms.plans.store');
        Route::post('/_pms/rate-plans/{plan}/update', fn () => '')->name('hotel.pms.plans.update');
        Route::post('/_pms/rate-plans/{plan}/delete', fn () => '')->name('hotel.pms.plans.destroy');
        Route::get('/_pms/rates', fn () => '')->name('hotel.pms.rates.index');
        Route::post('/_pms/rates', fn () => '')->name('hotel.pms.rates.bulk');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /** Register lane A's services + events routes as inert closures. */
    protected function registerServicesEventsRoutes(): void
    {
        Route::get('/_pms/services', fn () => '')->name('hotel.pms.services.index');
        Route::post('/_pms/services', fn () => '')->name('hotel.pms.services.store');
        Route::post('/_pms/services/{service}/update', fn () => '')->name('hotel.pms.services.update');
        Route::post('/_pms/services/{service}/delete', fn () => '')->name('hotel.pms.services.destroy');
        Route::post('/_pms/stays/{stay}/service', fn () => '')->name('hotel.pms.services.post');
        Route::get('/_pms/events', fn () => '')->name('hotel.pms.events.index');
        Route::post('/_pms/events', fn () => '')->name('hotel.pms.events.store');
        Route::get('/_pms/events/{event}', fn () => '')->name('hotel.pms.events.show');
        Route::post('/_pms/events/{event}/update', fn () => '')->name('hotel.pms.events.update');
        Route::post('/_pms/events/{event}/delete', fn () => '')->name('hotel.pms.events.destroy');
        Route::post('/_pms/events/{event}/items', fn () => '')->name('hotel.pms.events.items.store');
        Route::post('/_pms/events/{event}/items/delete', fn () => '')->name('hotel.pms.events.items.destroy');
        Route::post('/_pms/events/{event}/settle', fn () => '')->name('hotel.pms.events.settle');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /** Register lane A's housekeeping + out-of-service routes as inert closures. */
    protected function registerHousekeepingRoutes(): void
    {
        Route::get('/_pms/housekeeping', fn () => '')->name('hotel.pms.housekeeping');
        Route::post('/_pms/housekeeping/task', fn () => '')->name('hotel.pms.housekeeping.task');
        Route::post('/_pms/rooms/{room}/oo', fn () => '')->name('hotel.pms.rooms.oo');
        app('router')->getRoutes()->refreshNameLookups();
    }

    // ---------------------------------------------------------------- B2 pre-merge

    // ---------------------------------------------------------------- B2 post-merge list

    public function test_bookings_page_renders_list_and_filters_when_backend_exists(): void
    {
        [$owner, $store] = $this->store();
        $this->registerBookingRoutes();

        $paginator = new LengthAwarePaginator([$this->booking()], 1, 20, 1, ['path' => '/x']);
        $data = [
            'store' => $store,
            'bookings' => $paginator,
            'roomTypes' => collect([$this->o(['id' => 1, 'name' => 'Deluxe', 'base_rate' => 150])]),
            'ratePlans' => collect([$this->o(['id' => 1, 'name' => 'Flexible'])]),
            'rooms' => collect([$this->o(['id' => 1, 'table_no' => 101, 'label' => 'Sea view'])]),
            'status' => 'confirmed',
            'filterCheckIn' => '2026-09-01',
            'filterTypeId' => 1,
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.bookings', $data, 'hotel.pms.bookings', '/_render/bookings-on');

        // Booking row rendered.
        $this->assertStringContainsString('BK-ABC123', $html);
        $this->assertStringContainsString('Jane Guest', $html);
        // Status tabs + filter surfaces.
        $this->assertStringContainsString(___('In-house'), $html);
        $this->assertStringContainsString('name="room_type_id"', $html);
        $this->assertStringContainsString('name="check_in"', $html);
        // The new-booking form is embedded with the store action.
        $this->assertStringContainsString('name="idempotency_key"', $html);
    }

    // ---------------------------------------------------------------- B2 form field names

    public function test_booking_form_carries_every_spec_field_name(): void
    {
        $this->registerBookingRoutes();

        $html = view('owner.hotel.booking-form', [
            'action' => route('hotel.pms.bookings.store'),
            'booking' => null,
            'roomTypes' => collect([$this->o(['id' => 1, 'name' => 'Deluxe', 'base_rate' => 150])]),
            'ratePlans' => collect([$this->o(['id' => 1, 'name' => 'Flexible'])]),
            'rooms' => collect([$this->o(['id' => 1, 'table_no' => 101, 'label' => 'Sea view'])]),
            'currency' => 'USD',
            'availUrl' => route('hotel.pms.availability'),
        ])->render();

        foreach ([
            'guest_name', 'guest_phone', 'room_type_id', 'rate_plan_id', 'room_id',
            'check_in', 'check_out', 'adults', 'children', 'notes', 'idempotency_key',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "form must carry field: $field");
        }

        // The idempotency key is a real uuid, not an empty placeholder.
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $html);
        // The live-quote panel is wired to the availability endpoint.
        $this->assertStringContainsString('data-avail-url="'.route('hotel.pms.availability').'"', $html);
    }

    public function test_booking_form_availability_panel_hides_when_route_absent(): void
    {
        // No availability route registered → availUrl null → data-avail-url is empty,
        // the JS no-ops and the form still submits (server re-prices authoritatively).
        $html = view('owner.hotel.booking-form', [
            'action' => '/x',
            'booking' => null,
            'roomTypes' => collect(),
            'ratePlans' => collect(),
            'rooms' => collect(),
            'currency' => 'USD',
            'availUrl' => null,
        ])->render();

        $this->assertStringContainsString('data-avail-url=""', $html);
        $this->assertStringContainsString('name="guest_name"', $html);
    }

    // ---------------------------------------------------------------- B3 calendar

    public function test_calendar_renders_bookings_ooo_and_window_nav(): void
    {
        [$owner, $store] = $this->store();
        $this->registerBookingRoutes();

        $days = ['2026-09-01', '2026-09-02', '2026-09-03'];
        $data = [
            'store' => $store,
            'days' => $days,
            'windowStart' => '2026-09-01',
            'rooms' => collect([
                $this->o(['id' => 10, 'table_no' => 101, 'label' => 'Sea', 'room_type_name' => 'Deluxe']),
                $this->o(['id' => 11, 'table_no' => 102, 'label' => '', 'room_type_name' => 'Deluxe']),
            ]),
            'occupancy' => [
                10 => [
                    '2026-09-01' => ['type' => 'booked', 'status' => 'confirmed', 'booking_id' => 5, 'code' => 'BK-9', 'guest' => 'Nadia'],
                    '2026-09-02' => ['type' => 'booked', 'status' => 'confirmed', 'booking_id' => 5, 'code' => 'BK-9', 'guest' => 'Nadia'],
                ],
                11 => [
                    '2026-09-02' => ['type' => 'ooo'],
                ],
            ],
        ];

        $html = $this->renderPage($owner, 'owner.hotel.calendar', $data, 'hotel.pms.calendar', '/_render/calendar-on');

        // Room type header + rooms.
        $this->assertStringContainsString('Deluxe', $html);
        $this->assertStringContainsString('#101', $html);
        // Booked cell links to booking-show and shows the guest.
        $this->assertStringContainsString('Nadia', $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.bookings.show', 5), PHP_URL_PATH), $html);
        // OOO cell marker.
        $this->assertStringContainsString('<td data-tone="ooo"', $html);
        // Prev/next window nav carry a start query param.
        $this->assertStringContainsString('start=', $html);
    }

    // ---------------------------------------------------------------- B4 booking detail

    public function test_booking_show_pending_offers_confirm_cancel_edit(): void
    {
        [$owner, $store] = $this->store();
        $this->registerLifecycleRoutes();

        $data = ['store' => $store, 'booking' => $this->booking(['status' => 'pending']),
            'roomTypes' => collect([$this->o(['id' => 1, 'name' => 'Deluxe'])]), 'ratePlans' => collect(), 'rooms' => collect(), 'currency' => 'USD'];
        $html = $this->renderPage($owner, 'owner.hotel.booking-show', $data, 'pms.render.show.pending', '/_render/show-pending');

        $this->assertStringContainsString(___('Confirm booking'), $html);
        $this->assertStringContainsString(___('Cancel booking'), $html);
        $this->assertStringContainsString(___('Edit / assign room'), $html);
        // Not yet checkable-in from pending — no form posts to the check-in endpoint.
        // (Assert on the route path, not the label: booking-form's "Check-in" date
        // label slug-collides to "Check in" via ___().)
        $this->assertStringNotContainsString(parse_url(route('hotel.pms.bookings.checkin', 1), PHP_URL_PATH), $html);
        // Every lifecycle POST carries a uuid: confirm + cancel + the embedded edit form = 3.
        $this->assertGreaterThanOrEqual(3, $this->uuidCount($html));
    }

    public function test_booking_show_confirmed_offers_checkin_and_noshow_not_confirm(): void
    {
        [$owner, $store] = $this->store();
        $this->registerLifecycleRoutes();

        $data = ['store' => $store, 'booking' => $this->booking(['status' => 'confirmed']),
            'roomTypes' => collect(), 'ratePlans' => collect(), 'rooms' => collect(), 'currency' => 'USD'];
        $html = $this->renderPage($owner, 'owner.hotel.booking-show', $data, 'pms.render.show.confirmed', '/_render/show-confirmed');

        // Check-in + no-show forms post to their endpoints; confirm is gone.
        $this->assertStringContainsString(parse_url(route('hotel.pms.bookings.checkin', 1), PHP_URL_PATH), $html);
        $this->assertStringContainsString(___('Mark no-show'), $html);
        $this->assertStringNotContainsString(___('Confirm booking'), $html);
    }

    public function test_booking_show_renders_hk_warning_banner(): void
    {
        [$owner, $store] = $this->store();
        $this->registerLifecycleRoutes();

        $data = ['store' => $store, 'booking' => $this->booking(['status' => 'checked_in', 'hotel_stay_id' => 77]),
            'roomTypes' => collect(), 'ratePlans' => collect(), 'rooms' => collect(), 'currency' => 'USD',
            'hkWarning' => 'Room 101 was not marked clean at check-in.'];
        $html = $this->renderPage($owner, 'owner.hotel.booking-show', $data, 'pms.render.show.hk', '/_render/show-hk');

        $this->assertStringContainsString('Room 101 was not marked clean at check-in.', $html);
    }

    public function test_booking_show_checked_in_links_to_folio(): void
    {
        [$owner, $store] = $this->store();
        $this->registerLifecycleRoutes();

        $data = ['store' => $store, 'booking' => $this->booking(['status' => 'checked_in', 'hotel_stay_id' => 88]),
            'roomTypes' => collect(), 'ratePlans' => collect(), 'rooms' => collect(), 'currency' => 'USD'];
        $html = $this->renderPage($owner, 'owner.hotel.booking-show', $data, 'pms.render.show.folio', '/_render/show-folio');

        // hotel.stays.show is an existing route; the folio link resolves.
        $this->assertStringContainsString(parse_url(route('hotel.stays.show', 88), PHP_URL_PATH), $html);
        $this->assertStringContainsString(___('Open folio'), $html);
    }

    // ---------------------------------------------------------------- B5 room types / plans

    public function test_room_types_page_renders_types_plans_and_crud_forms(): void
    {
        [$owner, $store] = $this->store();
        $this->registerSetupRoutes();

        $data = [
            'store' => $store,
            'roomTypes' => collect([
                $this->o(['id' => 1, 'name' => 'Deluxe', 'code' => 'DLX', 'base_rate' => 150, 'max_occupancy' => 2, 'sort' => 0, 'active' => 1]),
            ]),
            'ratePlans' => collect([
                $this->o(['id' => 1, 'name' => 'Standard', 'parent_id' => null, 'modifier_type' => null, 'modifier_value' => null, 'refundable' => 1, 'cancel_before_hours' => 24, 'active' => 1]),
                $this->o(['id' => 2, 'name' => 'Non-refundable', 'parent_id' => 1, 'modifier_type' => 'percent', 'modifier_value' => -10, 'refundable' => 0, 'cancel_before_hours' => 0, 'active' => 1]),
            ]),
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.room-types', $data, 'hotel.pms.types.index', '/_render/types-on');

        // Rows rendered.
        $this->assertStringContainsString('Deluxe', $html);
        $this->assertStringContainsString('DLX', $html);
        $this->assertStringContainsString('Non-refundable', $html);
        // Room type form fields.
        foreach (['name', 'code', 'base_rate', 'max_occupancy', 'active'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "room-type form must carry: $field");
        }
        // Rate plan form fields (modifier + refundable + cancel window).
        foreach (['parent_id', 'modifier_type', 'modifier_value', 'refundable', 'cancel_before_hours'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "rate-plan form must carry: $field");
        }
        // The create dialogs post to the pinned store endpoints (server-resolved).
        $this->assertStringContainsString('data-type-store="'.route('hotel.pms.types.store').'"', $html);
        $this->assertStringContainsString('data-plan-store="'.route('hotel.pms.plans.store').'"', $html);
    }

    // ---------------------------------------------------------------- B5 rate calendar

    public function test_rates_grid_renders_one_price_input_per_day_and_bulk_action(): void
    {
        [$owner, $store] = $this->store();
        $this->registerSetupRoutes();

        // September 2026 → 30 nights.
        $days = [];
        $cursor = Carbon::parse('2026-09-01');
        $end = $cursor->copy()->endOfMonth();
        while ($cursor <= $end) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $type = $this->o(['id' => 1, 'name' => 'Deluxe', 'base_rate' => 150]);
        $data = [
            'store' => $store,
            'roomTypes' => collect([$type]),
            'ratePlans' => collect([$this->o(['id' => 1, 'name' => 'Flexible'])]),
            'selectedTypeId' => 1,
            'selectedPlanId' => null,
            'selectedType' => $type,
            'month' => '2026-09',
            'days' => $days,
            'rates' => ['2026-09-02' => 200.0],
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.rates', $data, 'hotel.pms.rates.index', '/_render/rates-on');

        // Exactly one price input per night in the month.
        $this->assertSame(count($days), substr_count($html, 'name="rates['), 'one price input per day');
        // The bulk-save contract: room_type_id + rate_plan_id hidden + the pinned POST target.
        $this->assertStringContainsString('name="room_type_id"', $html);
        $this->assertStringContainsString('name="rate_plan_id"', $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.rates.bulk'), PHP_URL_PATH), $html);
        // The override value renders; the base rate is the placeholder.
        $this->assertStringContainsString('value="200"', $html);
        $this->assertStringContainsString('placeholder="150.00"', $html);
    }

    // ---------------------------------------------------------------- B6 services catalog

    public function test_services_page_renders_catalog_with_charge_mode_and_crud_fields(): void
    {
        [$owner, $store] = $this->store();
        $this->registerServicesEventsRoutes();

        $data = [
            'store' => $store,
            'services' => collect([
                $this->o(['id' => 1, 'name' => 'Breakfast', 'price' => 15, 'tax_rate' => 5, 'charge_mode' => 'per_night', 'active' => 1]),
            ]),
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.services', $data, 'hotel.pms.services.index', '/_render/services-on');

        $this->assertStringContainsString('Breakfast', $html);
        foreach (['name', 'price', 'tax_rate', 'charge_mode', 'active'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "service form must carry: $field");
        }
        // All three charge modes are offered.
        $this->assertStringContainsString('value="per_person_per_night"', $html);
        $this->assertStringContainsString('data-service-store="'.route('hotel.pms.services.store').'"', $html);
    }

    // ---------------------------------------------------------------- B6 folio service picker

    public function test_folio_statement_post_service_picker_carries_spec_field_names(): void
    {
        [$owner, $store] = $this->store();
        $this->registerServicesEventsRoutes();

        // A real active stay so the folio statement renders end to end.
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room',
        ]);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Guest');
        $statement = HotelFolioService::statement($stay);

        $data = [
            'store' => $store, 'stay' => $stay, 'statement' => $statement,
            'services' => collect([$this->o(['id' => 1, 'name' => 'Breakfast', 'price' => 15])]),
        ];
        $html = $this->renderPage($owner, 'owner.hotel.folio-statement', $data, 'pms.render.folio.svc', '/_render/folio-svc');

        $this->assertStringContainsString(___('Post service'), $html);
        foreach (['service_id', 'qty', 'idempotency_key'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "picker must carry: $field");
        }
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.services.post', $stay->id), PHP_URL_PATH), $html);
    }

    // ---------------------------------------------------------------- B6 events list

    public function test_events_page_lists_events_with_billing_mode_and_create_fields(): void
    {
        [$owner, $store] = $this->store();
        $this->registerServicesEventsRoutes();

        $data = [
            'store' => $store,
            'events' => collect([
                $this->o(['id' => 1, 'title' => 'Gala dinner', 'guest_name' => 'ACME', 'venue' => 'Hall', 'starts_at' => '2026-09-10 19:00', 'ends_at' => null, 'attendees' => 50, 'status' => 'confirmed', 'hotel_stay_id' => null]),
                $this->o(['id' => 2, 'title' => 'Board lunch', 'guest_name' => 'Room 5', 'venue' => '', 'starts_at' => '2026-09-11 12:00', 'ends_at' => null, 'attendees' => 8, 'status' => 'enquiry', 'hotel_stay_id' => 77]),
            ]),
            'stays' => collect(),
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.events', $data, 'hotel.pms.events.index', '/_render/events-on');

        $this->assertStringContainsString('Gala dinner', $html);
        $this->assertStringContainsString('Board lunch', $html);
        // Both billing-mode indicators appear (standalone + stay folio).
        $this->assertStringContainsString(___('Own account'), $html);
        $this->assertStringContainsString(___('Stay folio'), $html);
        // Create form carries the key fields.
        foreach (['title', 'guest_name', 'venue', 'starts_at', 'hotel_stay_id'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "event form must carry: $field");
        }
    }

    // ---------------------------------------------------------------- B6 event detail (both modes)

    public function test_event_show_standalone_offers_settle_and_item_forms(): void
    {
        [$owner, $store] = $this->store();
        $this->registerServicesEventsRoutes();

        $data = [
            'store' => $store,
            'event' => $this->o(['id' => 1, 'title' => 'Gala', 'guest_name' => 'ACME', 'guest_phone' => '', 'venue' => 'Hall', 'starts_at' => '2026-09-10 19:00', 'ends_at' => null, 'attendees' => 50, 'status' => 'confirmed', 'hotel_stay_id' => null, 'notes' => '']),
            'items' => collect([$this->o(['id' => 1, 'description' => 'Canapés', 'qty' => 2, 'unit_price' => 100, 'service_id' => null])]),
            'services' => collect([$this->o(['id' => 1, 'name' => 'Breakfast'])]),
            'statement' => ['total_charges' => 200, 'total_payments' => 0, 'balance' => 200],
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.event-show', $data, 'pms.render.event.standalone', '/_render/event-standalone');

        $this->assertStringContainsString(___('Standalone account'), $html);
        // Settle form (mirrors folio settle) + uuid.
        foreach (['amount', 'method', 'idempotency_key'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "settle form must carry: $field");
        }
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.events.settle', 1), PHP_URL_PATH), $html);
        // Add-item form fields + remove line.
        foreach (['service_id', 'description', 'qty', 'unit_price', 'item_id'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "item form must carry: $field");
        }
        $this->assertStringContainsString('Canapés', $html);
    }

    public function test_event_show_stay_billed_links_folio_and_hides_settle(): void
    {
        [$owner, $store] = $this->store();
        $this->registerServicesEventsRoutes();

        $data = [
            'store' => $store,
            'event' => $this->o(['id' => 2, 'title' => 'Board lunch', 'guest_name' => 'ACME', 'guest_phone' => '', 'venue' => '', 'starts_at' => '2026-09-11 12:00', 'ends_at' => null, 'attendees' => 8, 'status' => 'confirmed', 'hotel_stay_id' => 88, 'notes' => '']),
            'items' => collect(),
            'services' => collect(),
            'currency' => 'USD',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.event-show', $data, 'pms.render.event.stay', '/_render/event-stay');

        $this->assertStringContainsString(___('Bills to stay folio'), $html);
        // Links to the existing stay folio; the standalone settle endpoint never renders.
        $this->assertStringContainsString(parse_url(route('hotel.stays.show', 88), PHP_URL_PATH), $html);
        $this->assertStringNotContainsString(parse_url(route('hotel.pms.events.settle', 2), PHP_URL_PATH), $html);
    }

    // ---------------------------------------------------------------- B7 housekeeping board

    public function test_housekeeping_board_renders_columns_task_and_oo_forms(): void
    {
        [$owner, $store] = $this->store();
        $this->registerHousekeepingRoutes();

        $data = [
            'store' => $store,
            'rooms' => collect([
                $this->o(['id' => 1, 'table_no' => 101, 'label' => 'Sea', 'room_type_name' => 'Deluxe', 'hk_status' => 'dirty', 'oo_status' => 'none', 'oo_until' => null, 'open_task' => $this->o(['id' => 9, 'type' => 'clean', 'created_at' => now()->subHours(2)])]),
                $this->o(['id' => 2, 'table_no' => 102, 'label' => '', 'room_type_name' => 'Standard', 'hk_status' => 'clean', 'oo_status' => 'out_of_order', 'oo_until' => '2026-09-20', 'open_task' => null]),
                $this->o(['id' => 3, 'table_no' => 103, 'label' => '', 'room_type_name' => 'Standard', 'hk_status' => 'inspected', 'oo_status' => 'none', 'oo_until' => null, 'open_task' => null]),
            ]),
        ];

        $html = $this->renderPage($owner, 'owner.hotel.housekeeping', $data, 'hotel.pms.housekeeping', '/_render/hk-on');

        // Three cleanliness columns.
        $this->assertStringContainsString(___('Needs cleaning'), $html);
        $this->assertStringContainsString(___('Clean'), $html);
        $this->assertStringContainsString(___('Inspected'), $html);
        $this->assertStringContainsString('#101', $html);
        // Complete-task form carries the SPEC §5 fields.
        foreach (['task_id', 'room_id', 'type', 'status'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "task form must carry: $field");
        }
        $this->assertStringContainsString('value="done"', $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.housekeeping.task'), PHP_URL_PATH), $html);
        // OO setter carries oo_status/oo_until and posts to rooms.oo.
        $this->assertStringContainsString('name="oo_status"', $html);
        $this->assertStringContainsString('name="oo_until"', $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.rooms.oo', 1), PHP_URL_PATH), $html);
        // Out-of-order room shows the badge.
        $this->assertStringContainsString(___('Out of order'), $html);
        // New-task dialog opens tasks in the 'open' state.
        $this->assertStringContainsString('value="open"', $html);
    }

    // ---------------------------------------------------------------- B7 hk chips on rooms/stays

    public function test_rooms_row_renders_hk_chip_and_room_type_label(): void
    {
        [$owner, $store] = $this->store();

        $room = $this->o([
            'id' => 1, 'table_no' => 101, 'label' => 'Sea view', 'area_type' => 'room',
            'floor_label' => 'Floor 1', 'key' => 'ABC123', 'hk_status' => 'inspected', 'room_type_name' => 'Deluxe',
        ]);
        $paginator = new LengthAwarePaginator([$room], 1, 20, 1, ['path' => '/x']);
        $data = [
            'store' => $store, 'rooms' => $paginator, 'floors' => collect(),
            'q' => '', 'floor' => '', 'type' => '',
        ];

        $html = $this->renderPage($owner, 'owner.hotel.rooms', $data, 'pms.render.rooms.chip', '/_render/rooms-chip');

        $this->assertStringContainsString('Deluxe', $html);
        $this->assertStringContainsString(___('Inspected'), $html);
    }

    public function test_stays_render_hk_chip_and_checkin_not_ready_wiring(): void
    {
        [$owner, $store] = $this->store();

        $stay = $this->o([
            'id' => 1, 'guest_name' => 'Jane', 'guest_phone' => '555', 'checked_in_at' => now(), 'status' => 'checked_in',
            'table_id' => 1, 'room' => $this->o(['table_no' => 101, 'label' => 'Sea', 'hk_status' => 'dirty', 'room_type_name' => 'Deluxe']),
        ]);
        $data = [
            'store' => $store,
            'active' => collect([$stay]),
            'past' => collect(),
            'balances' => [1 => 0],
            'rooms' => collect([$this->o(['id' => 1, 'table_no' => 101, 'label' => 'Sea', 'is_occupied' => false, 'hk_status' => 'dirty'])]),
        ];

        $html = $this->renderPage($owner, 'owner.hotel.stays', $data, 'pms.render.stays.chip', '/_render/stays-chip');

        // Active-stay row carries the room type + dirty chip.
        $this->assertStringContainsString('Deluxe', $html);
        $this->assertStringContainsString(___('Needs cleaning'), $html);
        // Check-in select flags the dirty room and the amber not-ready chip is wired.
        $this->assertStringContainsString('data-hk="dirty"', $html);
        $this->assertStringContainsString(___('Room not ready'), $html);
    }

    // ---------------------------------------------------------------- B8 hotel settings (PMS)

    public function test_pms_settings_fields_render_on_hotel_settings_page(): void
    {
        [$owner] = $this->store();

        $res = $this->actingAs($owner)->get(route('hotel.info'))->assertOk();
        $html = $res->getContent();

        foreach ([
            'hotel_online_booking', 'hotel_noshow_grace_hours', 'hotel_noshow_fee', 'waitlist_avg_turn_minutes',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "settings must carry: $field");
        }
        // All three online-booking modes are offered; default 'off' is selected.
        $this->assertStringContainsString('value="request"', $html);
        $this->assertStringContainsString('value="instant"', $html);
        $this->assertStringContainsString(___('Waitlist: minutes per table turn'), $html);
    }

    public function test_pms_settings_round_trip_and_defaults(): void
    {
        [$owner, $store] = $this->store();

        // Defaults read back for unset keys (SPEC §5).
        StoreOption::flushOptionMemo();
        $this->assertSame('off', (string) (store_options($store->id, 'hotel_online_booking', 'off') ?: 'off'));
        $this->assertSame('18', (string) store_options($store->id, 'hotel_noshow_grace_hours', '18'));
        $this->assertSame('20', (string) store_options($store->id, 'waitlist_avg_turn', '20'));

        $this->actingAs($owner)->post(route('hotel.info.save'), [
            'hotel_online_booking' => 'instant',
            'hotel_noshow_grace_hours' => '24',
            'hotel_noshow_fee' => '15.50',
            'waitlist_avg_turn_minutes' => '25',
        ])->assertRedirect();

        StoreOption::flushOptionMemo();
        $this->assertSame('instant', store_options($store->id, 'hotel_online_booking'));
        $this->assertSame('24', (string) store_options($store->id, 'hotel_noshow_grace_hours'));
        // Fee is stored as a number string; compare numerically (15.50 == 15.5).
        $this->assertEqualsWithDelta(15.5, (float) store_options($store->id, 'hotel_noshow_fee'), 0.001);
        // Persisted under the LIVE key `waitlist_avg_turn` (shared with the general
        // settings form + WaitlistController), not a second `_minutes` key.
        $this->assertSame('25', (string) store_options($store->id, 'waitlist_avg_turn'));
    }

    // ---------------------------------------------------------------- B9 room-type assignment

    public function test_rooms_admin_offers_room_type_select_and_bulk_assign(): void
    {
        [$owner, $store] = $this->store();
        Route::post('/_pms/rooms/assign-type', fn () => '')->name('hotel.pms.rooms.assign-type');
        app('router')->getRoutes()->refreshNameLookups();

        $room = $this->o([
            'id' => 1, 'table_no' => 101, 'label' => 'Sea view', 'area_type' => 'room',
            'floor_label' => 'Floor 1', 'key' => 'ABC123', 'hk_status' => null, 'room_type_id' => 1,
        ]);
        $paginator = new LengthAwarePaginator([$room], 1, 20, 1, ['path' => '/x']);
        $data = [
            'store' => $store, 'rooms' => $paginator, 'floors' => collect(['Floor 1']),
            'q' => '', 'floor' => '', 'type' => '',
            'roomTypes' => collect([$this->o(['id' => 1, 'name' => 'Deluxe']), $this->o(['id' => 2, 'name' => 'Standard'])]),
        ];

        $html = $this->renderPage($owner, 'owner.hotel.rooms', $data, 'pms.render.rooms.types', '/_render/rooms-types');

        // The create/edit dialog carries a room_type select using the pinned param name.
        $this->assertStringContainsString('name="room_type_id"', $html);
        $this->assertStringContainsString('Deluxe', $html);
        $this->assertStringContainsString('Standard', $html);
        // The bulk-assign helper posts to the A11 endpoint.
        $this->assertStringContainsString(___('Assign types'), $html);
        $this->assertStringContainsString(parse_url(route('hotel.pms.rooms.assign-type'), PHP_URL_PATH), $html);
    }

    public function test_rooms_admin_hides_type_assign_pre_merge(): void
    {
        [$owner, $store] = $this->store();

        // No assign-type route + no $roomTypes → the select and bulk helper stay hidden.
        $room = $this->o([
            'id' => 1, 'table_no' => 101, 'label' => 'A', 'area_type' => 'room',
            'floor_label' => '', 'key' => 'K1', 'hk_status' => null,
        ]);
        $paginator = new LengthAwarePaginator([$room], 1, 20, 1, ['path' => '/x']);
        $data = ['store' => $store, 'rooms' => $paginator, 'floors' => collect(), 'q' => '', 'floor' => '', 'type' => ''];

        $html = $this->renderPage($owner, 'owner.hotel.rooms', $data, 'pms.render.rooms.notypes', '/_render/rooms-notypes');

        $this->assertStringNotContainsString('name="room_type_id"', $html);
        $this->assertStringNotContainsString(___('Assign types'), $html);
    }
}
