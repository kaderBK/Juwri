<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Services\Rota\RotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B7-P1: rota schema + service.
 */
class RotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private RotaService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1); // P1-04 5b: fixture store ids must be real stores

        // FIX-2 F2-7: createShift() now re-asserts the staff_rota gate on the
        // write door. `staff_rota` is kill-switch-default-OFF and this class has
        // no `features` row (so the key is not plan_gated here) — meaning the
        // kill veto alone would refuse every shift. Turn the feature on: this
        // class is about rota persistence/overlap logic, and the feature being
        // available is its precondition, not its subject.
        config(['settings.admin_allow_staff_rota' => 1]);
        \App\Services\FeatureRegistry::clearCache();
        \App\Services\FeatureAccess::flush();

        $this->svc = new RotaService();
    }

    private function shift(int $rid, int $staffId, string $start, string $end, bool $published = false): StaffShift
    {
        return $this->svc->createShift([
            'store_id' => $rid, 'staff_id' => $staffId,
            'starts_at' => $start, 'ends_at' => $end, 'published' => $published,
        ]);
    }

    public function test_create_persists_scoped_shift(): void
    {
        $s = $this->shift(1, 10, '2026-07-13 09:00', '2026-07-13 17:00');
        $this->assertDatabaseHas('staff_shifts', ['id' => $s->id, 'store_id' => 1, 'staff_id' => 10, 'published' => false]);
    }

    public function test_overlap_detection(): void
    {
        $this->shift(1, 10, '2026-07-13 09:00', '2026-07-13 17:00');

        $this->assertTrue($this->svc->overlaps(10, '2026-07-13 16:00', '2026-07-13 18:00'));
        // Back-to-back (half-open) does not overlap.
        $this->assertFalse($this->svc->overlaps(10, '2026-07-13 17:00', '2026-07-13 20:00'));
        // Different staff never overlaps.
        $this->assertFalse($this->svc->overlaps(99, '2026-07-13 10:00', '2026-07-13 12:00'));
    }

    public function test_overlap_excludes_self_on_edit(): void
    {
        $s = $this->shift(1, 10, '2026-07-13 09:00', '2026-07-13 17:00');
        $this->assertFalse($this->svc->overlaps(10, '2026-07-13 09:00', '2026-07-13 17:00', $s->id));
    }

    public function test_publish_week_and_staff_visibility(): void
    {
        // Two shifts in the same week for staff 10, one for another week.
        $this->shift(1, 10, '2026-07-13 09:00', '2026-07-13 17:00'); // Mon
        $this->shift(1, 10, '2026-07-15 09:00', '2026-07-15 17:00'); // Wed
        $this->shift(1, 10, '2026-07-22 09:00', '2026-07-22 17:00'); // next week

        // Staff sees nothing before publish.
        $this->assertCount(0, $this->svc->publishedShiftsForStaff(10, \Illuminate\Support\Carbon::parse('2026-07-13'), \Illuminate\Support\Carbon::parse('2026-07-19')));

        $count = $this->svc->publishWeek(1, '2026-07-13');
        $this->assertSame(2, $count);

        $visible = $this->svc->publishedShiftsForStaff(10, \Illuminate\Support\Carbon::parse('2026-07-13'), \Illuminate\Support\Carbon::parse('2026-07-19'));
        $this->assertCount(2, $visible);
        // The next-week shift stays unpublished.
        $this->assertFalse(StaffShift::where('starts_at', '2026-07-22 09:00:00')->first()->published);
    }

    public function test_week_shifts_returns_all_in_week(): void
    {
        $this->shift(1, 10, '2026-07-13 09:00', '2026-07-13 17:00');
        $this->shift(1, 11, '2026-07-14 09:00', '2026-07-14 17:00');
        $this->assertCount(2, $this->svc->weekShifts(1, '2026-07-13'));
        $this->assertCount(0, $this->svc->weekShifts(1, '2026-07-20'));
    }
}
