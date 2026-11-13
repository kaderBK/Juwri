<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\PriceSchedule;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * PARITY-RUN T5 (unit): the window arithmetic and the price arithmetic, with no
 * database. Every branch of AppliesTimeWindow and PriceSchedule::apply().
 */
class PriceScheduleWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $when, string $tz = 'UTC'): Carbon
    {
        return Carbon::parse($when, $tz);
    }

    /* ─────────────────────────── windows ─────────────────────────────── */

    public function test_an_empty_window_always_covers(): void
    {
        $this->assertTrue(Menu::windowCovers(null, null, null, $this->at('2026-07-08 03:00')));
    }

    public function test_a_half_open_time_window_does_not_gate(): void
    {
        // Only one end set = not a window. Matches the shipped behaviour.
        $this->assertTrue(Menu::windowCovers(null, '11:00', null, $this->at('2026-07-08 23:00')));
        $this->assertTrue(Menu::windowCovers(null, null, '14:00', $this->at('2026-07-08 23:00')));
    }

    public function test_a_same_day_window_covers_only_inside_it(): void
    {
        $this->assertTrue(Menu::windowCovers(null, '11:00', '14:00', $this->at('2026-07-08 11:00')));
        $this->assertTrue(Menu::windowCovers(null, '11:00', '14:00', $this->at('2026-07-08 14:00')));
        $this->assertFalse(Menu::windowCovers(null, '11:00', '14:00', $this->at('2026-07-08 10:59')));
        $this->assertFalse(Menu::windowCovers(null, '11:00', '14:00', $this->at('2026-07-08 14:01')));
    }

    public function test_an_overnight_window_wraps_midnight(): void
    {
        foreach (['22:00', '23:30', '00:00', '01:59', '02:00'] as $t) {
            $this->assertTrue(
                Menu::windowCovers(null, '22:00', '02:00', $this->at("2026-07-08 $t")),
                "$t should be inside 22:00→02:00"
            );
        }
        foreach (['02:01', '12:00', '21:59'] as $t) {
            $this->assertFalse(
                Menu::windowCovers(null, '22:00', '02:00', $this->at("2026-07-08 $t")),
                "$t should be outside 22:00→02:00"
            );
        }
    }

    public function test_seconds_in_a_time_column_do_not_break_the_compare(): void
    {
        // MySQL hands back 'HH:MM:SS'; a naive lexicographic compare against
        // 'HH:MM' would mis-order every boundary.
        $this->assertTrue(Menu::windowCovers(null, '11:00:00', '14:00:00', $this->at('2026-07-08 13:59')));
        $this->assertFalse(Menu::windowCovers(null, '11:00:00', '14:00:00', $this->at('2026-07-08 14:30')));
    }

    public function test_day_codes_gate_and_are_case_insensitive(): void
    {
        $wed = $this->at('2026-07-08 12:00'); // a Wednesday
        $this->assertTrue(Menu::windowCovers('mon,wed,fri', null, null, $wed));
        $this->assertTrue(Menu::windowCovers('MON, WED', null, null, $wed));
        $this->assertFalse(Menu::windowCovers('sat,sun', null, null, $wed));
    }

    public function test_order_type_scope(): void
    {
        $this->assertTrue(Menu::scopeCoversOrderType(null, 'delivery'));
        $this->assertTrue(Menu::scopeCoversOrderType('', 'delivery'));
        $this->assertTrue(Menu::scopeCoversOrderType('on-table,delivery', 'delivery'));
        $this->assertFalse(Menu::scopeCoversOrderType('on-table', 'delivery'));
        // A menu browsed before the guest has chosen a type must not be narrowed.
        $this->assertTrue(Menu::scopeCoversOrderType('on-table', null));
    }

    /* ──────────────────────────── pricing ────────────────────────────── */

    private function schedule(array $attrs): PriceSchedule
    {
        return new PriceSchedule()->forceFill(array_merge([
            'store_id'   => 0, // unresolvable store ⇒ 2 decimals, as the old StoreMoney cast did
            'active'          => true,
            'adjustment_type' => 'percent',
        ], $attrs));
    }

    public function test_percent_amount_and_fixed_price(): void
    {
        $this->assertSame(8.0, $this->schedule(['adjustment_type' => 'percent', 'adjustment_value' => 20])->apply(10.0));
        $this->assertSame(7.5, $this->schedule(['adjustment_type' => 'amount', 'adjustment_value' => 2.5])->apply(10.0));
        $this->assertSame(6.0, $this->schedule(['adjustment_type' => 'fixed_price', 'adjustment_value' => 6])->apply(10.0));
    }

    public function test_a_schedule_can_never_raise_a_price_or_go_negative(): void
    {
        // A "fixed price" above the list price is a surcharge — not sold here.
        $this->assertSame(10.0, $this->schedule(['adjustment_type' => 'fixed_price', 'adjustment_value' => 99])->apply(10.0));
        // An over-large amount-off clamps at zero rather than paying the guest.
        $this->assertSame(0.0, $this->schedule(['adjustment_type' => 'amount', 'adjustment_value' => 999])->apply(10.0));
    }

    public function test_is_live_at_honours_the_date_range(): void
    {
        $s = $this->schedule([
            'adjustment_value' => 10,
            'starts_on' => Carbon::parse('2026-07-01'),
            'ends_on'   => Carbon::parse('2026-07-31'),
        ]);

        $this->assertTrue($s->isLiveAt($this->at('2026-07-31 23:30')));  // inclusive end
        $this->assertTrue($s->isLiveAt($this->at('2026-07-01 00:05')));  // inclusive start
        $this->assertFalse($s->isLiveAt($this->at('2026-06-30 23:59')));
        $this->assertFalse($s->isLiveAt($this->at('2026-08-01 00:01')));
    }

    public function test_an_inactive_schedule_is_never_live(): void
    {
        $this->assertFalse($this->schedule(['adjustment_value' => 10, 'active' => false])->isLiveAt($this->at('2026-07-08 12:00')));
    }

    public function test_display_label_falls_back_to_the_owner_name(): void
    {
        $this->assertSame('Happy Hour', $this->schedule(['name' => 'HH-1', 'label' => 'Happy Hour'])->displayLabel());
        $this->assertSame('HH-1', $this->schedule(['name' => 'HH-1', 'label' => null])->displayLabel());
    }
}
