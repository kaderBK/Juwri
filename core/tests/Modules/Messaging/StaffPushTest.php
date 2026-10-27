<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\StaffPush\WebPushSender;
use Tests\TestCase;

/**
 * Staff-PWA SP1 — scoped new-order push fan-out: restaurant-scoped (never another
 * restaurant's staff), respects notify_new_order pref, prunes dead endpoints.
 */
class StaffPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 2); // P1-04 5b: fixture store ids must be real stores
    }

    private function sub(int $storeId, array $attrs = []): PushSubscription
    {
        $endpoint = 'https://push.test/'.Str::random(12);

        return PushSubscription::create(array_merge([
            'store_id' => $storeId,
            'staff_id' => null,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'p', 'auth_token' => 'a',
            'notify_new_order' => true,
        ], $attrs));
    }

    public function test_push_is_scoped_to_the_restaurant(): void
    {
        $this->sub(1);
        $this->sub(1);
        $this->sub(2); // another restaurant — must never receive

        $sent = new StaffPushService()->sendNewOrder(1, ['title' => 'New order']);
        $this->assertSame(2, $sent); // only restaurant 1's two subs
    }

    public function test_pref_off_is_skipped(): void
    {
        $this->sub(1);
        $this->sub(1, ['notify_new_order' => false]);

        $sent = new StaffPushService()->sendNewOrder(1, []);
        $this->assertSame(1, $sent);
    }

    public function test_dead_endpoint_is_pruned(): void
    {
        $this->sub(1);
        $failing = new class implements WebPushSender
        {
            public function send($sub, array $payload): bool
            {
                return false; // simulate 410 Gone
            }
        };

        $sent = new StaffPushService($failing)->sendNewOrder(1, []);
        $this->assertSame(0, $sent);
        $this->assertNotNull(PushSubscription::first()->failed_at);

        // pruned sub excluded on the next run
        $again = new StaffPushService()->sendNewOrder(1, []);
        $this->assertSame(0, $again);
    }
}
