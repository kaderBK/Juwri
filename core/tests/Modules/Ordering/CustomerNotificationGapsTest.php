<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Jobs\SendCustomerPushJob;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use Juwri\Modules\Ordering\Models\CustomerPushSubscription;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Lane A — notification-center gap fixes. A1: inbox/push strings render under
 * the ORDER's stored locale (orders.locale), never the ambient (staff) locale
 * of whatever session flipped the status.
 */
class CustomerNotificationGapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
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

    private function order(Store $store, Customer $customer, ?string $locale, string $status = 'preparing'): Order
    {
        $o = new Order;
        $o->forceFill([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'type' => 'takeaway',
            'status' => $status, 'is_paid' => 0, 'source' => 'qr',
            'track_token' => Str::random(32), 'locale' => $locale, 'created_at' => now(),
        ])->save();

        return $o;
    }

    private function customer(Store $store): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'C'.Str::random(4), 'phone' => UniquePhone::next('55-')]);
    }

    public function test_inbox_row_renders_in_the_orders_locale_not_the_staff_locale(): void
    {
        $store = $this->store();
        $order = $this->order($store, $this->customer($store), 'ar');

        // Arabic expectation rendered under ar via the translator itself.
        app()->setLocale('ar');
        $expected = ___('Your order is ready.');
        app()->setLocale('en');

        $order->update(['status' => 'ready']); // real Order::updated hook

        $row = CustomerNotification::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame($expected, $row->body);
        $this->assertNotSame(___('Your order is ready.'), $row->body, 'ar translation missing — test would be vacuous');

        // Ambient locale restored after the hook.
        $this->assertSame('en', app()->getLocale());
    }

    // ── A2: "preparing" → inbox only, push list unchanged ────────────────

    public function test_preparing_creates_inbox_row_but_never_pushes(): void
    {
        Queue::fake();
        $store = $this->store();
        $order = $this->order($store, $this->customer($store), null, 'pending');
        CustomerPushSubscription::create([
            'order_id' => $order->id, 'store_id' => $store->id,
            'endpoint' => 'https://push.example.com/ep/x', 'endpoint_hash' => hash('sha256', 'https://push.example.com/ep/x'),
            'p256dh' => 'k', 'auth' => 'a',
        ]);

        $order->update(['status' => 'preparing']);

        $row = CustomerNotification::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(___('Your order is being prepared.'), $row->body);
        Queue::assertNotPushed(SendCustomerPushJob::class);

        // preparing → ready keeps the existing behavior: row AND push.
        $order->update(['status' => 'ready']);
        $this->assertSame(2, CustomerNotification::where('order_id', $order->id)->count());
        Queue::assertPushed(SendCustomerPushJob::class, fn ($j) => $j->status === 'ready');
    }

    // ── A9: guest-only recorder gate (+ A8 stamp shipped in same partial) ─

    public function test_track_recorder_renders_for_guests_only(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $order = $this->order($store, $customer, null);
        $vars = ['order' => $order, 'store' => $store];

        $guest = view('store_templates._partials.track-poll', $vars)->render();
        $this->assertStringContainsString('mb_recent_orders_', $guest);
        $this->assertStringContainsString('new Date().toISOString()', $guest); // A8 event-time stamp

        auth('customer')->login($customer);
        $authed = view('store_templates._partials.track-poll', $vars)->render();
        $this->assertStringNotContainsString('mb_recent_orders_', $authed);
        auth('customer')->logout();
    }

    // ── A7: dedup consecutive identical rows ─────────────────────────────

    public function test_status_retransition_does_not_duplicate_the_inbox_row(): void
    {
        $store = $this->store();
        $order = $this->order($store, $this->customer($store), null, 'preparing');

        $order->update(['status' => 'ready']);
        // Quiet revert (no hook), then back to ready via the real hook.
        $order->forceFill(['status' => 'preparing'])->saveQuietly();
        $order->update(['status' => 'ready']);

        $this->assertSame(1, CustomerNotification::where('order_id', $order->id)->count());
    }

    public function test_distinct_consecutive_statuses_all_record(): void
    {
        $store = $this->store();
        $order = $this->order($store, $this->customer($store), null, 'pending');

        $order->update(['status' => 'ready']);
        $order->update(['status' => 'preparing']);
        $order->update(['status' => 'ready']);

        $this->assertSame(3, CustomerNotification::where('order_id', $order->id)->count());
    }

    // ── A6: retention prune ──────────────────────────────────────────────

    public function test_prune_command_enforces_retention_cap_and_orphans(): void
    {
        $store = $this->store();
        $c1 = $this->customer($store);
        $c2 = $this->customer($store);
        $order = $this->order($store, $c1, null);

        $mk = fn (int $customerId, array $extra = []) => CustomerNotification::create(array_merge([
            'customer_id' => $customerId, 'store_id' => $store->id, 'order_id' => $order->id,
            'type' => 'order_status', 'title' => 'T', 'body' => 'B',
        ], $extra));

        // Old read row (goes) + recent read row (stays) + recent unread (stays).
        $old = $mk($c2->id);
        $old->forceFill(['read_at' => now()->subDays(91)])->saveQuietly();
        $recentRead = $mk($c2->id);
        $recentRead->forceFill(['read_at' => now()->subDay()])->saveQuietly();
        $unread = $mk($c2->id);

        // 205 rows for c1 → capped to 200 newest.
        for ($i = 0; $i < 205; $i++) {
            $mk($c1->id);
        }
        $newestC1 = CustomerNotification::where('customer_id', $c1->id)->max('id');

        // Orphans: dead customer id + dead order id.
        $orphanCustomer = $mk($c2->id);
        $orphanCustomer->forceFill(['customer_id' => 999999])->saveQuietly();
        $orphanOrder = $mk($c2->id, ['order_id' => 888888]);

        $this->artisan('notifications:prune-customer')->assertExitCode(0);

        $this->assertDatabaseMissing('customer_notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('customer_notifications', ['id' => $recentRead->id]);
        $this->assertDatabaseHas('customer_notifications', ['id' => $unread->id]);
        $this->assertSame(200, CustomerNotification::where('customer_id', $c1->id)->count());
        $this->assertDatabaseHas('customer_notifications', ['id' => $newestC1]); // newest kept
        $this->assertDatabaseMissing('customer_notifications', ['id' => $orphanCustomer->id]);
        $this->assertDatabaseMissing('customer_notifications', ['id' => $orphanOrder->id]);
    }

    public function test_inbox_row_without_stored_locale_uses_ambient_locale(): void
    {
        $store = $this->store();
        $order = $this->order($store, $this->customer($store), null);

        $order->update(['status' => 'ready']);

        $row = CustomerNotification::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(___('Your order is ready.'), $row->body);
        $this->assertSame('en', app()->getLocale());
    }
}
