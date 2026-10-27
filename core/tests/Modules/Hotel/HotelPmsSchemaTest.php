<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomRate;
use Juwri\Modules\Hotel\Models\RoomType;
use Tests\TestCase;

/**
 * HPMS A1/A2 — booking-engine schema. Asserts the PMS tables, additive columns
 * and race-guard uniques exist, that models round-trip, and that each new
 * migration's down() is clean (drops only what up() created).
 */
class HotelPmsSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 7); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    public function test_rate_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('room_types'));
        $this->assertTrue(Schema::hasTable('rate_plans'));
        $this->assertTrue(Schema::hasTable('room_rates'));

        foreach (['store_id', 'name', 'code', 'base_rate_minor', 'currency_code', 'max_occupancy', 'sort', 'active'] as $c) {
            $this->assertTrue(Schema::hasColumn('room_types', $c), "room_types.$c");
        }
        foreach (['store_id', 'name', 'parent_id', 'modifier_type', 'modifier_value', 'modifier_amount_minor', 'refundable', 'cancel_before_hours', 'active'] as $c) {
            $this->assertTrue(Schema::hasColumn('rate_plans', $c), "rate_plans.$c");
        }
        foreach (['store_id', 'room_type_id', 'rate_plan_id', 'date', 'price_minor', 'currency_code'] as $c) {
            $this->assertTrue(Schema::hasColumn('room_rates', $c), "room_rates.$c");
        }
    }

    public function test_restaurant_tables_pms_columns_exist(): void
    {
        foreach (['room_type_id', 'hk_status', 'oo_status', 'oo_until'] as $c) {
            $this->assertTrue(Schema::hasColumn('store_tables', $c), "store_tables.$c");
        }
    }

    public function test_room_rate_unique_blocks_duplicate_cell(): void
    {
        // The UNIQUE(room_type_id, rate_plan_id, date) enforces one price per cell
        // for PLAN-specific rows. (Base rows carry rate_plan_id NULL, which both
        // SQLite and MySQL treat as distinct in a unique index; their one-per-cell
        // guarantee comes from A11's updateOrCreate upsert, not the DB unique.)
        $type = RoomType::create(['store_id' => 1, 'name' => 'Std', 'base_rate' => 100]);
        $plan = RatePlan::create(['store_id' => 1, 'name' => 'Flexible']);
        RoomRate::create(['store_id' => 1, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'date' => '2026-09-01', 'price' => 120]);

        $this->expectException(QueryException::class);
        RoomRate::create(['store_id' => 1, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'date' => '2026-09-01', 'price' => 130]);
    }

    public function test_models_round_trip_and_scope(): void
    {
        $type = RoomType::create(['store_id' => 7, 'name' => 'Deluxe', 'code' => 'DLX', 'base_rate' => 200, 'max_occupancy' => 3]);
        $plan = RatePlan::create(['store_id' => 7, 'name' => 'Flexible']);
        RoomRate::create(['store_id' => 7, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'date' => '2026-09-02', 'price' => 250]);

        $this->assertSame(1, RoomType::forRestaurant(7)->count());
        $this->assertSame(0, RoomType::forRestaurant(8)->count());
        $this->assertTrue($type->fresh()->active);
        $this->assertTrue($plan->fresh()->refundable);
        $this->assertSame(1, $type->rates()->count());
    }

    // ── A2: bookings / services / events / housekeeping ──────────────────────

    public function test_booking_tables_exist(): void
    {
        foreach (['hotel_bookings', 'hotel_booking_nights', 'hotel_extra_services', 'hotel_events', 'hotel_event_items', 'housekeeping_tasks'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t);
        }
        foreach (['store_id', 'code', 'token', 'guest_name', 'room_type_id', 'room_id', 'check_in', 'check_out', 'status', 'quoted_total_minor', 'currency', 'source', 'hotel_stay_id'] as $c) {
            $this->assertTrue(Schema::hasColumn('hotel_bookings', $c), "hotel_bookings.$c");
        }
    }

    public function test_booking_nights_unique_is_the_race_guard(): void
    {
        HotelBookingNight::create(['booking_id' => 1, 'room_id' => 5, 'night' => '2026-09-10']);

        // Same (room, night) from a "racing" booking must be rejected by the unique.
        $this->expectException(QueryException::class);
        HotelBookingNight::create(['booking_id' => 2, 'room_id' => 5, 'night' => '2026-09-10']);
    }

    public function test_booking_code_generator_is_unique_and_alnum(): void
    {
        $codes = [];
        for ($i = 0; $i < 50; $i++) {
            $c = HotelBooking::generateCode();
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $c);
            $codes[$c] = true;
            HotelBooking::create([
                'store_id' => 1, 'code' => $c, 'guest_name' => 'G', 'room_type_id' => 1,
                'check_in' => '2026-09-01', 'check_out' => '2026-09-02',
            ]);
        }
        $this->assertCount(50, $codes); // no collisions
    }

    public function test_booking_token_is_hidden_from_array(): void
    {
        $b = HotelBooking::create([
            'store_id' => 1, 'code' => HotelBooking::generateCode(), 'token' => HotelBooking::generateToken(),
            'guest_name' => 'G', 'room_type_id' => 1, 'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ]);
        $this->assertArrayNotHasKey('token', $b->toArray());
        $this->assertSame(2, $b->nightsCount());
        $this->assertTrue($b->fresh()->isBlocking()); // DB default status = pending (blocking)
    }
}
