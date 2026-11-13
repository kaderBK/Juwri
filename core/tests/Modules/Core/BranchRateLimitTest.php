<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\AbuseEvent;
use Tests\TestCase;

/**
 * Abuse Guard P7 (7.4/7.8): per-restaurant/branch ceilings on public writes.
 */
class BranchRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('abuse:branch:orders:55');
        Route::post('/_t/{store}/order', fn () => 'ok')
            ->middleware('branchlimit:orders')->name('_t.order');
    }

    public function test_enforce_blocks_over_branch_ceiling(): void
    {
        config(['settings.abuse_branch_mode' => 'enforce', 'settings.abuse_branch_orders_per_min' => 2]);

        $this->post('/_t/55/order')->assertOk();
        $this->post('/_t/55/order')->assertOk();
        $this->post('/_t/55/order')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(1, AbuseEvent::where('guard', 'branch')->count());
    }

    public function test_warn_allows_but_logs(): void
    {
        config(['settings.abuse_branch_mode' => 'warn', 'settings.abuse_branch_orders_per_min' => 1]);

        $this->post('/_t/55/order')->assertOk();
        $this->post('/_t/55/order')->assertOk();
        $this->assertGreaterThanOrEqual(1, AbuseEvent::where('guard', 'branch')->count());
    }
}
