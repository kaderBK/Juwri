<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Services\OrderBoard\DailyTokenService;
use Juwri\Modules\Kitchen\Services\OrderBoard\OrderBoardService;
use Juwri\Modules\Ordering\Models\DailyToken;
use Tests\TestCase;

/**
 * PARITY-RUN T2: the status listener that hangs off Order::booted()'s updated()
 * choke point — the only place every status-write path (owner dashboard, staff
 * PWA/API, DeliveryService, aggregators, KDS) already passes through.
 *
 * It is additive by contract: it may number an order and stamp a missing
 * timestamp, and it may never change status, break a transition, or run at all
 * for a store without a board.
 */
class OrderBoardListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_order_board' => true]);
        DailyTokenService::clearMemo();
        FeatureAccess::flush();
    }

    private function storeFixture(array $options = []): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options + ['order_board_enabled' => '1'] as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    public function test_an_order_placed_before_the_board_was_switched_on_is_numbered_when_the_kitchen_starts_it(): void
    {
        $r = $this->storeFixture(['order_board_enabled' => '0']);
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        $this->assertNull($order->fresh()->token_number, 'no board yet ⇒ no number');

        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');

        $order->update(['status' => 'preparing']);

        $this->assertSame(1, (int) $order->fresh()->token_number);
    }

    public function test_the_ready_transition_stamps_ready_at_when_a_write_path_forgot_to(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        $this->assertNull($order->fresh()->ready_at);

        $order->update(['status' => 'ready']); // a bare status write, no timestamps

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->ready_at, 'the Ready column recycles off ready_at');
        $this->assertSame('ready', $fresh->status);
    }

    public function test_an_existing_timestamp_is_never_overwritten(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        $stamped = now()->subMinutes(30);
        $order->forceFill(['ready_at' => $stamped])->saveQuietly();

        $order->update(['status' => 'ready']);

        $this->assertSame($stamped->format('Y-m-d H:i'), $order->fresh()->ready_at->format('Y-m-d H:i'));
    }

    /** 'new' means queued, not cooking — stamping preparing_at there would skew ETAs. */
    public function test_the_queued_status_does_not_stamp_preparing_at(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);

        $order->update(['status' => 'new']);

        $this->assertNull($order->fresh()->preparing_at);
    }

    public function test_the_listener_is_inert_for_a_store_without_a_board(): void
    {
        $r = $this->storeFixture(['order_board_enabled' => '0']);
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);

        $order->update(['status' => 'ready']);

        $fresh = $order->fresh();
        $this->assertNull($fresh->token_number);
        $this->assertNull($fresh->ready_at);
        $this->assertSame('ready', $fresh->status);
    }

    /** A failure inside the board listener must never break a status transition. */
    public function test_a_listener_failure_cannot_break_a_transition(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);

        // Make the board explode from inside the hook.
        DailyToken::updating(fn () => throw new \RuntimeException('boom'));
        DailyToken::creating(fn () => throw new \RuntimeException('boom'));

        $order->update(['status' => 'ready']);

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_a_terminal_status_does_not_hand_out_a_number(): void
    {
        $r = $this->storeFixture(['order_board_enabled' => '0']);
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');

        $order->update(['status' => 'cancelled']);

        $this->assertNull($order->fresh()->token_number);
    }

    public function test_status_changed_is_idempotent_across_repeated_transitions(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        $token = (int) $order->fresh()->token_number;

        foreach (['new', 'preparing', 'ready', 'preparing', 'ready'] as $status) {
            $order->update(['status' => $status]);
        }

        $this->assertSame($token, (int) $order->fresh()->token_number);
        $this->assertSame(1, DailyToken::where('store_id', $r->id)->value('last_seq'));
    }

    public function test_settings_expose_the_owner_configuration(): void
    {
        $r = $this->storeFixture([
            'order_board_ready_minutes' => '12',
            'order_board_chime' => '0',
            'order_board_type_delivery' => '1',
        ]);

        $settings = OrderBoardService::settings((int) $r->id);

        $this->assertTrue($settings['enabled']);
        $this->assertSame(12, $settings['ready_minutes']);
        $this->assertFalse($settings['chime']);
        $this->assertContains('delivery', $settings['types']);
        $this->assertContains('takeaway', $settings['types']);
        $this->assertNotContains('room_service', $settings['types']);
    }

    public function test_an_out_of_range_recycle_setting_falls_back_to_the_default(): void
    {
        $r = $this->storeFixture(['order_board_ready_minutes' => '99999']);

        $this->assertSame(
            OrderBoardService::DEFAULT_READY_MINUTES,
            OrderBoardService::settings((int) $r->id)['ready_minutes']
        );
    }
}
