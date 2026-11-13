<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P7 — the shipped dev/test defaults for cache/session stay `file` so a fresh install
 * boots with zero Redis dependency; the prod redis switch is owner-applied env, documented
 * in deploy/PROD-HARDENING-CHECKLIST.md. This guards against an accidental default flip.
 */
class EnvExampleDriversTest extends TestCase
{
    public function test_env_example_ships_file_drivers(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^CACHE_DRIVER=file$/m', $env, 'shipped CACHE_DRIVER default must stay file');
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=file$/m', $env, 'shipped SESSION_DRIVER default must stay file');
    }

    public function test_prod_redis_guidance_is_documented(): void
    {
        $checklist = file_get_contents(base_path('../deploy/PROD-HARDENING-CHECKLIST.md'));

        $this->assertStringContainsString('CACHE_DRIVER=redis', $checklist);
        $this->assertStringContainsString('SESSION_DRIVER=redis', $checklist);
    }
}
