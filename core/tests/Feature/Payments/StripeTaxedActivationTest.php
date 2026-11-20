<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B1b (port of the old platform's taxed-Stripe fix): with Managed Payments Stripe adds VAT / sales tax on top of our
 * pre-tax plan price, so a 9.00 USD plan is paid as 10.35. The one-time Checkout check compared that total with 9.00 and
 * the plan never activated. It now also accepts the total less the tax Stripe reports on the same session; a real
 * underpayment still activates nothing. An invoice without a subscription (one per one-time Managed Payments checkout)
 * is acknowledged and ignored.
 */
class StripeTaxedActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config([
            'settings.stripe_secret_key' => 'sk_test_fake_platform',
            'settings.stripe_webhook_secret' => 'whsec_fake',
            'settings.currency_code' => 'USD',
            'settings.stripe_payment_mode' => 'one_time',
        ]);
    }

    /** A pending one-time plan purchase of 9.00 USD, paid through the Checkout session `$sessionId`. */
    private function pendingPlanPurchase(string $sessionId): Transaction
    {
        $plan = TestPlans::create(['name' => 'Plan '.Str::random(5), 'description' => 'x', 'monthly_price' => 9, 'annual_price' => 90,
            'lifetime_price' => 27, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 1])]);
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Transaction::create(['product_name' => $plan->name, 'product_id' => TestPlans::productId($plan) ?? 0, 'user_id' => $user->id,
            'base_amount' => 9, 'amount' => 9, 'currency_code' => 'USD', 'transaction_method' => 'membership',
            'transaction_description' => 'Plan payment', 'frequency' => 'MONTHLY', 'payment_id' => $sessionId, 'status' => null]);
    }

    /** @return array<string, mixed> */
    private function paidSession(string $id, int $total, int $tax): array
    {
        return ['id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'status' => 'complete',
            'amount_total' => $total, 'currency' => 'usd', 'payment_intent' => 'pi_'.$id,
            'total_details' => ['amount_discount' => 0, 'amount_shipping' => 0, 'amount_tax' => $tax]];
    }

    /** @param  array<string, mixed>  $object */
    private function postEvent(string $type, array $object): TestResponse
    {
        $payload = (string) json_encode(['id' => 'evt_'.Str::random(10), 'type' => $type, 'data' => ['object' => $object]]);
        $t = time();

        return $this->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake')], $payload);
    }

    private function planActivated(Transaction $tx): bool
    {
        return DB::table('upgrades')->where('user_id', $tx->user_id)->exists();
    }

    public function test_a_taxed_checkout_webhook_activates_the_plan(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        $tx = $this->pendingPlanPurchase('cs_taxed');

        // 9.00 plan + 1.35 tax (15%) that Stripe added on top.
        $this->postEvent('checkout.session.completed', $this->paidSession('cs_taxed', 1035, 135))->assertOk();

        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()?->status);
        $this->assertTrue($this->planActivated($tx));
    }

    public function test_a_taxed_checkout_return_activates_the_plan(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        $tx = $this->pendingPlanPurchase('cs_taxed_ret');
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_taxed_ret' => Http::response($this->paidSession('cs_taxed_ret', 1080, 180))]);

        $this->get(route('ipn', 'stripe').'?payment_id=cs_taxed_ret&pay_mode=one_time')->assertRedirect();

        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()?->status);
        $this->assertTrue($this->planActivated($tx));
    }

    public function test_an_underpaid_checkout_does_not_activate_even_with_tax_reported(): void
    {
        $tx = $this->pendingPlanPurchase('cs_short');

        // 5.00 paid, 0.65 of it tax: neither the total nor the pre-tax 4.35 is 9.00.
        $this->postEvent('checkout.session.completed', $this->paidSession('cs_short', 500, 65))->assertOk();
        // A tax figure at or above the total is ignored: 8.00 paid is compared as 8.00 only.
        $this->postEvent('checkout.session.completed', $this->paidSession('cs_short', 800, 900))->assertOk();

        $this->assertNull($tx->fresh()?->status);
        $this->assertFalse($this->planActivated($tx));
    }

    public function test_an_overpaid_taxed_checkout_whose_pre_tax_amount_differs_does_not_activate(): void
    {
        $tx = $this->pendingPlanPurchase('cs_other');

        // 12.00 paid with 1.00 tax: 11.00 before tax is not the 9.00 price.
        $this->postEvent('checkout.session.completed', $this->paidSession('cs_other', 1200, 100))->assertOk();

        $this->assertNull($tx->fresh()?->status);
        $this->assertFalse($this->planActivated($tx));
    }

    public function test_an_invoice_without_a_subscription_is_acknowledged_and_ignored(): void
    {
        $this->postEvent('invoice.paid', [
            'id' => 'in_oneoff', 'object' => 'invoice', 'amount_paid' => 1035, 'currency' => 'usd', 'subscription' => null, 'metadata' => [],
            'lines' => ['data' => [['metadata' => []]]],
        ])->assertOk()->assertJson(['message' => 'not a subscription invoice']);

        $this->assertSame(0, Transaction::query()->where('payment_id', 'like', '%in_oneoff%')->count());
    }
}
