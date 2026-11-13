<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use App\Models\Reservation;
use Juwri\Core\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS E10 — the six new webhook events fire with the pinned names, only reach
 * subscribed endpoints, and the subscribable list carries them.
 */
class WebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware();
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
    }

    private function store(User $owner): Store
    {
        $t = 'S'.Str::random(5);
        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1']);
    }

    private function subscribe(User $owner, array $events): WebhookEndpoint
    {
        return WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://x.test/hook', 'secret' => 's3cr3t', 'events' => $events, 'active' => true]);
    }

    private function assertFired(string $event): void
    {
        Bus::assertDispatched(DeliverWebhookJob::class, fn ($j) => $j->event === $event);
    }

    public function test_selectable_events_include_the_six(): void
    {
        foreach (['refund.completed', 'reservation.created', 'reservation.cancelled', 'customer.created', 'shift.opened', 'shift.closed'] as $e) {
            $this->assertContains($e, WebhookEndpoint::SELECTABLE_EVENTS);
        }
    }

    public function test_reservation_created_fires(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->subscribe($owner, ['reservation.created']);

        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $store->id, 'name' => 'G', 'party_size' => 2, 'reserved_at' => '2027-06-01 19:00:00',
        ])->assertRedirect();

        $this->assertFired('reservation.created');
    }

    public function test_reservation_cancelled_fires(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->subscribe($owner, ['reservation.cancelled']);
        $res = Reservation::create(['store_id' => $store->id, 'name' => 'G', 'party_size' => 2, 'reserved_at' => now()->addDay(), 'status' => 'confirmed', 'token' => Str::random(40)]);

        $this->actingAs($owner)->post(route('reservations.status', $res->id), ['status' => 'cancelled'])->assertRedirect();

        $this->assertFired('reservation.cancelled');
    }

    public function test_customer_created_fires(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->subscribe($owner, ['customer.created']);

        Customer::create(['store_id' => $store->id, 'name' => 'New Guest']);

        $this->assertFired('customer.created');
    }

    public function test_refund_completed_fires(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->subscribe($owner, ['refund.completed']);
        $order = Order::factory()->create(['store_id' => $store->id, 'is_paid' => 1]);
        // total/amount_due are computed from items — give it a $100 line.
        $menu = \Juwri\Modules\Menu\Models\Menu::create(['store_id' => $store->id, 'name' => 'X', 'price' => 100]);
        \App\Models\OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'unit_price' => 100]);

        PosService::refund($order->fresh(), null, 40.0, 'cash', 'test refund');

        $this->assertFired('refund.completed');
    }

    /** HPMS §8-P1: item-level refunds must fire refund.completed like amount-mode. */
    public function test_item_refund_fires_refund_completed(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->subscribe($owner, ['refund.completed']);
        $order = Order::factory()->create(['store_id' => $store->id, 'is_paid' => 1]);
        $menu = \Juwri\Modules\Menu\Models\Menu::create(['store_id' => $store->id, 'name' => 'X', 'price' => 100]);
        $oi = \App\Models\OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'unit_price' => 100]);

        PosService::refundItems($order->fresh(), [['order_item_id' => $oi->id, 'qty' => 1, 'amount' => 100.0]], 'test', null, 'cash', (string) \Illuminate\Support\Str::uuid());

        $this->assertFired('refund.completed');
    }

    public function test_shift_events_fire(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->subscribe($owner, ['shift.opened', 'shift.closed']);

        $shift = PosService::openShift($store, null, null, 100.0);
        $this->assertFired('shift.opened');

        PosService::closeShift($shift, 100.0);
        $this->assertFired('shift.closed');
    }

    public function test_no_event_when_unsubscribed(): void
    {
        Bus::fake();
        $owner = $this->owner();
        $store = $this->store($owner);
        // Subscribed to a DIFFERENT event only.
        $this->subscribe($owner, ['order.created']);

        Customer::create(['store_id' => $store->id, 'name' => 'Nobody']);

        Bus::assertNotDispatched(DeliverWebhookJob::class);
    }
}
