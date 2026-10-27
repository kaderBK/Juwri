<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Reservations;
use App\Models\Reservation;
use Juwri\Modules\Reservations\Services\ReservationBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class ReservationBookingTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {
        parent::setUp();
        $this->seedStores(1); // P1-04 5b: fixture store ids must be real stores
    }
    private function res(string $at, string $status='confirmed'): Reservation {
        return Reservation::create(['store_id'=>1,'name'=>'G','party_size'=>2,'reserved_at'=>$at,'status'=>$status]);
    }
    public function test_double_book_guard(): void {
        $a=$this->res('2026-07-10 19:00:00');
        ReservationBookingService::assignTable($a,5);
        $this->assertSame(5,$a->fresh()->table_id);
        // same table within window -> reject
        $b=$this->res('2026-07-10 19:30:00');
        $this->expectException(\RuntimeException::class);
        ReservationBookingService::assignTable($b,5);
    }
    public function test_far_apart_is_allowed(): void {
        $a=$this->res('2026-07-10 12:00:00'); ReservationBookingService::assignTable($a,7);
        $b=$this->res('2026-07-10 20:00:00'); // >90min later
        $ok=ReservationBookingService::assignTable($b,7);
        $this->assertSame(7,$ok->fresh()->table_id);
    }

    public function test_p6239_clash_is_rechecked_inside_the_table_lock(): void {
        // A rival booking lands after the caller's own (stale) pre-check: the in-lock re-check must refuse.
        Reservation::create(['store_id'=>1,'name'=>'Rival','party_size'=>2,'reserved_at'=>'2026-07-10 19:00:00','status'=>'confirmed','table_number'=>'T5']);
        $created = 0;
        try {
            ReservationBookingService::withTableLock(1, 'T5', '2026-07-10 19:30:00', function () use (&$created) { $created++; });
            $this->fail('expected clash');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already booked', $e->getMessage());
        }
        $this->assertSame(0, $created);
    }

    public function test_p6239_second_booking_of_same_table_loses_and_other_table_wins(): void {
        $book = fn (string $t, string $at) => ReservationBookingService::withTableLock(1, $t, $at, fn () =>
            Reservation::create(['store_id'=>1,'name'=>'G','party_size'=>2,'reserved_at'=>$at,'status'=>'pending','table_number'=>$t]));
        $book('T1', '2026-07-10 19:00:00');
        $this->assertSame(1, Reservation::where('table_number','T1')->count());
        try { $book('T1', '2026-07-10 19:00:00'); $this->fail('expected clash'); } catch (\RuntimeException $e) {}
        $book('T2', '2026-07-10 19:00:00');
        $this->assertSame(1, Reservation::where('table_number','T1')->count());
        $this->assertSame(1, Reservation::where('table_number','T2')->count());
    }

    public function test_p6239_booking_refused_while_another_request_holds_the_table_lock(): void {
        $held = \Illuminate\Support\Facades\Cache::lock('res-table:1:t9', 30);
        $this->assertTrue($held->get());
        try {
            ReservationBookingService::withTableLock(1, 'T9', '2026-07-10 19:00:00', fn () =>
                Reservation::create(['store_id'=>1,'name'=>'G','party_size'=>2,'reserved_at'=>'2026-07-10 19:00:00','status'=>'pending','table_number'=>'T9']));
            $this->fail('expected busy');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('busy', $e->getMessage());
        } finally {
            $held->release();
        }
        $this->assertSame(0, Reservation::where('table_number','T9')->count());
    }
}
