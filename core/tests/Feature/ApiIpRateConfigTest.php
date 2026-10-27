<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** P4-16 (load test): the `api` group's per-IP ceiling was a hard-coded 60 a minute; it is now API_IP_RATE (default unchanged). */
class ApiIpRateConfigTest extends TestCase
{
    public function test_the_default_is_still_sixty_a_minute(): void
    {
        $limit = RateLimiter::limiter('api')(Request::create('/api/v1/x', 'GET'));
        $this->assertSame(60, $limit->maxAttempts);
    }

    public function test_the_ceiling_follows_the_config(): void
    {
        config(['api.ip_rate' => 600]);
        $limit = RateLimiter::limiter('api')(Request::create('/api/v1/x', 'GET'));
        $this->assertSame(600, $limit->maxAttempts);
    }
}
