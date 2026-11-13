<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\StaffPush\FcmPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\NullWebPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\StaffPush\VapidWebPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\WebPushSender;
use Tests\TestCase;

/**
 * PARITY-RUN T9 — the regression guard for the P0 bug.
 *
 * `StaffPushService::__construct` has always resolved `WebPushSender` from the
 * container and fallen back to {@see NullWebPushSender} when nothing was bound.
 * Nothing ever WAS bound — in any ServiceProvider or config — so the null
 * sender, which reports success and delivers nothing, ran in production and
 * staff push had never sent a notification. Every test in this file exists so
 * that cannot silently come back: a green suite hid this for months precisely
 * because the null sender returns true.
 */
class MobilePushBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(11, 12, 13, 14); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    private function sub(int $storeId, array $attrs = []): PushSubscription
    {
        $endpoint = $attrs['endpoint'] ?? 'https://push.test/'.Str::random(12);

        return PushSubscription::create(array_merge([
            'store_id' => $storeId,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'p', 'auth_token' => 'a',
            'platform' => 'web',
            'notify_new_order' => true,
        ], $attrs));
    }

    /** THE regression assertion: a real sender is bound, and it is not the null stub. */
    public function test_a_real_web_push_sender_is_bound_in_the_container(): void
    {
        $this->assertTrue(
            app()->bound(WebPushSender::class),
            'Nothing binds WebPushSender — StaffPushService silently falls back to NullWebPushSender, which delivers nothing.'
        );

        $sender = app(WebPushSender::class);
        $this->assertInstanceOf(WebPushSender::class, $sender);
        $this->assertNotInstanceOf(
            NullWebPushSender::class,
            $sender,
            'WebPushSender resolves to the null stub — push would report success and deliver nothing.'
        );
        $this->assertInstanceOf(FcmPushSender::class, $sender);
    }

    /** …and the service actually picks that binding up when constructed bare. */
    public function test_staff_push_service_uses_the_bound_sender(): void
    {
        $this->assertNotInstanceOf(NullWebPushSender::class, new StaffPushService()->sender());

        // Behavioural half: a spy bound in the container must receive the send.
        $spy = new class implements WebPushSender
        {
            public int $calls = 0;

            public function send(PushSubscription $sub, array $payload): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->app->instance(WebPushSender::class, $spy);

        $this->sub(11);
        $this->assertSame(1, new StaffPushService()->sendNewOrder(11, ['title' => 'New order']));
        $this->assertSame(1, $spy->calls);
    }

    /**
     * The multi-channel sender routes by platform: a web subscription must reach
     * the VAPID sender, never Firebase.
     */
    public function test_web_subscriptions_route_to_the_vapid_sender(): void
    {
        Http::fake();

        $spy = new class extends VapidWebPushSender
        {
            public int $calls = 0;

            public function send(PushSubscription $sub, array $payload): bool
            {
                $this->calls++;

                return true;
            }
        };

        $sender = new FcmPushSender($spy);
        $this->assertTrue($sender->send($this->sub(12), ['title' => 'hi']));
        $this->assertSame(1, $spy->calls);
        Http::assertNothingSent();
    }

    /**
     * The safety property that makes shipping the binding safe: with NO VAPID
     * keys and NO Firebase credentials, delivery is a no-op that reports SUCCESS.
     * Returning false would make the caller prune — i.e. binding a real sender on
     * an unconfigured install would have wiped every subscription in the table.
     */
    public function test_an_unconfigured_install_no_ops_instead_of_pruning(): void
    {
        config(['webpush.public_key' => '', 'webpush.private_key' => '']);
        config(['services.fcm.server_key' => '', 'services.fcm.project_id' => '']);

        $web = $this->sub(13);
        $native = PushSubscription::storeNative('tok-'.Str::random(8), 'fcm', 13);

        $sent = new StaffPushService()->sendNewOrder(13, ['title' => 'New order']);

        $this->assertSame(2, $sent);
        $this->assertNull($web->fresh()->failed_at);
        $this->assertNull($native->fresh()->failed_at);
    }

    /** A native token is not a web-push endpoint — the VAPID sender must never prune one. */
    public function test_the_vapid_sender_refuses_to_prune_a_native_token(): void
    {
        config(['webpush.public_key' => 'pub', 'webpush.private_key' => 'priv']);

        $native = PushSubscription::storeNative('raw-fcm-token', 'fcm', 14);
        $this->assertTrue(new VapidWebPushSender()->send($native, ['title' => 'x']));
    }
}
