<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelServiceRequestController;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D4 — station spillover + hotel routing. A disabled station never receives a
 * ticket (KDS spills to the first active one); the hotel department resolver skips
 * inactive departments and the staff-board create path now alerts the desk.
 */
class StationSpilloverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function station(Store $r, string $name, bool $active, int $pos): KitchenStation
    {
        return KitchenStation::create([
            'store_id' => $r->id, 'name' => $name, 'active' => $active,
            'position' => $pos, 'display_color' => '#6366f1',
        ]);
    }

    private function menu(Store $r, ?int $stationId): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(3),
            'price' => 10, 'active' => 1, 'station_id' => $stationId,
        ]);
    }

    // ── KDS spillover ─────────────────────────────────────────────────────────

    public function test_active_station_resolves_to_itself(): void
    {
        $r = $this->storeFixture();
        $grill = $this->station($r, 'Grill', true, 1);
        $menu = $this->menu($r, $grill->id);

        $this->assertSame((int) $grill->id, KdsService::resolveStationForOrderItem(null, $menu));
    }

    public function test_disabled_station_spills_to_first_active(): void
    {
        $r = $this->storeFixture();
        $fry = $this->station($r, 'Fry (off)', false, 1);
        $grill = $this->station($r, 'Grill', true, 2);
        $menu = $this->menu($r, $fry->id); // routed to a disabled station

        // Spills to the first ACTIVE station by position (Grill), never the dead Fry.
        $this->assertSame((int) $grill->id, KdsService::resolveStationForOrderItem(null, $menu));
    }

    public function test_disabled_station_with_no_active_fallback_is_null(): void
    {
        $r = $this->storeFixture();
        $fry = $this->station($r, 'Fry (off)', false, 1);
        $menu = $this->menu($r, $fry->id);

        $this->assertNull(KdsService::resolveStationForOrderItem(null, $menu));
    }

    public function test_no_station_configured_stays_null(): void
    {
        $r = $this->storeFixture();
        $this->station($r, 'Grill', true, 1); // exists but the item is not routed to it
        $menu = $this->menu($r, null);

        $this->assertNull(KdsService::resolveStationForOrderItem(null, $menu));
    }

    // ── Hotel department routing + staff-create alert ──────────────────────────

    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }

    public function test_hotel_resolve_department_skips_inactive(): void
    {
        $r = $this->storeFixture();
        $active = $this->station($r, 'Housekeeping', true, 1);
        $inactive = $this->station($r, 'Old dept', false, 2);
        $c = app(HotelServiceRequestController::class);

        // An explicit ACTIVE department resolves; an inactive one is rejected → null.
        $this->assertSame((int) $active->id, $this->invoke($c, 'resolveDepartment', [$r->id, 'housekeeping', $active->id]));
        $this->assertNull($this->invoke($c, 'resolveDepartment', [$r->id, 'housekeeping', $inactive->id]));
    }

    public function test_staff_created_request_alerts_the_desk(): void
    {
        Bus::fake();
        $r = $this->storeFixture();
        $room = StoreTable::create([
            'store_id' => $r->id, 'table_no' => 305, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 305',
        ]);
        $sr = ServiceRequest::create([
            'store_id' => $r->id, 'table_id' => $room->id, 'type' => 'housekeeping', 'status' => 'open',
        ]);

        $c = app(HotelServiceRequestController::class);
        $this->invoke($c, 'dispatchStaffRequestAlert', [$r, $sr, $room]);

        Bus::assertDispatched(CreateNotificationJob::class, fn ($job) => $job->type === 'hotel_request');
    }

    public function test_staff_alert_respects_the_off_toggle(): void
    {
        Bus::fake();
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'hotel_alerts_notif', '0');
        $sr = ServiceRequest::create([
            'store_id' => $r->id, 'type' => 'maintenance', 'status' => 'open',
        ]);

        $c = app(HotelServiceRequestController::class);
        $this->invoke($c, 'dispatchStaffRequestAlert', [$r, $sr, null]);

        Bus::assertNotDispatched(CreateNotificationJob::class);
    }
}
