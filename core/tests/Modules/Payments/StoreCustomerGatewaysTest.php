<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GatewayAdapterController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\StoreCustomerGateways;
use Juwri\Modules\Payments\Services\StorePayments;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-23 — "Your customers' money goes directly to your gateway account; juwri never holds it" (website, Terms 4.3).
 *
 * Regression: a diner buying a gift card, topping up store credit or paying a reservation / catering deposit was
 * shown the PLATFORM's installed gateways and charged with the PLATFORM's credentials (the adapters used the store's
 * keys only for transaction_method 'order'), so on juwri Cloud the money landed in JUWRI's account. Now these store
 * payments carry their store and are charged, like orders, on the store's own gateway.
 */
class StoreCustomerGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        return $store;
    }

    public function test_the_adapter_list_matches_the_gateway_adapter_controllers(): void
    {
        $adapters = [];
        foreach (glob(app_path('Modules/Payments/Http/Controllers/User/PaymentMethods/*Controller.php')) ?: [] as $file) {
            $class = 'Juwri\\Modules\\Payments\\Http\\Controllers\\User\\PaymentMethods\\'.basename($file, '.php');
            if (is_subclass_of($class, GatewayAdapterController::class)) {
                $adapters[] = Str::snake(substr(basename($file, '.php'), 0, -strlen('Controller')));
            }
        }
        sort($adapters);
        $listed = array_merge(array_keys(StoreCustomerGateways::ADAPTER_FOLDERS), StoreCustomerGateways::PLATFORM_ONLY);
        sort($listed);
        $this->assertSame([], array_intersect(StoreCustomerGateways::PLATFORM_ONLY, array_keys(StoreCustomerGateways::ADAPTER_FOLDERS)));

        $this->assertSame($adapters, $listed, 'a gateway moved to an adapter must be listed (and only adapters charge store payments with store keys)');
    }

    public function test_only_the_store_own_adapter_gateways_are_offered(): void
    {
        $store = $this->store();
        PaymentGateway::create(['payment_folder' => 'wire_transfer', 'payment_title' => 'Platform wire', 'payment_install' => '1']);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card');
        StoreOption::updateStoreOption($store->id, 'restaurant_2checkout_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_gocardless_install', '1'); // platform-only (a mandate flow for plans)

        $gateways = app(CustomerGateways::class)->forStore((int) $store->id);

        $this->assertSame(['stripe', 'two_checkout'], $gateways->pluck('id')->all());
        $this->assertSame('Card', $gateways->first()->payment_title);
        $this->assertSame('stripe', app(CustomerGateways::class)->resolve((int) $store->id, 'stripe'));
        foreach (['gocardless', 'wire_transfer', (string) PaymentGateway::value('id'), '', 'paypal'] as $refused) {
            $this->assertNull(app(CustomerGateways::class)->resolve((int) $store->id, $refused), $refused);
        }
    }

    public function test_store_payments_use_the_store_gateway_and_platform_purchases_do_not(): void
    {
        $store = $this->store();
        foreach (StorePayments::METHODS as $method) {
            $t = new Transaction(['transaction_method' => $method, 'store_id' => $store->id]);
            $this->assertTrue(Settlement::usesStoreGateway($t), $method);
            $this->assertSame((int) $store->id, (int) Settlement::store($t)?->id, $method);

            $this->assertFalse(Settlement::usesStoreGateway(new Transaction(['transaction_method' => $method])), "$method without a store (legacy row)");
        }
        $this->assertTrue(Settlement::usesStoreGateway(new Transaction(['transaction_method' => 'order', 'store_id' => $store->id])));
        $this->assertFalse(Settlement::usesStoreGateway(new Transaction(['transaction_method' => 'membership', 'store_id' => $store->id])));
    }

    public function test_a_gift_card_is_charged_on_the_store_stripe_account_and_the_buyer_returns_to_the_store(): void
    {
        $store = $this->store();
        config(['settings.stripe_secret_key' => 'sk_test_PLATFORM']);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', 'sk_test_store_own');
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_gc', 'url' => 'https://checkout.stripe.test/cs_gc', 'payment_status' => 'unpaid', 'status' => 'open'])]);

        ['transaction' => $tx] = GiftCardService::initiate((int) $store->id, 25.0, 'stripe');
        $this->assertSame((int) $store->id, (int) $tx->store_id);

        $response = StripeController::pay($tx);

        $this->assertSame('https://checkout.stripe.test/cs_gc', $response->getTargetUrl());
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_store_own')
            && $r['cancel_url'] === route('publicView', $store->slug));
        Http::assertNotSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_PLATFORM'));

        // The confirmed buyer is sent back to the store, not to the platform's account pages.
        $back = Settlement::completeForBuyer($tx->fresh());
        $this->assertSame(route('publicView', $store->slug), $back->headers->get('Location'));
    }

    public function test_a_store_credit_top_up_carries_its_store(): void
    {
        $store = $this->store();
        ['transaction' => $tx] = CustomerWalletService::initiateTopup((int) $store->id, 10.0, 'stripe', 1);

        $this->assertSame((int) $store->id, (int) $tx->store_id);
        $this->assertTrue(Settlement::usesStoreGateway($tx));
        $this->assertStringNotContainsString('Plan (', Settlement::title($tx));
    }
}
