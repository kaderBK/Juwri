<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\RoomRate;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS A11 — HotelPmsSetupController CRUD: room types, rate plans, per-date rates
 * (bulk upsert), extra services, events + items, and room ↔ room-type assignment.
 * Covers the delete guards, the rates.bulk overwrite-not-duplicate behaviour, the
 * event status transition (bills the folio), request validation, and cross-tenant
 * isolation (another store's row → 404).
 */
class HotelPmsSetupTest extends TestCase
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

    private function room(Store $store, RoomType $type): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'hk_status' => 'clean',
        ]);
    }

    public function test_room_type_crud(): void
    {
        [$owner, $store] = $this->store();

        $id = $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.types.store'), ['name' => 'Deluxe', 'base_rate' => 150, 'max_occupancy' => 3])
            ->assertOk()->json('id');
        $this->assertDatabaseHas('room_types', ['id' => $id, 'store_id' => $store->id, 'name' => 'Deluxe', 'max_occupancy' => 3]);

        $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.types.update', $id), ['name' => 'Deluxe King', 'base_rate' => 175])
            ->assertOk();
        $this->assertSame('Deluxe King', RoomType::find($id)->name);
        $this->assertSame(175.0, RoomType::find($id)->base_rate);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.types.delete', $id))->assertOk();
        $this->assertDatabaseMissing('room_types', ['id' => $id]);
    }

    public function test_room_type_delete_guarded_by_rooms_and_bookings(): void
    {
        [$owner, $store] = $this->store();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);
        $room = $this->room($store, $type);

        // A room is assigned → delete refused.
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.types.delete', $type->id))->assertStatus(422);

        // Unassign the room but leave an active booking → still refused.
        $room->update(['room_type_id' => null]);
        BookingService::create((int) $store->id, [
            'guest_name' => 'Sam', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2030-01-01', 'check_out' => '2030-01-03',
        ], HotelBooking::STATUS_CONFIRMED);
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.types.delete', $type->id))->assertStatus(422);
        $this->assertDatabaseHas('room_types', ['id' => $type->id]);
    }

    public function test_rate_plan_crud_and_delete_guard(): void
    {
        [$owner, $store] = $this->store();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);

        $id = $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.plans.store'), ['name' => 'Advance', 'modifier_type' => 'percent', 'modifier_value' => -15])
            ->assertOk()->json('id');
        $this->assertDatabaseHas('rate_plans', ['id' => $id, 'store_id' => $store->id, 'modifier_type' => 'percent']);

        // Reference the plan from a booking → delete refused.
        $room = $this->room($store, $type);
        BookingService::create((int) $store->id, [
            'guest_name' => 'Ann', 'room_type_id' => $type->id, 'room_id' => $room->id, 'rate_plan_id' => $id,
            'check_in' => '2030-02-01', 'check_out' => '2030-02-02',
        ], HotelBooking::STATUS_CONFIRMED);
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.plans.delete', $id))->assertStatus(422);
        $this->assertDatabaseHas('rate_plans', ['id' => $id]);
    }

    public function test_rates_bulk_upsert_overwrites_same_date_not_duplicates(): void
    {
        [$owner, $store] = $this->store();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.rates.bulk'), [
            'room_type_id' => $type->id,
            'rates' => ['2026-09-01' => 100, '2026-09-02' => 120],
        ])->assertOk()->assertJson(['updated' => 2]);

        // Re-post the SAME date with a new price + one new date.
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.rates.bulk'), [
            'room_type_id' => $type->id,
            'rates' => ['2026-09-01' => 175, '2026-09-03' => 130],
        ])->assertOk();

        // The base row for 2026-09-01 was overwritten, not duplicated.
        $base = RoomRate::where('room_type_id', $type->id)->whereNull('rate_plan_id')->get();
        $this->assertCount(3, $base, 'expected exactly one row per distinct date (no duplicate for 09-01)');
        $sep1 = $base->firstWhere(fn ($r) => Carbon::parse($r->date)->toDateString() === '2026-09-01');
        $this->assertSame(175.0, (float) $sep1->price);
    }

    public function test_rates_bulk_rejects_bad_date_and_negative_price(): void
    {
        [$owner, $store] = $this->store();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.rates.bulk'), [
            'room_type_id' => $type->id, 'rates' => ['not-a-date' => 100],
        ])->assertStatus(422);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.rates.bulk'), [
            'room_type_id' => $type->id, 'rates' => ['2026-09-01' => -5],
        ])->assertStatus(422);

        $this->assertSame(0, RoomRate::where('room_type_id', $type->id)->count());
    }

    public function test_service_crud(): void
    {
        [$owner, $store] = $this->store();

        $id = $this->actingAs($owner, 'web')->postJson(route('hotel.pms.services.store'), [
            'name' => 'Breakfast', 'price' => 15, 'charge_mode' => 'per_person_per_night', 'tax_rate' => 5,
        ])->assertOk()->json('id');
        $this->assertDatabaseHas('hotel_extra_services', ['id' => $id, 'charge_mode' => 'per_person_per_night']);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.services.update', $id), ['name' => 'Continental', 'price' => 18])->assertOk();
        $this->assertSame('Continental', HotelExtraService::find($id)->name);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.services.delete', $id))->assertOk();
        $this->assertDatabaseMissing('hotel_extra_services', ['id' => $id]);
    }

    public function test_event_crud_items_and_confirm_transition(): void
    {
        [$owner, $store] = $this->store();

        $eventId = $this->actingAs($owner, 'web')->postJson(route('hotel.pms.events.store'), [
            'title' => 'Wedding', 'guest_name' => 'Lee', 'attendees' => 80,
        ])->assertOk()->json('id');
        $this->assertDatabaseHas('hotel_events', ['id' => $eventId, 'status' => 'enquiry']);

        $itemId = $this->actingAs($owner, 'web')->postJson(route('hotel.pms.events.items.add', $eventId), [
            'description' => 'Hall hire', 'qty' => 1, 'unit_price' => 500,
        ])->assertOk()->json('id');

        // Confirm via the update endpoint → EventService bills the standalone folio.
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.events.update', $eventId), ['status' => 'confirmed'])
            ->assertOk()->assertJson(['status' => 'confirmed']);
        $this->assertSame('confirmed', HotelEvent::find($eventId)->status);
        $this->assertNotNull(HotelEvent::find($eventId)->folio_account_id);

        // Remove the item.
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.events.items.remove', [$eventId, $itemId]))->assertOk();
        $this->assertDatabaseMissing('hotel_event_items', ['id' => $itemId]);
    }

    public function test_room_type_assignment(): void
    {
        [$owner, $store] = $this->store();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Suite', 'base_rate' => 300]);
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => '501', 'key' => Str::random(10), 'area_type' => 'room',
        ]);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.rooms.type', $room->id), ['room_type_id' => $type->id])->assertOk();
        $this->assertSame((int) $type->id, (int) $room->fresh()->room_type_id);

        // Null clears it.
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.rooms.type', $room->id), ['room_type_id' => null])->assertOk();
        $this->assertNull($room->fresh()->room_type_id);
    }

    public function test_cross_tenant_rows_are_404(): void
    {
        [$owner] = $this->store();
        [, $other] = $this->store();
        $otherType = RoomType::create(['store_id' => $other->id, 'name' => 'Std', 'base_rate' => 100]);
        $otherService = HotelExtraService::create(['store_id' => $other->id, 'name' => 'Spa', 'price' => 40]);
        $otherEvent = HotelEvent::create(['store_id' => $other->id, 'title' => 'Gala', 'status' => 'enquiry']);

        // Acting as store-1 owner against store-2 rows → 404 (dashboard resolves store 1).
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.types.update', $otherType->id), ['name' => 'X', 'base_rate' => 1])->assertNotFound();
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.services.delete', $otherService->id))->assertNotFound();
        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.events.update', $otherEvent->id), ['title' => 'X'])->assertNotFound();
        $this->assertDatabaseHas('room_types', ['id' => $otherType->id, 'name' => 'Std']);
    }
}
