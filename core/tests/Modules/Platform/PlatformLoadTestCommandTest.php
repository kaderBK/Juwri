<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5-4: the bench command stays runnable (tiny N — the real numbers live in
 * decisions/load-test.md; this guards the harness itself).
 */
class PlatformLoadTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_load_test_command_runs_and_reports(): void
    {
        $this->artisan('platform:load-test', ['--renders' => 3, '--installs' => 2])
            ->expectsOutputToContain('crave (single-file liquid)')
            ->expectsOutputToContain('install path')
            ->assertExitCode(0);
    }
}
