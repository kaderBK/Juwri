<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\StaffPush\FcmPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\NullWebPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\StaffPush\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobilePushBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(7, 42); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    public function test_store_native_creates_fcm_subscription(): void
    {
        $sub = PushSubscription::storeNative('fcm-token-abc', 'fcm', 7, 3);
        $this->assertSame('fcm', $sub->platform);
        $this->assertSame('fcm-token-abc', $sub->endpoint);
        $this->assertSame(7, (int) $sub->store_id);

        // Re-register same token → updates, no duplicate (endpoint_hash unique).
        PushSubscription::storeNative('fcm-token-abc', 'fcm', 7, 3);
        $this->assertSame(1, PushSubscription::where('endpoint', 'fcm-token-abc')->count());
    }

    public function test_fcm_sender_routes_web_to_fallback(): void
    {
        // A web sub is delegated to the web fallback sender.
        $webSub = PushSubscription::store(['endpoint' => 'https://web/ep', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], 7, 3);

        $spyFallback = new class extends NullWebPushSender {
            public bool $called = false;
            public function send(PushSubscription $sub, array $payload): bool { $this->called = true; return true; }
        };
        $sender = new FcmPushSender($spyFallback);
        $this->assertTrue($sender->send($webSub, ['title' => 'hi']));
        $this->assertTrue($spyFallback->called);
    }

    public function test_fcm_sender_no_key_is_safe_noop_for_native(): void
    {
        config(['services.fcm.server_key' => '']);
        $fcmSub = PushSubscription::storeNative('tok', 'fcm', 7, 3);
        $sender = new FcmPushSender();
        // No creds → treated as delivered (true), never throws.
        $this->assertTrue($sender->send($fcmSub, ['title' => 'New order', 'body' => 'x']));
    }

    public function test_fanout_reaches_native_subscriptions(): void
    {
        PushSubscription::storeNative('tok-1', 'fcm', 42, 3);
        PushSubscription::store(['endpoint' => 'https://web/e', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], 42, 3);

        // The service fans out to ALL subs for the restaurant (web + native).
        $svc = new StaffPushService(new FcmPushSender());
        $sent = $svc->sendNewOrder(42, ['title' => 'New order', 'body' => 'x']);
        $this->assertSame(2, $sent);
    }
}
