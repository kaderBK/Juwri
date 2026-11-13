<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Billing;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-09p — contract of the storefront order payment controller
 * (User\PaymentController: the diner's pay page, the gateway hand-off and
 * paySuccess), written against the code before its base-derived methods were
 * rewritten.
 */
class StorefrontOrderPaymentContractTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        view()->share(['admin_languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_page_lists_installed_gateways_in_order_with_2checkout_last_and_uses_the_store_currency(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 50.0, [], 'OMR');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card');
        StoreOption::updateStoreOption($store->id, 'restaurant_paystack_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_2checkout_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_razorpay_install', '0');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');
        StoreOption::updateStoreOption($store->id, 'currency_sign', 'ر.ع.');
        StoreOption::updateStoreOption($store->id, 'currency_pos', '0');

        $res = $this->get("/payment/{$tx->id}")->assertOk();

        $this->assertSame('store_templates.payment', $res->original->name());
        $data = $res->original->getData();
        foreach (['transaction', 'store', 'postOptions', 'theme', 'plan', 'paymentGateways'] as $key) {
            $this->assertArrayHasKey($key, $data, $key);
        }
        $this->assertSame($tx->id, $data['transaction']->id);
        $this->assertSame($store->id, $data['store']->id);
        $gateways = array_map(fn ($g) => [$g->id, $g->payment_folder, $g->payment_title], $data['paymentGateways']);
        $this->assertSame([
            ['stripe', 'stripe', 'Card'],
            ['paystack', 'paystack', 'Paystack'],
            ['two_checkout', 'two_checkout', '2Checkout'],
        ], $gateways);
        $this->assertSame('OMR', config('settings.currency_code'));
        $this->assertSame('ر.ع.', config('settings.currency_sign'));
        $this->assertSame('0', (string) config('settings.currency_pos'));
    }

    public function test_page_and_pay_404_when_the_store_owner_is_inactive_or_the_store_is_gone(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        $store->user->update(['status' => 0]);

        $this->get("/payment/{$tx->id}")->assertNotFound();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stripe'])->assertNotFound();
        $this->get('/payment/999999')->assertNotFound();
    }

    public function test_pay_hands_the_transaction_to_the_chosen_gateway(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('wallet_transfer', 20.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_wallet_transfer_syriatel_number', '0999111222');

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk()->assertSee('0999111222');
        $this->assertSame('wallet_transfer', $tx->fresh()->transaction_gatway);
    }

    public function test_pay_success_marks_the_order_paid_and_sends_the_diner_on(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 30.0);
        $tx->forceFill(['transaction_gatway' => 'stripe', 'details' => ['whatsapp_url' => 'https://wa.me/96890000000?text=Order']])->save();

        ob_start();
        PaymentController::paySuccess($tx->fresh());
        $out = (string) ob_get_clean();

        $order->refresh();
        $this->assertSame(1, (int) $order->is_paid);
        $this->assertSame('stripe', $order->payment_gateway);
        $this->assertSame('pending', $order->status);
        $this->assertEqualsWithDelta(30.0, (float) store_options($store->id, 'wallet_amount', 0), 0.001);
        $this->assertSame(Transaction::STATUS_CONSUMED, $tx->fresh()->status);

        $this->assertStringContainsString('<script', $out);
        $this->assertStringContainsString('window.open(', $out);
        $this->assertStringContainsString('96890000000', $out);
        $this->assertStringContainsString('_blank', $out);
        $this->assertStringContainsString('location.href', $out);
        $this->assertStringContainsString($order->track_token, $out);
    }

    public function test_pay_success_without_a_whatsapp_hand_off_only_redirects(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 30.0);

        ob_start();
        PaymentController::paySuccess($tx);
        $out = (string) ob_get_clean();

        $this->assertStringNotContainsString('window.open(', $out);
        $this->assertStringContainsString($order->track_token, $out);
        $this->assertSame(1, (int) Order::find($order->id)->is_paid);
    }
}
