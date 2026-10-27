<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Ordering\Services\Orders\NewOrderAlert;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Browser\Support\Fixtures;
use Tests\TestCase;

/** P6-255 / P6-256: new-order and scheduled-release alerts speak the store currency; one alert per order. */
class NewOrderAlertTest extends TestCase
{
    use RefreshDatabase;

    private array $staff = [];

    private array $owner = [];

    private function store(string $currency, string $sign): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $s = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($s->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($s->id, 'currency_sign', $sign);
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);

        return $s;
    }

    private function fakes(): void
    {
        $this->staff = [];
        $this->owner = [];
        $q = \Mockery::mock(MessageQueue::class);
        $q->shouldReceive('staffPush')->andReturnUsing(function ($r, $t, $b) {
            $this->staff[] = [$t, $b];
        });
        $this->app->instance(MessageQueue::class, $q);
        $m = \Mockery::mock(MobilePush::class);
        $m->shouldReceive('newOrder')->andReturnUsing(function ($o, $b = null) {
            $this->owner[] = $b;
        });
        $this->app->instance(MobilePush::class, $m);
    }

    private function order(Store $s, array $extra = [], float $price = 12.5): Order
    {
        $o = Order::forceCreate(array_merge(['store_id' => $s->id, 'status' => 'pending', 'source' => 'qr', 'type' => 'takeaway', 'created_at' => now()], $extra));
        OrderItem::create(['order_id' => $o->id, 'item_id' => Fixtures::item($s, 'Item '.Str::random(3), $price)->id, 'quantity' => 1, 'variation' => 0]);

        return $o;
    }

    public function test_alert_uses_the_store_currency(): void
    {
        $s = $this->store('KWD', 'KD');
        $this->fakes();
        NewOrderAlert::send($this->order($s, [], 1.125));

        $this->assertCount(1, $this->staff);
        $this->assertStringContainsString('KD', $this->staff[0][1]);
        $this->assertStringNotContainsString('$', $this->staff[0][1]);
        $this->assertCount(1, $this->owner);
    }

    public function test_scheduled_pending_order_is_not_announced_now(): void
    {
        $s = $this->store('SAR', 'SAR');
        $this->fakes();
        NewOrderAlert::send($this->order($s, ['scheduled_at' => now()->addHours(3)]));

        $this->assertSame([], $this->staff);
        $this->assertSame([], $this->owner);
    }

    public function test_scheduled_release_alert_is_in_store_currency(): void
    {
        $s = $this->store('KWD', 'KD');
        $this->fakes();
        $o = $this->order($s, ['scheduled_at' => now()->subMinute()], 1.125);

        $this->assertTrue((new SchedulingService)->release($o));

        $this->assertCount(1, $this->staff);
        $body = $this->staff[0][1];
        $this->assertStringContainsString(store_money_plain(1.125, $s->id), $body); // P6-213 store money format (P6-256: store currency)
        $this->assertStringContainsString('1.125', $body);
        $this->assertStringContainsString('KD', $body);
        $this->assertStringNotContainsString('$', $body);
    }
}
