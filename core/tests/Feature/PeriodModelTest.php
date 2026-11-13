<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * P1-4 — one period model: resolve_dashboard_period() presets, custom range,
 * session persistence, and the "same week last" compare window.
 */
class PeriodModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolve(array $query = [], array $session = []): array
    {
        request()->replace($query);
        foreach ($session as $k => $v) session([$k => $v]);
        return resolve_dashboard_period();
    }

    public function test_presets_resolve_expected_windows(): void
    {
        $today = $this->resolve(['period' => 'today']);
        $this->assertSame('today', $today['period']);
        $this->assertSame('2026-07-14', $today['from']);
        $this->assertSame('2026-07-14', $today['to']);

        $w = $this->resolve(['period' => '7d']);
        $this->assertSame('2026-07-08', $w['from']); // now-6 days
        $this->assertSame('2026-07-14', $w['to']);

        $m = $this->resolve(['period' => '30d']);
        $this->assertSame('2026-06-15', $m['from']); // now-29 days
    }

    public function test_compare_window_is_the_same_period_one_week_earlier(): void
    {
        $w = $this->resolve(['period' => '7d']);
        // Weekday-aligned: prev = start/end shifted back exactly 7 days.
        $this->assertSame('2026-07-01', $w['prevStart']->toDateString());
        $this->assertSame('2026-07-07', $w['prevEnd']->toDateString());
    }

    public function test_custom_range_and_session_persistence(): void
    {
        $c = $this->resolve(['period' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-10']);
        $this->assertSame('custom', $c['period']);
        $this->assertSame('2026-07-01', $c['from']);
        $this->assertSame('2026-07-10', $c['to']);

        // No period in the request → the session remembers the last choice.
        $again = $this->resolve([]);
        $this->assertSame('custom', $again['period']);
        $this->assertSame('2026-07-01', $again['from']);
    }

    public function test_invalid_period_falls_back_to_month(): void
    {
        $r = $this->resolve(['period' => 'garbage']);
        $this->assertSame('month', $r['period']);
        $this->assertSame('2026-07-01', $r['from']);
        $this->assertSame('2026-07-31', $r['to']);
    }
}
