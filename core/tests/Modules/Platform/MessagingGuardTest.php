<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Services\MessagingGuard;
use Tests\TestCase;

/**
 * Abuse Guard (edge): per-tenant daily cap on bulk/marketing message sends.
 */
class MessagingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function storeId(): int
    {
        $owner = User::factory()->create();

        return Store::create([
            'user_id' => $owner->id, 'title' => 'R', 'slug' => 'r-'.$owner->id,
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ])->id;
    }

    public function test_enforce_stops_over_cap(): void
    {
        config(['settings.abuse_messaging_mode' => 'enforce', 'settings.abuse_messaging_tenant_daily_cap' => 2]);
        $rid = $this->storeId();

        $this->assertTrue(MessagingGuard::allow($rid));
        $this->assertTrue(MessagingGuard::allow($rid));
        $this->assertFalse(MessagingGuard::allow($rid)); // 3rd over cap
        $this->assertSame(1, AbuseEvent::where('guard', 'messaging')->count());
    }

    public function test_warn_allows_but_logs(): void
    {
        config(['settings.abuse_messaging_mode' => 'warn', 'settings.abuse_messaging_tenant_daily_cap' => 1]);
        $rid = $this->storeId();

        $this->assertTrue(MessagingGuard::allow($rid));
        $this->assertTrue(MessagingGuard::allow($rid));
        $this->assertGreaterThanOrEqual(1, AbuseEvent::where('guard', 'messaging')->count());
    }

    public function test_off_and_no_restaurant_noop(): void
    {
        config(['settings.abuse_messaging_mode' => 'off', 'settings.abuse_messaging_tenant_daily_cap' => 1]);
        $rid = $this->storeId();
        $this->assertTrue(MessagingGuard::allow($rid));
        $this->assertTrue(MessagingGuard::allow($rid));
        $this->assertTrue(MessagingGuard::allow(null));
        $this->assertSame(0, AbuseEvent::count());
    }
}
