<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Storefront\LighthouseGate;
use Tests\TestCase;

/**
 * P4-3: the perf/a11y gate. Verdict logic is pure and tested on fixtures —
 * real Chrome runs happen in the review pipeline (and were exercised once
 * live against the crave storefront; see PROGRESS).
 */
class LighthouseGateTest extends TestCase
{
    /** A gate whose runner returns scripted scores instead of shelling out. */
    private function gateWith(array $scores): LighthouseGate
    {
        return new class($scores) extends LighthouseGate
        {
            public function __construct(private array $scores) {}

            protected function run(string $url, string $formFactor): array
            {
                return array_shift($this->scores);
            }
        };
    }

    public function test_passes_when_both_bars_are_met(): void
    {
        $gate = $this->gateWith([
            ['performance' => 72.0, 'accessibility' => 95.0], // home mobile
            ['performance' => 88.0, 'accessibility' => 97.0], // home desktop
        ]);

        $verdict = $gate->evaluate(['https://seed.test/home']);

        $this->assertTrue($verdict['passed']);
        $this->assertSame(80.0, $verdict['performance']); // avg(72, 88)
        $this->assertSame(95.0, $verdict['accessibility']); // min
        $this->assertCount(2, $verdict['runs'], 'mobile + desktop per URL');
        $this->assertSame([], $verdict['reasons']);
    }

    public function test_fails_below_the_performance_average_bar(): void
    {
        $gate = $this->gateWith([
            ['performance' => 45.0, 'accessibility' => 95.0],
            ['performance' => 70.0, 'accessibility' => 95.0],
        ]);

        $verdict = $gate->evaluate(['https://seed.test/home']);

        $this->assertFalse($verdict['passed']); // avg 57.5 < 60
        $this->assertStringContainsString('performance 57.5', $verdict['reasons'][0]);
    }

    public function test_fails_when_any_run_misses_the_accessibility_minimum(): void
    {
        $gate = $this->gateWith([
            ['performance' => 90.0, 'accessibility' => 96.0],
            ['performance' => 92.0, 'accessibility' => 82.0], // one bad run sinks it
        ]);

        $verdict = $gate->evaluate(['https://seed.test/home']);

        $this->assertFalse($verdict['passed']);
        $this->assertStringContainsString('accessibility 82', $verdict['reasons'][0]);
    }

    public function test_no_runs_means_reject(): void
    {
        $verdict = new LighthouseGate()->verdict([]);

        $this->assertFalse($verdict['passed']);
    }

    public function test_bars_are_configurable(): void
    {
        config(['platform.lighthouse.min_performance' => 90]);

        $gate = $this->gateWith([
            ['performance' => 80.0, 'accessibility' => 95.0],
            ['performance' => 85.0, 'accessibility' => 95.0],
        ]);

        $this->assertFalse($gate->evaluate(['https://seed.test/x'])['passed']);
    }
}
