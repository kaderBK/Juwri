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
use Juwri\Modules\Hotel\Models\HousekeepingTask;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HousekeepingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS A9 — housekeeping: checkout dirties + opens a task, task completion
 * advances cleanliness, check-in warns (never blocks), out_of_order subtracts
 * from availability.
 */
class HotelHousekeepingTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $roomAttrs
     * @return array{0: RoomType, 1: StoreTable}
     */
    private function typeRoom(Store $store, array $roomAttrs = []): array
    {
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);
        $room = StoreTable::create(array_merge([
            'store_id' => $store->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'hk_status' => 'clean',
        ], $roomAttrs));

        return [$type, $room];
    }

    public function test_checkout_dirties_room_and_opens_clean_task(): void
    {
        [, $store] = $this->store();
        [$type, $room] = $this->typeRoom($store);
        $b = BookingService::create((int) $store->id, [
            'guest_name' => 'Sam', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-02',
        ], HotelBooking::STATUS_CONFIRMED);
        $stay = BookingService::checkIn($b);
        HotelFolioService::settle($stay, HotelFolioService::balance($stay), 'cash'); // clear folio
        BookingService::checkOut($b, '2026-09-02');

        $this->assertSame('dirty', $room->fresh()->hkStatus());
        $this->assertSame(1, HousekeepingTask::where('room_id', $room->id)->where('type', 'clean')->where('status', 'open')->count());
    }

    public function test_completing_clean_and_inspect_tasks_advances_cleanliness(): void
    {
        [, $store] = $this->store();
        [, $room] = $this->typeRoom($store, ['hk_status' => 'dirty']);

        $clean = HousekeepingService::openTask((int) $store->id, (int) $room->id, 'clean');
        HousekeepingService::completeTask($clean);
        $this->assertSame('clean', $room->fresh()->hkStatus());

        $inspect = HousekeepingService::openTask((int) $store->id, (int) $room->id, 'inspect');
        HousekeepingService::completeTask($inspect);
        $this->assertSame('inspected', $room->fresh()->hkStatus());
    }

    public function test_check_in_warning_only_when_not_clean(): void
    {
        [, $store] = $this->store();
        [, $dirty] = $this->typeRoom($store, ['hk_status' => 'dirty']);
        [, $clean] = $this->typeRoom($store, ['hk_status' => 'clean']);

        $this->assertNotNull(HousekeepingService::checkInWarning($dirty));
        $this->assertNull(HousekeepingService::checkInWarning($clean));
    }

    public function test_out_of_order_subtracts_from_availability_via_service(): void
    {
        [, $store] = $this->store();
        [$type, $room] = $this->typeRoom($store);
        $this->assertSame(1, BookingService::typeAvailability((int) $store->id, '2026-09-01', '2026-09-03')[$type->id]);

        HousekeepingService::setOutOfOrder($room, 'out_of_order', '2026-09-30');
        $this->assertSame(0, BookingService::typeAvailability((int) $store->id, '2026-09-01', '2026-09-03')[$type->id]);

        HousekeepingService::setOutOfOrder($room, 'none');
        $this->assertSame(1, BookingService::typeAvailability((int) $store->id, '2026-09-01', '2026-09-03')[$type->id]);
    }

    public function test_oo_route_marks_room_and_task_route_completes(): void
    {
        [$owner, $store] = $this->store();
        [, $room] = $this->typeRoom($store, ['hk_status' => 'dirty']);

        $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.rooms.oo', $room->id), ['oo_status' => 'out_of_order', 'oo_until' => '2026-10-01'])
            ->assertOk();
        $this->assertSame('out_of_order', $room->fresh()->ooStatus());

        $task = HousekeepingService::openTask((int) $store->id, (int) $room->id, 'clean');
        $this->actingAs($owner, 'web')
            ->postJson(route('hotel.pms.housekeeping.task'), ['task_id' => $task->id, 'status' => 'done'])
            ->assertOk();
        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('clean', $room->fresh()->hkStatus());
    }

    public function test_housekeeping_board_groups_rooms(): void
    {
        [$owner, $store] = $this->store();
        $this->typeRoom($store, ['hk_status' => 'dirty']);
        $this->typeRoom($store, ['hk_status' => 'clean']);

        $res = $this->actingAs($owner, 'web')->getJson(route('hotel.pms.housekeeping'))->assertOk()->json();
        $this->assertCount(1, $res['board']['grouped']['dirty']);
        $this->assertCount(1, $res['board']['grouped']['clean']);
    }

    /** Lane-B contract: each board room carries hk_status/oo_status/room_type_name/open_task. */
    public function test_housekeeping_board_room_carries_contract_fields(): void
    {
        [, $store] = $this->store();
        [, $room] = $this->typeRoom($store, ['hk_status' => 'dirty']);
        HousekeepingService::openTask((int) $store->id, (int) $room->id, 'clean');

        $board = HousekeepingService::board((int) $store->id);
        $dirtyRoom = $board['grouped']['dirty'][0];

        $this->assertSame('dirty', $dirtyRoom->hk_status);
        $this->assertSame('none', $dirtyRoom->oo_status);
        $this->assertSame('Std', $dirtyRoom->getAttribute('room_type_name'));
        $task = $dirtyRoom->getAttribute('open_task');
        $this->assertInstanceOf(HousekeepingTask::class, $task);
        $this->assertSame('clean', $task->type);
    }
}
