<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Models\WaitlistEntry;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Jobs\SendMobilePushJob;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\FcmV1Client;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;
use Juwri\Modules\Messaging\Services\StaffPush\FcmPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\StaffPush\WebPushSender;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T9 — the notification-event hooks: that they fire, that they fire
 * ONCE, that the toggles and the kill-switch are honoured, and above all that a
 * failing push can never break the write that triggered it.
 */
class MobilePushEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config([
            'settings.admin_allow_mobile_app_push' => true,
            'services.fcm.server_key' => '',
            'services.fcm.project_id' => '',
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Store $r, string $status = 'new'): Order
    {
        return Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => $status,
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now(),
        ]);
    }

    private function device(Store $r, string $token = 'tok'): PushSubscription
    {
        return MobilePushService::registerDevice((int) $r->id, $token, 'fcm');
    }

    // ── order status ─────────────────────────────────────────────────────────

    public function test_an_order_status_change_notifies_the_apps(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->device($r);
        $order = $this->order($r);

        $order->update(['status' => 'preparing']);

        Queue::assertPushed(SendMobilePushJob::class, function (SendMobilePushJob $job) use ($r, $order) {
            return $job->storeId === (int) $r->id
                && $job->event === MobilePushService::EVENT_ORDER_STATUS
                && $job->payload['status'] === 'preparing'
                && $job->payload['order_id'] === (string) $order->id;
        });
    }

    /** Replays of the same transition collapse to one notification. */
    public function test_the_same_transition_is_only_notified_once(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->device($r);
        $order = $this->order($r);

        $order->update(['status' => 'preparing']);
        // A second write of the SAME status (a replayed sync, a status push from
        // an aggregator echoing our own change) must not re-alert.
        $order->forceFill(['status' => 'new'])->saveQuietly();
        $order->update(['status' => 'preparing']);

        Queue::assertPushed(SendMobilePushJob::class, 1);

        // …but a genuinely new status does notify.
        $order->update(['status' => 'ready']);
        Queue::assertPushed(SendMobilePushJob::class, 2);
    }

    public function test_no_devices_means_no_job(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->order($r)->update(['status' => 'preparing']);

        Queue::assertNotPushed(SendMobilePushJob::class);
    }

    public function test_the_per_event_toggle_silences_the_hook(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->device($r);
        StoreOption::updateStoreOption($r->id, 'mobile_push_order_status', '0');

        $this->order($r)->update(['status' => 'preparing']);

        Queue::assertNotPushed(SendMobilePushJob::class);
    }

    public function test_the_kill_switch_silences_every_hook(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->device($r);

        config(['settings.admin_allow_mobile_app_push' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->order($r)->update(['status' => 'preparing']);
        Reservation::create([
            'store_id' => $r->id, 'name' => 'Sam', 'party_size' => 2,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);

        Queue::assertNotPushed(SendMobilePushJob::class);
    }

    /**
     * THE safety property. A sender that throws must not be able to fail the
     * order transition it was triggered by — the status write has to land, and
     * the hook's own try/catch is what guarantees it.
     */
    public function test_a_throwing_push_never_breaks_an_order_transition(): void
    {
        $r = $this->storeFixture();
        $this->device($r);
        $order = $this->order($r);

        $this->app->instance(WebPushSender::class, new class implements WebPushSender
        {
            public function send(PushSubscription $sub, array $payload): bool
            {
                throw new \RuntimeException('push backend is on fire');
            }
        });

        // QUEUE_CONNECTION=sync in the test env, so the job — and the throw —
        // happens inside the same save() call.
        $order->update(['status' => 'ready']);

        $this->assertSame('ready', $order->fresh()->status);
    }

    /** The same guarantee for the reservation and waitlist writes. */
    public function test_a_throwing_push_never_breaks_a_reservation_or_waitlist_write(): void
    {
        $r = $this->storeFixture();
        $this->device($r);

        $this->app->instance(WebPushSender::class, new class implements WebPushSender
        {
            public function send(PushSubscription $sub, array $payload): bool
            {
                throw new \RuntimeException('push backend is on fire');
            }
        });

        $reservation = Reservation::create([
            'store_id' => $r->id, 'name' => 'Sam', 'party_size' => 4,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);
        $this->assertTrue($reservation->exists);
        $this->assertSame(1, Reservation::where('store_id', $r->id)->count());

        $entry = WaitlistEntry::create([
            'store_id' => $r->id, 'name' => 'Lea', 'party_size' => 2, 'status' => 'waiting',
        ]);
        $entry->update(['status' => 'notified']);
        $this->assertSame('notified', $entry->fresh()->status);
    }

    // ── other events ─────────────────────────────────────────────────────────

    public function test_a_new_reservation_notifies_the_apps(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->device($r);

        Reservation::create([
            'store_id' => $r->id, 'name' => 'Sam', 'party_size' => 4,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);

        Queue::assertPushed(SendMobilePushJob::class, fn ($job) => $job->event === MobilePushService::EVENT_RESERVATION);
    }

    /** Waitlist alerts fire on the transition INTO `notified`, not on every save. */
    public function test_waitlist_ready_fires_once_on_the_transition(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $this->device($r);

        $entry = WaitlistEntry::create([
            'store_id' => $r->id, 'name' => 'Lea', 'party_size' => 2, 'status' => 'waiting',
        ]);
        Queue::assertNotPushed(SendMobilePushJob::class);

        $entry->update(['status' => 'notified']);
        Queue::assertPushed(SendMobilePushJob::class, 1);

        // Touching the row again while still `notified` must not re-alert.
        $entry->update(['party_size' => 3]);
        Queue::assertPushed(SendMobilePushJob::class, 1);
    }

    // ── delivery ─────────────────────────────────────────────────────────────

    /** The job addresses NATIVE devices only — web subscriptions are the staff PWA's lane. */
    public function test_delivery_reaches_native_devices_only(): void
    {
        $r = $this->storeFixture();
        $this->device($r, 'native-1');
        $this->device($r, 'native-2');
        PushSubscription::store(['endpoint' => 'https://web/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], (int) $r->id);

        $seen = [];
        $this->app->instance(WebPushSender::class, new class($seen) implements WebPushSender
        {
            public function __construct(public array &$seen) {}

            public function send(PushSubscription $sub, array $payload): bool
            {
                $this->seen[] = $sub->endpoint;

                return true;
            }
        });

        $this->assertSame(2, MobilePushService::deliver((int) $r->id, MobilePushService::EVENT_ORDER_STATUS, ['title' => 'x']));
        $this->assertSame(['native-1', 'native-2'], $seen);
    }

    /** A gone token (404/410 from FCM) is pruned; a transient failure is not. */
    public function test_a_gone_token_is_pruned_and_a_transient_failure_is_not(): void
    {
        $r = $this->storeFixture();
        $gone = $this->device($r, 'dead');

        $this->app->instance(WebPushSender::class, new class implements WebPushSender
        {
            public function send(PushSubscription $sub, array $payload): bool
            {
                return false;
            }
        });
        MobilePushService::deliver((int) $r->id, MobilePushService::EVENT_ORDER_STATUS, []);
        $this->assertNotNull($gone->fresh()->failed_at);

        $alive = $this->device($r, 'alive');
        $this->app->instance(WebPushSender::class, new class implements WebPushSender
        {
            public function send(PushSubscription $sub, array $payload): bool
            {
                return true;
            }
        });
        MobilePushService::deliver((int) $r->id, MobilePushService::EVENT_ORDER_STATUS, []);
        $this->assertNull($alive->fresh()->failed_at);
    }

    // ── FCM v1 wire format ───────────────────────────────────────────────────

    /**
     * The payload shape FCM HTTP v1 actually requires: `message.token`, string-only
     * `data` values, and the APNs block on iOS. A wrong shape is a 400 that a
     * mocked "assert something was posted" test would happily pass.
     */
    public function test_the_fcm_v1_message_shape(): void
    {
        Http::fake([
            FcmV1Client::OAUTH_URL => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1']),
        ]);

        $creds = [
            'mode' => PushCredentials::MODE_V1, 'project_id' => 'proj',
            'client_email' => 'e@x', 'private_key' => $this->realPrivateKey(),
        ];

        $status = new FcmV1Client()->send($creds, 'device-token', 'apns', [
            'title' => 'Order updated', 'body' => '#7 · ready', 'order_id' => 7,
        ]);
        $this->assertSame(200, $status);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'projects/proj/messages:send')) {
                return false;
            }
            $message = $request->data()['message'];

            return $message['token'] === 'device-token'
                && $message['notification']['title'] === 'Order updated'
                // FCM rejects non-string data values.
                && $message['data']['order_id'] === '7'
                && isset($message['apns']['payload']['aps'])
                && ! isset($message['android']);
        });
    }

    public function test_a_404_from_fcm_prunes_the_token(): void
    {
        $r = $this->storeFixture();
        $sub = $this->device($r, 'stale-token');

        Credential::create([
            'store_id' => $r->id, 'feature' => PushCredentials::FEATURE, 'provider' => 'firebase',
            'secrets' => ['project_id' => 'proj', 'client_email' => 'e@x', 'private_key' => $this->realPrivateKey()],
            'active' => true,
        ]);

        Http::fake([
            FcmV1Client::OAUTH_URL => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => 'NOT_FOUND'], 404),
        ]);

        $this->assertFalse(new FcmPushSender()->send($sub, ['title' => 'x']));

        // …and the fan-out turns that false into a prune.
        new StaffPushService(new FcmPushSender)->sendNewOrder((int) $r->id, ['title' => 'x']);
        $this->assertNotNull($sub->fresh()->failed_at);
    }

    /** A 5xx is transient: the token survives, because losing it costs the user push. */
    public function test_a_server_error_from_fcm_keeps_the_token(): void
    {
        $r = $this->storeFixture();
        $sub = $this->device($r, 'good-token');

        Credential::create([
            'store_id' => $r->id, 'feature' => PushCredentials::FEATURE, 'provider' => 'firebase',
            'secrets' => ['project_id' => 'proj', 'client_email' => 'e@x', 'private_key' => $this->realPrivateKey()],
            'active' => true,
        ]);

        Http::fake([
            FcmV1Client::OAUTH_URL => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response('boom', 503),
        ]);

        $this->assertTrue(new FcmPushSender()->send($sub, ['title' => 'x']));
        $this->assertNull($sub->fresh()->failed_at);
    }

    /** No credentials must mean no outbound call at all, not a call that 401s. */
    public function test_an_unconfigured_store_sends_nothing_over_the_wire(): void
    {
        Http::fake();
        $r = $this->storeFixture();
        $sub = $this->device($r);

        $this->assertTrue(new FcmPushSender()->send($sub, ['title' => 'x']));
        Http::assertNothingSent();
    }

    /** A throwaway RSA key so the JWT signing path is genuinely exercised. */
    private function realPrivateKey(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);

        return (string) $pem;
    }
}
