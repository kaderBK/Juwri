<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS A7 — extra-service posting: charge-mode expansion (once / per_night /
 * per_person_per_night), tax folding, idempotent replay, cross-tenant 404.
 */
class HotelServicePostingTest extends TestCase
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

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'], 'status' => 1,
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

    /** A 2-night stay for 2 adults + 1 child, checked in. */
    private function checkedInStay(Store $store): HotelStay
    {
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'hk_status' => 'clean',
        ]);
        $b = BookingService::create((int) $store->id, [
            'guest_name' => 'Sam', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03', 'adults' => 2, 'children' => 1,
        ], HotelBooking::STATUS_CONFIRMED);

        return BookingService::checkIn($b);
    }

    private function service(Store $store, string $mode, float $price, float $tax = 0): HotelExtraService
    {
        return HotelExtraService::create([
            'store_id' => $store->id, 'name' => ucfirst($mode), 'price' => $price,
            'tax_rate' => $tax, 'charge_mode' => $mode, 'active' => 1,
        ]);
    }

    public function test_once_mode_charges_price_times_qty(): void
    {
        [, $store] = $this->store();
        $stay = $this->checkedInStay($store);
        $base = HotelFolioService::balance($stay);

        $svc = $this->service($store, 'once', 10);
        $res = HotelFolioService::postServiceCharge($stay, $svc, 2);

        $this->assertEqualsWithDelta(20.0, $res['amount'], 0.001);
        $this->assertEqualsWithDelta($base + 20, HotelFolioService::balance($stay), 0.001);
    }

    public function test_per_night_mode_multiplies_by_booked_nights(): void
    {
        [, $store] = $this->store();
        $stay = $this->checkedInStay($store);
        $svc = $this->service($store, 'per_night', 10);

        $res = HotelFolioService::postServiceCharge($stay, $svc, 1);
        $this->assertEqualsWithDelta(20.0, $res['amount'], 0.001); // 10 × 2 nights
    }

    public function test_per_person_per_night_mode(): void
    {
        [, $store] = $this->store();
        $stay = $this->checkedInStay($store);
        $svc = $this->service($store, 'per_person_per_night', 10);

        $res = HotelFolioService::postServiceCharge($stay, $svc, 1);
        $this->assertEqualsWithDelta(60.0, $res['amount'], 0.001); // 10 × 3 persons × 2 nights
    }

    public function test_tax_rate_is_folded_into_the_line(): void
    {
        [, $store] = $this->store();
        $stay = $this->checkedInStay($store);
        $svc = $this->service($store, 'once', 100, 15); // 15% tax

        $res = HotelFolioService::postServiceCharge($stay, $svc, 1);
        $this->assertEqualsWithDelta(115.0, $res['amount'], 0.001);
    }

    public function test_idempotent_replay_with_same_key_charges_once(): void
    {
        [, $store] = $this->store();
        $stay = $this->checkedInStay($store);
        $base = HotelFolioService::balance($stay);
        $svc = $this->service($store, 'once', 25);
        $key = (string) Str::uuid();

        HotelFolioService::postServiceCharge($stay, $svc, 1, $key);
        HotelFolioService::postServiceCharge($stay, $svc, 1, $key);

        $this->assertEqualsWithDelta($base + 25, HotelFolioService::balance($stay), 0.001);
    }

    public function test_route_posts_service_and_rejects_wrong_store_service(): void
    {
        [$owner, $store] = $this->store();
        $stay = $this->checkedInStay($store);
        $svc = $this->service($store, 'once', 30);

        $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.services.post', $stay->id), ['service_id' => $svc->id, 'qty' => 1])
            ->assertOk()->assertJson(['success' => true]);

        // A service from another store → 404.
        [, $postB] = $this->store();
        $foreignSvc = $this->service($postB, 'once', 5);
        $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.services.post', $stay->id), ['service_id' => $foreignSvc->id, 'qty' => 1])
            ->assertStatus(404);
    }
}
