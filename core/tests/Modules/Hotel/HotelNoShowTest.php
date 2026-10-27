<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Tests\TestCase;

/**
 * HPMS A10 — no-show sweep: grace math, nights freed, idempotent re-run,
 * notification dispatched.
 */
class HotelNoShowTest extends TestCase
{
    use RefreshDatabase;

    private int $rid;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

        $owner = User::factory()->create(['status' => 1]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $this->rid = (int) $store->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function type(): RoomType
    {
        $type = RoomType::create(['store_id' => $this->rid, 'name' => 'Std', 'base_rate' => 100]);
        // Plenty of rooms so each confirmed booking auto-picks a free one.
        for ($i = 0; $i < 4; $i++) {
            StoreTable::create([
                'store_id' => $this->rid, 'table_no' => (string) (100 + $i),
                'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'hk_status' => 'clean',
            ]);
        }

        return $type;
    }

    private function confirmed(RoomType $type, string $in, string $out, string $guest = 'Sam'): HotelBooking
    {
        return BookingService::create($this->rid, [
            'guest_name' => $guest, 'room_type_id' => $type->id,
            'check_in' => $in, 'check_out' => $out,
        ], HotelBooking::STATUS_CONFIRMED);
    }

    public function test_grace_math_sweeps_only_past_deadline_bookings(): void
    {
        $type = $this->type();
        // check_in 2 days ago → deadline 2026-09-08 18:00 < now → sweep.
        $stale = $this->confirmed($type, '2026-09-08', '2026-09-09');
        // check_in today → deadline 2026-09-10 18:00 > now (12:00) → within grace.
        $fresh = $this->confirmed($type, '2026-09-10', '2026-09-11');

        $this->artisan('hotel:no-show-sweep')->assertExitCode(0);

        $this->assertSame('no_show', $stale->fresh()->status);
        $this->assertSame('confirmed', $fresh->fresh()->status);
    }

    public function test_custom_grace_hours_option_is_honoured(): void
    {
        StoreSettings::set($this->rid, 'hotel_noshow_grace_hours', '6');
        $type = $this->type();
        // check_in today, grace 6h → deadline 2026-09-10 06:00 < now (12:00) → sweep.
        $b = $this->confirmed($type, '2026-09-10', '2026-09-11');

        $this->artisan('hotel:no-show-sweep')->assertExitCode(0);
        $this->assertSame('no_show', $b->fresh()->status);
    }

    public function test_sweep_frees_the_night_rows(): void
    {
        $type = $this->type();
        $b = $this->confirmed($type, '2026-09-08', '2026-09-10');
        $this->assertSame(2, HotelBookingNight::where('booking_id', $b->id)->count());

        $this->artisan('hotel:no-show-sweep');
        $this->assertSame(0, HotelBookingNight::where('booking_id', $b->id)->count());
    }

    public function test_rerun_is_idempotent(): void
    {
        $type = $this->type();
        $this->confirmed($type, '2026-09-08', '2026-09-09');

        $this->artisan('hotel:no-show-sweep');
        $this->assertSame(1, HotelBooking::where('store_id', $this->rid)->where('status', 'no_show')->count());

        // Second run sweeps nothing (already no_show).
        $this->artisan('hotel:no-show-sweep');
        $this->assertSame(1, HotelBooking::where('store_id', $this->rid)->where('status', 'no_show')->count());
    }

    public function test_notification_dispatched_per_swept_booking(): void
    {
        Queue::fake();
        $type = $this->type();
        $this->confirmed($type, '2026-09-08', '2026-09-09', 'Sam [Carter](x)');

        $this->artisan('hotel:no-show-sweep');

        Queue::assertPushed(CreateNotificationJob::class, function ($job) {
            // []() neutralised, type is hotel_pms.
            return $job->type === 'hotel_pms'
                && ! str_contains($job->title, '[')
                && ! str_contains($job->title, '(');
        });
    }
}
