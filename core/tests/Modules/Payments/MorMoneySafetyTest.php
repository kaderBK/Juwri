<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Tests\Support\MorWebhookFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-038 money safety (J72/J73, public core): captured merchant-of-record money is never dropped. A paid Paddle / Lemon
 * Squeezy checkout is marked settled (recurring_setup / checkout_settled) and kept, so its renewals still find it after
 * the hourly cleanup; a renewal whose row the old cleanup deleted settles against its subscription; a refused signed
 * settlement raises an alert; a backfill marks paid checkouts left unmarked; Lemon Squeezy's 'initial' invoice does not
 * credit the first period twice; unsettled MoR checkouts survive a week; a Stripe renewal must match the amount sold.
 */
class MorMoneySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
        Carbon::setTestNow('2026-10-27 09:00:00');
        config([
            'settings.currency_code' => 'USD',
            'settings.lemon_squeezy_webhook_secret' => 'ls_whsec_test',
            'settings.paddle_api_key' => 'pdl_apikey_test', 'settings.paddle_webhook_secret' => 'pdl_whsec_test', 'settings.paddle_sandbox_mode' => 'Yes',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Transaction, 1: array<string, string>} a pending checkout and its custom data, as pay() sends it */
    private function checkout(float $amount, string $frequency = 'MONTHLY', array $extra = [], ?User $user = null): array
    {
        $plan = TestPlans::create(['name' => 'Plan '.Str::random(5), 'description' => 'x', 'monthly_price' => $amount, 'annual_price' => $amount * 10,
            'lifetime_price' => $amount * 3, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 1])]);
        $user ??= User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $tx = Transaction::create(['product_name' => $plan->name, 'product_id' => TestPlans::productId($plan) ?? 0, 'user_id' => $user->id, 'base_amount' => $amount,
            'amount' => $amount, 'currency_code' => 'USD', 'transaction_method' => 'membership', 'transaction_description' => 'Plan payment',
            'frequency' => $frequency, 'status' => null] + $extra);

        return [$tx, $this->custom($tx)];
    }

    /** @return array<string, string> */
    private function custom(Transaction $tx): array
    {
        $custom = [];
        foreach ($tx->fresh()->legacyAttributes() as $key => $value) {
            if (is_scalar($value)) {
                $custom[$key] = strval($value);
            }
        }

        return $custom;
    }

    private function lemon(string $event, array $custom, string $id, array $attributes = []): TestResponse
    {
        $signed = MorWebhookFixtures::lemonSqueezySigned(MorWebhookFixtures::lemonSqueezyEvent($event, $custom, $id, $attributes), 'ls_whsec_test');

        return $this->call('POST', route('webhook', 'lemon_squeezy'), [], [], [],
            ['HTTP_X-Signature' => $signed['headers']['X-Signature'], 'CONTENT_TYPE' => 'application/json'], $signed['body']);
    }

    private function paddle(array $custom, string $txn, ?string $subscription, string $paidTotal = '1500'): TestResponse
    {
        $event = MorWebhookFixtures::paddleTransactionCompleted($custom, $txn, $subscription);
        $event['data']['details']['totals']['total'] = $paidTotal;
        $signed = MorWebhookFixtures::paddleSigned($event, 'pdl_whsec_test');

        return $this->call('POST', route('webhook', 'paddle'), [], [], [],
            ['HTTP_PADDLE_SIGNATURE' => $signed['headers']['Paddle-Signature'], 'CONTENT_TYPE' => 'application/json'], $signed['body']);
    }

    private function alerts(string $kind): int
    {
        return DB::table('platform_alerts')->where('kind', $kind)->count();
    }

    private function stripeInvoice(array $custom, string $invoiceId, int $amountPaid, array $invoice = [], string $currency = 'usd'): TestResponse
    {
        PaymentGateway::query()->firstOrCreate(['payment_folder' => 'stripe'], ['payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake_platform', 'settings.stripe_webhook_secret' => 'whsec_fake']);
        $payload = (string) json_encode(['type' => 'invoice.paid', 'data' => ['object' => [
            'id' => $invoiceId, 'subscription' => 'sub_s', 'metadata' => [], 'amount_paid' => $amountPaid, 'currency' => $currency,
            'lines' => ['data' => [['metadata' => $custom]]],
        ] + $invoice]]);
        $t = time();

        return $this->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake')], $payload);
    }

    public function test_a_lemon_squeezy_renewal_after_the_hourly_cleanup_still_settles(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-1')->assertOk();
        $this->lemon('subscription_created', $custom, 'sub-1')->assertOk();
        $this->assertSame(Transaction::STATUS_RECURRING_SETUP, $tx->fresh()?->status, 'the checkout is marked settled');

        Carbon::setTestNow(now()->addHours(4));
        $this->artisan('cron:hourly')->assertExitCode(0);
        $this->assertNotNull($tx->fresh(), 'the cleanup keeps a settled checkout');

        Carbon::setTestNow(now()->addMonth());
        $this->lemon('subscription_payment_success', $custom, 'inv-2', ['subscription_id' => 'sub-1'])->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'inv-2')->value('status'));
    }

    public function test_a_paddle_renewal_after_the_hourly_cleanup_still_settles(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->paddle($custom, 'txn_first', 'sub_p')->assertOk();
        $this->assertSame(Transaction::STATUS_RECURRING_SETUP, $tx->fresh()?->status);

        Carbon::setTestNow(now()->addHours(4));
        $this->artisan('cron:hourly')->assertExitCode(0);

        Carbon::setTestNow(now()->addMonth());
        $this->paddle($custom, 'txn_renewal', 'sub_p')->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'txn_renewal')->value('status'));
    }

    public function test_a_one_time_merchant_of_record_checkout_is_consumed_by_its_settlement(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0, 'LIFETIME');
        $this->lemon('order_created', $custom, 'order-9')->assertOk();
        $this->assertSame(Transaction::STATUS_CHECKOUT_SETTLED, $tx->fresh()?->status);
        $this->lemon('order_created', $custom, 'order-9')->assertOk(); // a re-delivery: still found, still a no-op
        $this->assertSame(1, Transaction::where('payment_id', 'order-9')->count());
    }

    public function test_a_paddle_renewal_whose_checkout_row_was_deleted_settles_against_its_subscription(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->paddle($custom, 'txn_first', 'sub_p')->assertOk();
        $tx->delete(); // what the old 3-hour cleanup did to a paid checkout

        Carbon::setTestNow(now()->addMonth());
        $this->paddle($custom, 'txn_renewal', 'sub_p')->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'txn_renewal')->value('status'));
        $this->assertSame(0, $this->alerts('payments.mor_settlement_refused'));
    }

    public function test_a_lemon_renewal_whose_checkout_row_was_deleted_settles_against_its_subscription(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-1')->assertOk();
        $this->lemon('subscription_created', $custom, 'sub-1')->assertOk();
        $tx->delete();

        Carbon::setTestNow(now()->addMonth());
        $this->lemon('subscription_payment_success', $custom, 'inv-2', ['subscription_id' => 'sub-1', 'billing_reason' => 'renewal'])->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'inv-2')->value('status'));
    }

    public function test_a_renewal_without_its_checkout_is_bound_to_the_accounts_own_subscription_and_plan_only(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->paddle($custom, 'txn_first', 'sub_p')->assertOk();
        $tx->delete();

        // Another subscription id, or custom data naming another plan: refused, and alerted (money was captured).
        $this->paddle($custom, 'txn_other_sub', 'sub_other')->assertStatus(400);
        $this->paddle(['product_id' => (string) ((int) $custom['product_id'] + 999)] + $custom, 'txn_other_plan', 'sub_p')->assertStatus(400);
        // Custom data never sets its own price: the renewal must cover the subscription's last settled payment.
        $this->paddle(['amount' => '0.01'] + $custom, 'txn_cheap', 'sub_p', '1')->assertStatus(400);
        $this->assertSame(0, Transaction::whereIn('payment_id', ['txn_other_sub', 'txn_other_plan', 'txn_cheap'])->count());
        $this->assertSame(3, $this->alerts('payments.mor_settlement_refused'));
    }

    public function test_a_checkout_row_that_exists_but_does_not_match_never_falls_back_to_the_subscription(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->paddle($custom, 'txn_first', 'sub_p')->assertOk();
        $this->paddle(['amount' => '0.01'] + $custom, 'txn_low', 'sub_p', '1')->assertStatus(400);
        $this->assertNull(Transaction::where('payment_id', 'txn_low')->first());
    }

    public function test_a_refused_signed_settlement_alerts_but_a_redelivered_recorded_payment_does_not(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0, 'LIFETIME');
        $this->lemon('order_created', ['amount' => '1.00'] + $custom, 'order-bad')->assertStatus(400);
        $this->assertSame(1, $this->alerts('payments.mor_settlement_refused'));

        $this->lemon('order_created', $custom, 'order-ok')->assertOk();
        // A copy the receipts do not catch reaches the settlement's "already recorded" 400: no alert for that.
        $this->lemon('order_created', $custom, 'order-ok', ['updated_at' => 'again'])->assertSuccessful();
        $this->assertSame(1, $this->alerts('payments.mor_settlement_refused'));
    }

    public function test_lemons_initial_subscription_invoice_does_not_credit_the_first_period_twice(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-i')->assertOk();
        $this->lemon('subscription_created', $custom, 'sub-i')->assertOk();
        $this->lemon('subscription_payment_success', $custom, 'inv-i', ['subscription_id' => 'sub-i', 'billing_reason' => 'initial'])->assertOk();

        $this->assertSame(1, Transaction::where('status', Transaction::STATUS_SUCCESS)->where('transaction_gatway', 'lemon_squeezy')->count(),
            'one payment, one settled row');
        $this->assertSame('lemon_squeezy###sub-i', DB::table('upgrades')->where('user_id', $custom['user_id'])->value('unique_id'),
            'the subscription is kept, not cancelled');

        // The next period's invoice (billing_reason 'renewal') still credits.
        Carbon::setTestNow(now()->addMonth());
        $this->lemon('subscription_payment_success', $custom, 'inv-i2', ['subscription_id' => 'sub-i', 'billing_reason' => 'renewal'])->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'inv-i2')->value('status'));
        $this->assertSame(2, Transaction::where('status', Transaction::STATUS_SUCCESS)->where('transaction_gatway', 'lemon_squeezy')->count());
    }

    public function test_a_lemon_late_payment_on_a_kept_checkout_still_settles_and_marks_it(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0, 'LIFETIME', ['details' => ['checkout_gateway' => 'lemon_squeezy']]);
        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('cron:hourly')->assertExitCode(0);

        $this->lemon('order_created', $custom, 'order-late')->assertOk();
        $this->assertSame(Transaction::STATUS_CHECKOUT_SETTLED, $tx->fresh()->status);
    }

    public function test_a_stripe_renewal_for_another_amount_is_held_for_review_and_extends_nothing(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->stripeInvoice($custom, 'in_low', 1000)->assertOk()->assertJson(['message' => 'held for review']);
        $this->assertSame(Transaction::STATUS_REVIEW, Transaction::where('payment_id', 'review:in_low')->value('status'));
        $this->assertNotSame((string) $custom['product_id'], (string) User::find($custom['user_id'])->group_id);
        $this->assertSame(1, $this->alerts('payments.amount_mismatch'));

        $this->stripeInvoice($custom, 'in_ok', 1500)->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'in_ok')->value('status'));
        $this->assertSame((string) $custom['product_id'], (string) User::find($custom['user_id'])->group_id);
    }

    public function test_the_backfill_marks_paid_mor_checkouts_left_unmarked(): void
    {
        [$paid] = $this->checkout(15.0);
        [$paidOnce] = $this->checkout(20.0, 'LIFETIME');
        [$unpaid] = $this->checkout(25.0);
        foreach ([[$paid, 'paddle', 'txn_b1'], [$paidOnce, 'lemon_squeezy', 'order_b2']] as [$checkout, $gateway, $paymentId]) {
            $settled = $checkout->replicate(['public_id']);
            $settled->forceFill(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => $gateway, 'payment_id' => $paymentId,
                'created_at' => now()->addMinutes(5)])->save();
        }

        $migration = require base_path('app/Modules/Payments/Database/Migrations/2026_10_27_200000_mark_settled_mor_checkouts.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame(Transaction::STATUS_RECURRING_SETUP, $paid->fresh()->status);
        $this->assertSame(Transaction::STATUS_CHECKOUT_SETTLED, $paidOnce->fresh()->status);
        $this->assertNull($unpaid->fresh()->status);
    }

    public function test_unsettled_mor_checkouts_survive_a_week_and_others_go_after_three_hours(): void
    {
        [$paddle] = $this->checkout(15.0, 'MONTHLY', ['details' => ['checkout_gateway' => 'paddle', 'paddle_transaction' => 'txn_open']]);
        [$lemon] = $this->checkout(15.0, 'MONTHLY', ['details' => ['checkout_gateway' => 'lemon_squeezy']]);
        [$plain] = $this->checkout(15.0);

        Carbon::setTestNow(now()->addHours(4));
        $this->artisan('cron:hourly')->assertExitCode(0);
        $this->assertNotNull($paddle->fresh(), 'a Paddle checkout may still be paid: kept');
        $this->assertNotNull($lemon->fresh());
        $this->assertNull($plain->fresh(), 'a checkout that never reached a MoR gateway is purged as before');

        Carbon::setTestNow(now()->addDays(6));
        $this->artisan('cron:hourly')->assertExitCode(0);
        $this->assertNotNull($paddle->fresh(), 'still within the week');

        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('cron:hourly')->assertExitCode(0);
        $this->assertNull($paddle->fresh());
        $this->assertNull($lemon->fresh());
    }

    // Review fixes (fix/mor-money review) --------------------------------------------------------------------------------

    public function test_a_stripe_renewal_is_compared_before_customer_balance_and_exclusive_tax(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        // A customer-balance credit lowers amount_paid; exclusive Stripe Tax raises it — both settle.
        $this->stripeInvoice($custom, 'in_balance', 1000, ['subtotal' => 1500, 'total_excluding_tax' => 1500, 'total' => 1500])->assertOk();
        $this->stripeInvoice($custom, 'in_tax', 1800, ['subtotal' => 1500, 'total_excluding_tax' => 1500, 'total' => 1800, 'tax' => 300])->assertOk();
        // Exact match on an invoice that carries only amount_paid.
        $this->stripeInvoice($custom, 'in_exact', 1500)->assertOk();
        $this->assertSame(3, Transaction::whereIn('payment_id', ['in_balance', 'in_tax', 'in_exact'])->where('status', Transaction::STATUS_SUCCESS)->count());
        $this->assertSame(0, $this->alerts('payments.amount_mismatch'));
    }

    public function test_a_stripe_dashboard_coupon_or_a_genuine_underpayment_is_held_and_can_be_released_by_hand(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->stripeInvoice($custom, 'in_coupon', 1200, ['subtotal' => 1500, 'total_excluding_tax' => 1200,
            'total_discount_amounts' => [['amount' => 300, 'discount' => 'di_1']]])->assertOk()->assertJson(['message' => 'held for review']);
        $this->stripeInvoice($custom, 'in_short', 1000, ['subtotal' => 1000, 'total_excluding_tax' => 1000])->assertOk()->assertJson(['message' => 'held for review']);
        $this->assertSame(0, Transaction::whereIn('payment_id', ['in_coupon', 'in_short'])->count(), 'the payment ids stay free');
        $review = Transaction::where('payment_id', 'review:in_short')->sole();
        $this->assertSame(Transaction::STATUS_REVIEW, $review->status);

        // A replay of the held event alerts nothing again; the release settles it through the normal settlement.
        $alerts = $this->alerts('payments.amount_mismatch');
        $this->stripeInvoice($custom, 'in_short', 1000, ['subtotal' => 1000, 'total_excluding_tax' => 1000, 'attempt' => 2])->assertOk();
        $this->assertSame($alerts, $this->alerts('payments.amount_mismatch'));
        // Below the plan price: refused unless accepted, then booked at what Stripe collected (10.00), not 15.00.
        $this->artisan('payments:settle-held-renewal', ['transaction' => $review->id])->assertExitCode(1);
        $this->assertSame(0, Transaction::where('payment_id', 'in_short')->count());
        $this->artisan('payments:settle-held-renewal', ['transaction' => $review->id, '--accept-amount' => true])->assertExitCode(0);
        $settled = Transaction::where('payment_id', 'in_short')->sole();
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame(1000, $settled->money('amount')->minor());
        $this->assertNotNull(data_get($review->fresh()->details, 'amount_review.released_at'));
        $this->artisan('payments:settle-held-renewal', ['transaction' => $review->id, '--accept-amount' => true])->assertExitCode(0); // idempotent
        $this->assertSame(1, Transaction::where('payment_id', 'in_short')->count());
    }

    public function test_a_three_decimal_stripe_renewal_accepts_the_old_half_up_step_and_nothing_else(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(12.345);
        $custom['currency_code'] = 'KWD';
        $custom['amount'] = $custom['base_amount'] = '12.345';
        // Set up now: rounded DOWN to Stripe's 10-fils step (12.340); before that decision: half-up (12.350).
        $this->stripeInvoice($custom, 'in_kwd_down', 12340, [], 'kwd')->assertOk();
        $this->stripeInvoice($custom, 'in_kwd_up', 12350, [], 'kwd')->assertOk();
        $this->stripeInvoice($custom, 'in_kwd_off', 12360, [], 'kwd')->assertOk()->assertJson(['message' => 'held for review']);
        $this->assertSame(2, Transaction::whereIn('payment_id', ['in_kwd_down', 'in_kwd_up'])->where('status', Transaction::STATUS_SUCCESS)->count());
        $this->assertSame(Transaction::STATUS_REVIEW, Transaction::where('payment_id', 'review:in_kwd_off')->value('status'));
    }

    public function test_recovery_works_with_no_upgrade_row_through_the_stamped_settlement(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->paddle($custom, 'txn_first', 'sub_gone')->assertOk();
        $this->assertSame('sub_gone', data_get(Transaction::where('payment_id', 'txn_first')->value('details'), 'subscription_id'));
        $tx->delete();
        DB::table('upgrades')->where('user_id', $custom['user_id'])->delete(); // the plan lapsed: its Upgrade row is gone

        $this->paddle($custom, 'txn_after', 'sub_gone')->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'txn_after')->value('status'));
        // Another subscription id is still refused.
        $this->paddle($custom, 'txn_other', 'sub_else')->assertStatus(400);
    }

    public function test_lemon_recovery_works_with_no_upgrade_row(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-s')->assertOk();
        $this->lemon('subscription_created', $custom, 'sub-s', ['order_id' => 'order-s'])->assertOk();
        $tx->delete();
        DB::table('upgrades')->where('user_id', $custom['user_id'])->delete();

        $this->lemon('subscription_payment_success', $custom, 'inv-s2', ['subscription_id' => 'sub-s', 'billing_reason' => 'renewal'])->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'inv-s2')->value('status'));
    }

    public function test_a_settlement_in_progress_503_raises_no_refusal_alert(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        WebhookReceipts::claim('paddle', 'payment:txn_busy');
        WebhookReceipts::claim('lemon_squeezy', 'payment:order-busy');

        $this->paddle($custom, 'txn_busy', 'sub_b')->assertStatus(503);
        $this->lemon('order_created', $custom, 'order-busy')->assertStatus(503);
        $this->assertSame(0, $this->alerts('payments.mor_settlement_refused'));
    }

    public function test_an_initial_lemon_invoice_without_a_settled_order_is_alerted(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->lemon('subscription_payment_success', $custom, 'inv-lost', ['subscription_id' => 'sub-l', 'billing_reason' => 'initial'])->assertOk();
        $this->assertSame(1, $this->alerts('payments.lemon_initial_unsettled'));
        $this->assertSame(0, Transaction::where('payment_id', 'inv-lost')->count(), 'still nothing credited');

        // With its order settled, an initial invoice raises nothing.
        [, $paid] = $this->checkout(15.0);
        $this->lemon('order_created', $paid, 'order-ok')->assertOk();
        $this->lemon('subscription_payment_success', $paid, 'inv-ok', ['subscription_id' => 'sub-ok', 'billing_reason' => 'initial'])->assertOk();
        $this->assertSame(1, $this->alerts('payments.lemon_initial_unsettled'));
    }

    public function test_a_tax_inclusive_stripe_invoice_is_compared_on_its_total(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $inclusive = ['total_tax_amounts' => [['amount' => 250, 'inclusive' => true]]];
        // The price includes its tax: total is the price, total_excluding_tax is below it — settles.
        $this->stripeInvoice($custom, 'in_incl', 1500, ['subtotal' => 1500, 'total' => 1500, 'total_excluding_tax' => 1250] + $inclusive)->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::where('payment_id', 'in_incl')->value('status'));
        // A discount visible only in total (no total_discount_amounts): held.
        $this->stripeInvoice($custom, 'in_incl_disc', 1200, ['subtotal' => 1500, 'total' => 1200, 'total_excluding_tax' => 1000] + $inclusive)
            ->assertOk()->assertJson(['message' => 'held for review']);
        $this->assertSame(Transaction::STATUS_REVIEW, Transaction::where('payment_id', 'review:in_incl_disc')->value('status'));
    }

    public function test_the_release_command_refuses_unknown_non_review_and_unsettleable_ids(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        $this->artisan('payments:settle-held-renewal', ['transaction' => 999999])->assertExitCode(1);

        [, $custom] = $this->checkout(15.0);
        $this->stripeInvoice($custom, 'in_paid', 1500)->assertOk();
        $paid = Transaction::where('payment_id', 'in_paid')->sole();
        $this->artisan('payments:settle-held-renewal', ['transaction' => $paid->id])->assertExitCode(1);

        // A held charge whose settlement answers 400 (its account is gone): nothing released.
        $this->stripeInvoice(['user_id' => '999999'] + $custom, 'in_orphan', 1000, ['subtotal' => 1000])->assertOk();
        $review = Transaction::where('payment_id', 'review:in_orphan')->sole();
        $this->artisan('payments:settle-held-renewal', ['transaction' => $review->id, '--accept-amount' => true])->assertExitCode(1);
        $this->assertNull(data_get($review->fresh()->details, 'amount_review.released_at'));
        $this->assertSame(0, Transaction::where('payment_id', 'in_orphan')->count());
    }

    public function test_the_lemon_first_payment_is_stamped_with_its_subscription_in_either_arrival_order(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $first] = $this->checkout(15.0);
        $this->lemon('order_created', $first, 'order-a')->assertOk();
        $this->lemon('subscription_created', $first, 'sub-a', ['order_id' => 'order-a'])->assertOk();
        $this->assertSame('sub-a', data_get(Transaction::where('payment_id', 'order-a')->value('details'), 'subscription_id'));

        [, $second] = $this->checkout(15.0);
        $this->lemon('subscription_created', $second, 'sub-b', ['order_id' => 'order-b'])->assertOk();
        $this->lemon('order_created', $second, 'order-b')->assertOk();
        $this->assertSame('sub-b', data_get(Transaction::where('payment_id', 'order-b')->value('details'), 'subscription_id'));
    }
}
