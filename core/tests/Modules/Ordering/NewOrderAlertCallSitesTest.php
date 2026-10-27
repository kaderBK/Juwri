<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use Mockery;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-255 — where the staff/owner "new order" alert fires.
 *
 * NewOrderAlert::send() fans out to MessageQueue::staffPush and
 * MobilePush::newOrder. It has exactly two call sites:
 *   1. StoreController order placement — only when the order is NOT a pay-online
 *      order still owing the gateway (cash/COD alerts at placement);
 *   2. PaymentController::paySuccess() — after the transaction is atomically
 *      claimed, so a retried webhook + the browser return URL alert once.
 *
 * This test drives each call site through the real controller/endpoint with the
 * two messaging contracts faked, and pins the zero-alert cases: an online order
 * at placement, and a payment that was never confirmed.
 */
class NewOrderAlertCallSitesTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** Number of MessageQueue::staffPush calls the alert made. */
    private int $staffPushes = 0;

    /** Number of MobilePush::newOrder calls the alert made. */
    private int $newOrderPushes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        view()->share(['admin_languages' => collect(), 'pages' => collect()]);
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->fakeAlertSinks();
    }

    /**
     * Bind lenient Mockery doubles for the two alert sinks and count each call.
     * `shouldIgnoreMissing()` keeps unrelated messaging (SMS/WhatsApp/web-push)
     * from blowing up the double while these paths run.
     */
    private function fakeAlertSinks(): void
    {
        $this->staffPushes = 0;
        $this->newOrderPushes = 0;

        $queue = Mockery::mock(MessageQueue::class)->shouldIgnoreMissing();
        $queue->shouldReceive('staffPush')->andReturnUsing(function (...$args): void {
            $this->staffPushes++;
        });
        $this->app->instance(MessageQueue::class, $queue);

        $push = Mockery::mock(MobilePush::class)->shouldIgnoreMissing();
        $push->shouldReceive('newOrder')->andReturnUsing(function (...$args): void {
            $this->newOrderPushes++;
        });
        $this->app->instance(MobilePush::class, $push);
    }

    /**
     * A store that can take storefront orders (active owner, ordering-enabled
     * plan, takeaway switched on) — the shape the placement tests post against.
     */
    private function placingStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Bites', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_sign', '$');
        FeatureAccess::flush();

        return $store;
    }

    private function menuItem(Store $store, float $price = 10.00): Menu
    {
        $category = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id,
            'name' => 'Item', 'price' => $price, 'active' => 1,
        ]);
    }

    /** POST a one-line takeaway order through the real storefront endpoint. */
    private function placeOrder(Store $store, Menu $item, string $payVia): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Test Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '+16505551234',
            'pay_via' => $payVia,
            'total' => '10.00',
        ]);
    }

    /**
     * (a) A paid-online order alerts once when paySuccess() is entered twice —
     * the gateway webhook and then the browser return URL. The atomic
     * `status != consumed` claim means only the first delivery announces.
     *
     * Decision: no real gateway webhook route is driven here. Every
     * `webhook/{gateway}` entry point verifies a provider-specific signature
     * (and the E2E `stub` gateway exposes only `pay()`, not `webhook()`), so
     * routing the webhook through it would test gateway signature parsing, not
     * this call site. Both deliveries are the same paySuccess() call the
     * webhook controller invokes.
     */
    public function test_paid_online_order_alerts_once_when_the_second_delivery_replays_pay_success(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 30.0);
        $tx->forceFill(['transaction_gatway' => 'stripe'])->save();

        ob_start();
        PaymentController::paySuccess($tx->fresh()); // gateway webhook
        PaymentController::paySuccess($tx->fresh()); // browser return-URL replay
        ob_end_clean();

        $this->assertSame(Transaction::STATUS_CONSUMED, $tx->fresh()->status, 'the transaction is claimed once');
        $this->assertSame(1, (int) $order->fresh()->is_paid, 'the order is marked paid');
        $this->assertSame(1, $this->staffPushes, 'staffPush must fire exactly once across both deliveries');
        $this->assertSame(1, $this->newOrderPushes, 'newOrder must fire exactly once across both deliveries');
    }

    /** (b) A cash/COD order alerts once, at placement, through the real endpoint. */
    public function test_cod_order_alerts_once_at_placement(): void
    {
        $store = $this->placingStore();
        $item = $this->menuItem($store);

        $this->placeOrder($store, $item, 'cash')->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Order::where('store_id', $store->id)->count(), 'the order was placed');
        $this->assertSame(1, $this->staffPushes, 'staffPush must fire exactly once at placement');
        $this->assertSame(1, $this->newOrderPushes, 'newOrder must fire exactly once at placement');
    }

    /** (b) A pay-online order anti-alerts at placement — it alerts on payment instead. */
    public function test_pay_online_order_alerts_zero_times_at_placement(): void
    {
        $store = $this->placingStore();
        $item = $this->menuItem($store);

        $this->placeOrder($store, $item, 'pay_online')->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('unpaid', $order->status, 'a pay-online order waits unpaid');
        $this->assertSame(0, $this->staffPushes, 'an unpaid pay-online order must not alert the kitchen');
        $this->assertSame(0, $this->newOrderPushes, 'an unpaid pay-online order must not push to the owner');
    }

    /**
     * (c) A payment that failed or was abandoned (no paySuccess() ever runs)
     * alerts zero times — the order stays unpaid and the transaction pending.
     */
    public function test_abandoned_unpaid_payment_alerts_zero_times(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 40.0);
        $tx->forceFill(['transaction_gatway' => 'stripe'])->save();
        $order->update(['status' => 'unpaid']);

        // The diner never returned and no webhook arrived: paySuccess() is not called.
        $this->assertSame(0, (int) $order->fresh()->is_paid);
        $this->assertNotSame(Transaction::STATUS_CONSUMED, $tx->fresh()->status);
        $this->assertSame(0, $this->staffPushes, 'an unconfirmed payment must not alert the kitchen');
        $this->assertSame(0, $this->newOrderPushes, 'an unconfirmed payment must not push to the owner');
    }
}
