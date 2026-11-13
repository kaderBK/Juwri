<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Jobs\SendCustomerPushJob;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use Juwri\Modules\Ordering\Models\CustomerPushSubscription;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1 — order-scoped customer Web Push (closed-tab "order ready").
 *
 * Anonymous, token-scoped subscribe/unsubscribe; the status-change seam queues
 * a send on meaningful transitions; the payload is PII-free; terminal orders
 * clean up their subscriptions. VAPID keys are empty in the test env, so the
 * sender no-ops gracefully (never prunes) — exactly the unconfigured-install
 * behaviour.
 */
class CustomerPushTest extends TestCase
{
    use RefreshDatabase;

    private const SUB = ['endpoint' => 'https://push.example.com/ep/abc123', 'keys' => ['p256dh' => 'BPk_key_material_xxxxxxxxxxxxxxxxxxxx', 'auth' => 'auth_secret_xxxxxxxxxx']];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Pizza', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function order(Store $store, string $status = 'preparing'): Order
    {
        $o = new Order;
        $o->forceFill([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => $status,
            'is_paid' => 0, 'source' => 'qr', 'track_token' => Str::random(32), 'created_at' => now(),
        ])->save();

        return $o;
    }

    public function test_subscribe_and_unsubscribe_round_trip(): void
    {
        $order = $this->order($this->store());

        $this->postJson(route('store.pushSubscribe', $order->track_token), self::SUB)
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('customer_push_subscriptions', [
            'order_id' => $order->id,
            'endpoint_hash' => hash('sha256', self::SUB['endpoint']),
            'failed_at' => null,
        ]);

        $this->postJson(route('store.pushUnsubscribe', $order->track_token), ['endpoint' => self::SUB['endpoint']])
            ->assertOk();

        $this->assertDatabaseMissing('customer_push_subscriptions', [
            'endpoint_hash' => hash('sha256', self::SUB['endpoint']),
        ]);
    }

    public function test_subscribe_upserts_on_endpoint_and_never_duplicates(): void
    {
        $order = $this->order($this->store());

        $this->postJson(route('store.pushSubscribe', $order->track_token), self::SUB)->assertOk();
        $this->postJson(route('store.pushSubscribe', $order->track_token), self::SUB)->assertOk();

        $this->assertSame(1, CustomerPushSubscription::where('order_id', $order->id)->count());
    }

    public function test_bad_token_is_rejected(): void
    {
        // A token that does not resolve to an order → 404, not a silent create.
        $this->postJson(route('store.pushSubscribe', 'not-a-real-token'), self::SUB)->assertNotFound();
        $this->assertSame(0, CustomerPushSubscription::count());
    }

    public function test_terminal_order_refuses_new_subscriptions(): void
    {
        $order = $this->order($this->store(), 'completed');

        $this->postJson(route('store.pushSubscribe', $order->track_token), self::SUB)
            ->assertStatus(422);

        $this->assertSame(0, CustomerPushSubscription::count());
    }

    public function test_status_change_seam_queues_a_send_only_when_subscribed(): void
    {
        Queue::fake();
        $store = $this->store();

        // No subscription yet → the cheap gate means nothing is queued.
        $lonely = $this->order($store, 'preparing');
        $lonely->update(['status' => 'ready']);
        Queue::assertNotPushed(SendCustomerPushJob::class);

        // With a subscription, a meaningful transition queues exactly one send.
        $order = $this->order($store, 'preparing');
        CustomerPushSubscription::create([
            'order_id' => $order->id, 'store_id' => $store->id,
            'endpoint' => self::SUB['endpoint'], 'endpoint_hash' => hash('sha256', self::SUB['endpoint']),
            'p256dh' => self::SUB['keys']['p256dh'], 'auth' => self::SUB['keys']['auth'],
        ]);
        $order->update(['status' => 'ready']);

        Queue::assertPushed(SendCustomerPushJob::class, function ($job) use ($order) {
            // Payload carries only ids + a status string — no customer PII.
            return $job->orderId === $order->id && $job->status === 'ready';
        });
    }

    public function test_non_meaningful_transition_does_not_queue(): void
    {
        Queue::fake();
        $store = $this->store();
        $order = $this->order($store, 'new');
        CustomerPushSubscription::create([
            'order_id' => $order->id, 'store_id' => $store->id,
            'endpoint' => self::SUB['endpoint'], 'endpoint_hash' => hash('sha256', self::SUB['endpoint']),
            'p256dh' => self::SUB['keys']['p256dh'], 'auth' => self::SUB['keys']['auth'],
        ]);

        // new → preparing is not in the diner notify set.
        $order->update(['status' => 'preparing']);
        Queue::assertNotPushed(SendCustomerPushJob::class);
    }

    public function test_terminal_job_cleans_up_subscriptions(): void
    {
        // VAPID keys are empty in tests → the job no-ops the send but still runs
        // its terminal cleanup, so a finished order leaves no orphan rows.
        $store = $this->store();
        $order = $this->order($store, 'completed');
        CustomerPushSubscription::create([
            'order_id' => $order->id, 'store_id' => $store->id,
            'endpoint' => self::SUB['endpoint'], 'endpoint_hash' => hash('sha256', self::SUB['endpoint']),
            'p256dh' => self::SUB['keys']['p256dh'], 'auth' => self::SUB['keys']['auth'],
        ]);

        new SendCustomerPushJob($order->id, $store->id, 'completed', true)->handle();

        $this->assertSame(0, CustomerPushSubscription::where('order_id', $order->id)->count());
    }

    public function test_opted_out_customer_receives_no_inbox_or_push(): void
    {
        // Diner account switch: a signed-in customer who turned the header-bell
        // off gets neither an inbox row nor a push for their own order — even
        // with a live subscription. The opted-in customer is the control.
        Queue::fake();
        $store = $this->store();

        $optedOut = Customer::create([
            'store_id' => $store->id, 'name' => 'Opted Out', 'phone' => '900001',
            'notifications_enabled' => false,
        ]);
        $optedIn = Customer::create([
            'store_id' => $store->id, 'name' => 'Opted In', 'phone' => '900002',
            'notifications_enabled' => true,
        ]);

        $orders = [];
        foreach (['out' => $optedOut, 'in' => $optedIn] as $key => $c) {
            $o = new Order;
            $o->forceFill([
                'store_id' => $store->id, 'customer_id' => $c->id, 'type' => 'takeaway',
                'status' => 'preparing', 'is_paid' => 0, 'source' => 'qr',
                'track_token' => Str::random(32), 'created_at' => now(),
            ])->save();
            CustomerPushSubscription::create([
                'order_id' => $o->id, 'store_id' => $store->id,
                'endpoint' => 'https://push.example.com/ep/'.$c->id,
                'endpoint_hash' => hash('sha256', 'https://push.example.com/ep/'.$c->id),
                'p256dh' => self::SUB['keys']['p256dh'], 'auth' => self::SUB['keys']['auth'],
            ]);
            $o->update(['status' => 'ready']); // fires inbox + push
            $orders[$key] = $o;
        }

        // Opted-out → suppressed on both channels.
        $this->assertSame(0, CustomerNotification::where('customer_id', $optedOut->id)->count());
        Queue::assertNotPushed(SendCustomerPushJob::class, fn ($job) => $job->orderId === $orders['out']->id);

        // Opted-in control → inbox row written + push queued.
        $this->assertSame(1, CustomerNotification::where('customer_id', $optedIn->id)->count());
        Queue::assertPushed(SendCustomerPushJob::class, fn ($job) => $job->orderId === $orders['in']->id);
    }

    public function test_subscribe_route_is_throttled_and_anonymous(): void
    {
        $route = Route::getRoutes()->getByName('store.pushSubscribe');
        $this->assertNotNull($route);
        $mw = $route->gatherMiddleware();
        $this->assertContains('throttle:public-check', $mw);
        // Anonymous surface — never a feature:/entitled: gate (subject would be wrong).
        foreach ($mw as $m) {
            $this->assertStringStartsNotWith('feature:', $m);
            $this->assertStringStartsNotWith('entitled:', $m);
        }
    }
}
