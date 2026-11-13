<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MoneyBugs3;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Bug batch 3 · F — the Apple/Google Pay sheet shows the currency and amount the order is charged in: the config
 * endpoint reports the ORDER's stamped currency, and the intent hands the sheet the exact minor units it charges.
 */
class WalletPaySheetCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public static array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        self::$stripeCalls = [];
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                WalletPaySheetCurrencyTest::$stripeCalls[] = $params;

                return [json_encode(['id' => 'pi_1', 'object' => 'payment_intent', 'client_secret' => 'pi_1_secret',
                    'amount' => $params['amount'] ?? 0, 'currency' => $params['currency'] ?? 'usd']), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function store(string $currency): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_publishable_key', 'pk_test_x');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', 'sk_test_x');

        return $store;
    }

    private function order(Store $store, array $attrs = []): Order
    {
        return Order::create(array_merge(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'tip_amount' => 3.375], $attrs));
    }

    public function test_config_reports_the_orders_currency_not_the_stores_current_one(): void
    {
        $store = $this->store('USD');
        $order = $this->order($store, ['currency_code' => 'USD', 'tip_amount' => 12.5]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD'); // store moved currency after the order

        $this->getJson("/{$store->slug}/wallet/config?order_id={$order->id}")
            ->assertOk()->assertJsonPath('currency', 'usd'); // before: 'kwd' (sheet in KWD, charge in USD)
    }

    public function test_config_without_an_order_still_reports_the_store_currency(): void
    {
        $store = $this->store('AED');
        $this->getJson("/{$store->slug}/wallet/config")->assertOk()->assertJsonPath('currency', 'aed');
    }

    public function test_intent_hands_the_sheet_the_minor_units_it_charges(): void
    {
        $store = $this->store('KWD');
        $order = $this->order($store, ['currency_code' => 'KWD', 'tip_amount' => 3.375]);

        $res = $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])->assertOk();

        // Stripe: 3-decimal minor in tens — owner decision 2026-10-01: rounded DOWN, never up (before: 3380, half-up).
        $this->assertSame(3370, (int) self::$stripeCalls[0]['amount']);
        $this->assertSame('5', self::$stripeCalls[0]['metadata']['rounding_adjustment_minor'] ?? null);
        $this->assertSame('kwd', $res->json('currency'));
        $this->assertSame(3370, $res->json('amount_minor'));               // before: 3380; earlier absent — the sheet showed 3.38 × 100 = 338 (0.338 KWD)
    }

    public function test_intent_charges_what_is_due_after_tenders(): void
    {
        $store = $this->store('USD');
        $order = $this->order($store, ['currency_code' => 'USD', 'tip_amount' => 20, 'gift_card_applied' => 5]);

        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])->assertOk();

        $this->assertSame(1500, (int) self::$stripeCalls[0]['amount']); // before: 2000 (the total, ignoring the 5.00 gift card)
    }

    public function test_the_sheet_uses_the_intents_minor_amount(): void
    {
        $html = view('partials.wallet-button', ['walletRestaurant' => 'r', 'walletOrderId' => 1])->render();

        $this->assertStringContainsString('amount: intent.amount_minor', $html);
        $this->assertStringNotContainsString('intent.amount * 100', $html);           // before: Math.round(intent.amount * 100)
        $this->assertStringContainsString('wallet\/config?order_id=1', $html);
    }
}
