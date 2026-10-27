<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Menu\Models\Menu;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Menu::isAvailableNow — scheduled-availability gating shared by the storefront
 * and the server-side order guard. Regression cover for windows being ignored
 * server-side.
 */
class MenuAvailabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function menu(array $attrs): Menu
    {
        return new Menu()->forceFill($attrs);
    }

    public function test_no_window_is_always_available(): void
    {
        $this->assertTrue($this->menu([])->isAvailableNow('UTC'));
    }

    public function test_inside_time_window_is_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00', 'UTC'));
        $m = $this->menu(['available_from' => '11:00', 'available_to' => '14:00']);
        $this->assertTrue($m->isAvailableNow('UTC'));
    }

    public function test_outside_time_window_is_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:30', 'UTC'));
        $m = $this->menu(['available_from' => '11:00', 'available_to' => '14:00']);
        $this->assertFalse($m->isAvailableNow('UTC'));
    }

    public function test_overnight_window_wraps_midnight(): void
    {
        $m = $this->menu(['available_from' => '22:00', 'available_to' => '02:00']);

        Carbon::setTestNow(Carbon::parse('2026-07-08 23:30', 'UTC'));
        $this->assertTrue($m->isAvailableNow('UTC'));

        Carbon::setTestNow(Carbon::parse('2026-07-08 01:30', 'UTC'));
        $this->assertTrue($m->isAvailableNow('UTC'));

        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00', 'UTC'));
        $this->assertFalse($m->isAvailableNow('UTC'));
    }

    public function test_day_of_week_gate(): void
    {
        // 2026-07-08 is a Wednesday.
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00', 'UTC'));

        $this->assertTrue($this->menu(['available_days' => 'mon,wed,fri'])->isAvailableNow('UTC'));
        $this->assertFalse($this->menu(['available_days' => 'sat,sun'])->isAvailableNow('UTC'));
    }
}
