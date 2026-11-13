<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderApiService;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H6: order creation writes the order, its lines, combos and pricing inside ONE
 * transaction on BOTH paths (OrderApiService::create and StoreController::submitOrder),
 * and the external side effects (metering, webhook, staff push, Meta CAPI, print)
 * fire only AFTER commit. A mid-flight throw must leave no row; a rolled-back order
 * must fire no kitchen/webhook side effect; and — since the push used to fire before
 * the item loop — the staff push body must now carry the real basket total.
 */
class OrderCreateAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $r, float $price = 10): Menu
    {
        return Menu::create(['store_id' => $r->id, 'name' => 'Dish '.Str::random(4), 'price' => $price, 'active' => 1]);
    }

    /** Fail the SECOND order_item insert, deterministically. */
    private function throwOnSecondLine(): void
    {
        $n = 0;
        Event::listen('eloquent.created: '.OrderItem::class, function () use (&$n) {
            if (++$n === 2) {
                throw new \RuntimeException('boom on the second line');
            }
        });
    }

    private function storefrontPayload(Store $r, array $items): array
    {
        return [
            'items' => json_encode($items),
            'name' => 'Ada',
            'phone-number' => '55-'.rand(1000, 9999),
            'ordering-type' => 'takeaway',
            'pay_via' => 'cash',
            'total' => '0',
        ];
    }

    public function test_api_order_creation_rolls_back_completely_when_a_line_fails(): void
    {
        $r = $this->storeFixture();
        $i1 = $this->item($r, 10);
        $i2 = $this->item($r, 5);
        $this->throwOnSecondLine();

        try {
            OrderApiService::create((int) $r->user_id, $r->id, [
                'type' => 'takeaway',
                'items' => [['item_id' => $i1->id, 'quantity' => 1], ['item_id' => $i2->id, 'quantity' => 1]],
            ]);
            $this->fail('create() should have thrown');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(0, Order::count(), 'a failed order must leave no row');
        $this->assertSame(0, OrderItem::count());

        // H6 UI backing: the rollback is counted so the dashboard "Failed checkouts"
        // tile, the superadmin abuse-guard column and the reseller usage column can
        // surface it. recordCreateFailure runs AFTER the rollback, so it survives.
        $this->assertSame(1, (int) TenantUsageDaily::where('user_id', $r->user_id)->where('metric', 'orders_failed')->sum('value'));
        $this->assertGreaterThan(0, PlatformMetrics::todayCount('order.create_failed'));
    }

    public function test_storefront_checkout_rolls_back_completely_when_a_line_fails(): void
    {
        $r = $this->storeFixture();
        $i1 = $this->item($r, 10);
        $i2 = $this->item($r, 5);
        $this->throwOnSecondLine();
        $this->withoutExceptionHandling();

        try {
            $this->post(route('store.submitOrder', $r), $this->storefrontPayload($r, [
                ['id' => $i1->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
                ['id' => $i2->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
            ]));
            $this->fail('submitOrder should have thrown');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
    }

    public function test_a_rolled_back_order_fires_no_kitchen_or_webhook_side_effects(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $i1 = $this->item($r, 10);
        $i2 = $this->item($r, 5);
        $this->throwOnSecondLine();
        $this->withoutExceptionHandling();

        try {
            $this->post(route('store.submitOrder', $r), $this->storefrontPayload($r, [
                ['id' => $i1->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
                ['id' => $i2->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
            ]));
        } catch (\Throwable $e) {
            // expected
        }

        Queue::assertNotPushed(SendPushNotificationJob::class);
    }

    public function test_staff_push_body_carries_the_real_basket_total(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $item = $this->item($r, 12);

        $this->post(route('store.submitOrder', $r), $this->storefrontPayload($r, [
            ['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
        ]))->assertOk();

        Queue::assertPushed(SendPushNotificationJob::class, fn ($job) => str_contains($job->body, '12.00'));
    }

    public function test_happy_path_still_creates_order_items_combos_and_pricing(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r, 20);
        Coupon::create([
            'code' => 'OFF20', 'percentage' => 20, 'limit' => 99, 'used' => 0,
            'user_id' => $r->user_id, 'coupon_type' => 'percentage', 'issuer_scope' => 'owner',
            'applies_to' => 'food_order', 'expiry_at' => now()->addMonth(),
        ]);

        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
            'coupon_code' => 'OFF20',
        ]);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $order->refresh();
        $this->assertNotNull($order->coupon_id);
        $this->assertEqualsWithDelta(4.0, (float) $order->discount_amount, 0.001, '20% of 20');
        $this->assertNotNull($order->grand_total, 'snapshot inside the transaction');
    }

    public function test_duplicate_client_uuid_still_short_circuits_without_refiring_side_effects(): void
    {
        Queue::fake();
        $r = $this->storeFixture();
        $item = $this->item($r, 10);
        $payload = [
            'type' => 'takeaway',
            'client_uuid' => 'dupe-uuid-'.rand(1000, 9999),
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ];

        $first = OrderApiService::create((int) $r->user_id, $r->id, $payload);
        $second = OrderApiService::create((int) $r->user_id, $r->id, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
        Queue::assertPushed(SendStaffOrderPushJob::class, 1);
    }
}
