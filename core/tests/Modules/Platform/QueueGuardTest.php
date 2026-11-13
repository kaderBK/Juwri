<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\AbuseEvent;
use App\Services\QueueGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abuse Guard P5 (5.2): per-tenant pending heavy-job quota.
 */
class QueueGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_enforce_blocks_over_cap(): void
    {
        config(['settings.abuse_queue_mode' => 'enforce', 'settings.abuse_queue_tenant_pending_cap' => 2]);

        $this->assertTrue(QueueGuard::allow(7));
        $this->assertTrue(QueueGuard::allow(7));
        $this->assertFalse(QueueGuard::allow(7)); // 3rd over cap 2
        $this->assertSame(1, AbuseEvent::where('guard', 'queue')->count());
    }

    public function test_warn_logs_but_allows(): void
    {
        config(['settings.abuse_queue_mode' => 'warn', 'settings.abuse_queue_tenant_pending_cap' => 1]);

        $this->assertTrue(QueueGuard::allow(9));
        $this->assertTrue(QueueGuard::allow(9)); // over cap but warn → allowed
        $this->assertGreaterThanOrEqual(1, AbuseEvent::where('guard', 'queue')->count());
    }

    public function test_release_frees_a_slot(): void
    {
        config(['settings.abuse_queue_mode' => 'enforce', 'settings.abuse_queue_tenant_pending_cap' => 1]);

        $this->assertTrue(QueueGuard::allow(3));
        $this->assertFalse(QueueGuard::allow(3));
        QueueGuard::release(3);
        $this->assertTrue(QueueGuard::allow(3));
    }

    public function test_off_and_guest_are_noop(): void
    {
        config(['settings.abuse_queue_mode' => 'off', 'settings.abuse_queue_tenant_pending_cap' => 1]);
        $this->assertTrue(QueueGuard::allow(1));
        $this->assertTrue(QueueGuard::allow(1));
        $this->assertTrue(QueueGuard::allow(null));
        $this->assertSame(0, AbuseEvent::count());
    }
}
