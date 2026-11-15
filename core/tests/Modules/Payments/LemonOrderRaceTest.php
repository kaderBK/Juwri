<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\MorWebhookFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-045 race: Lemon Squeezy order_created and subscription_created within milliseconds. Both touch the parked
 * subscription id under one per-order lock and re-read state inside it, so the second event merges and the
 * subscription id is never lost (renewals would otherwise be ignored).
 */
class LemonOrderRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
        Carbon::setTestNow('2026-10-27 09:00:00');
        config(['settings.currency_code' => 'USD', 'settings.lemon_squeezy_webhook_secret' => 'ls_whsec_test', 'settings.lemon_order_lock_wait_seconds' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{0: Transaction, 1: array<string, string>} a pending checkout and its custom data, as pay() sends it
     */
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

    /**
     * @param  array<string, string>  $custom
     * @param  array<string, mixed>  $attributes
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function lemon(string $event, array $custom, string $id, array $attributes = []): TestResponse
    {
        $signed = MorWebhookFixtures::lemonSqueezySigned(MorWebhookFixtures::lemonSqueezyEvent($event, $custom, $id, $attributes), 'ls_whsec_test');

        return $this->call('POST', route('webhook', 'lemon_squeezy'), [], [], [],
            ['HTTP_X-Signature' => $signed['headers']['X-Signature'], 'CONTENT_TYPE' => 'application/json'], $signed['body']);
    }

    /** @param array<string, string> $custom */
    private function assertMerged(array $custom, string $order, string $sub): void
    {
        $this->assertSame($sub, data_get(Transaction::where('payment_id', $order)->value('details'), 'subscription_id'));
        $this->assertSame('lemon_squeezy###'.$sub, DB::table('upgrades')->where('user_id', $custom['user_id'])->value('unique_id'));
        $this->assertNull(Cache::get('lemon_sub_for_order:'.$order), 'nothing stays parked once merged');
    }

    public function test_order_first_then_subscription_merges(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-r1')->assertOk();
        $this->lemon('subscription_created', $custom, 'sub-r1', ['order_id' => 'order-r1'])->assertOk();
        $this->assertMerged($custom, 'order-r1', 'sub-r1');
    }

    public function test_subscription_first_then_order_merges(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->lemon('subscription_created', $custom, 'sub-r2', ['order_id' => 'order-r2'])->assertOk();
        $this->lemon('order_created', $custom, 'order-r2')->assertOk();
        $this->assertMerged($custom, 'order-r2', 'sub-r2');
    }

    public function test_an_event_arriving_while_the_other_holds_the_lock_waits_and_a_retry_merges(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-r3')->assertOk();

        Carbon::setTestNow(); // block() measures its wait on the clock: a frozen clock would never time out
        $lock = Cache::lock('lemon_order:order-r3', 30);
        $this->assertTrue($lock->get());
        try {
            $this->withoutExceptionHandling();
            $this->lemon('subscription_created', $custom, 'sub-r3', ['order_id' => 'order-r3']);
            $this->fail('the second event must not run while the first holds the lock');
        } catch (LockTimeoutException) {
            $this->assertNotSame('lemon_squeezy###sub-r3', DB::table('upgrades')->where('user_id', $custom['user_id'])->value('unique_id'), 'no half-written state');
        } finally {
            $lock->release();
            $this->withExceptionHandling();
        }

        // The gateway retries (the failed claim was released): the second event now merges.
        $this->lemon('subscription_created', $custom, 'sub-r3', ['order_id' => 'order-r3'])->assertOk();
        $this->assertMerged($custom, 'order-r3', 'sub-r3');
    }

    public function test_a_subscription_event_with_a_stale_checkout_read_sees_the_settlement_under_the_lock(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->lemon('order_created', $custom, 'order-r4')->assertOk();
        // Stale parked entry from an earlier delivery must not survive or override the settled state.
        Cache::put('lemon_sub_for_order:order-r4', 'stale', now()->addDay());
        $this->lemon('subscription_created', $custom, 'sub-r4', ['order_id' => 'order-r4'])->assertOk();
        $this->assertSame('sub-r4', data_get($tx->fresh()->details, 'lemon_subscription_id'));
        $this->assertSame('lemon_squeezy###sub-r4', DB::table('upgrades')->where('user_id', $custom['user_id'])->value('unique_id'));
    }

    public function test_replaying_either_event_changes_nothing(): void
    {
        $this->requiresEditionPackage('a platform plan settlement (Billing)');
        [$tx, $custom] = $this->checkout(15.0);
        $this->lemon('subscription_created', $custom, 'sub-r5', ['order_id' => 'order-r5'])->assertOk();
        $this->lemon('order_created', $custom, 'order-r5')->assertOk();
        $before = [Transaction::count(), DB::table('upgrades')->where('user_id', $custom['user_id'])->value('unique_id'), $tx->fresh()->status];

        $this->lemon('subscription_created', $custom, 'sub-r5', ['order_id' => 'order-r5'])->assertOk();
        $this->lemon('order_created', $custom, 'order-r5')->assertOk();
        $this->lemon('subscription_created', $custom, 'sub-r5', ['order_id' => 'order-r5'])->assertOk();

        $this->assertSame($before, [Transaction::count(), DB::table('upgrades')->where('user_id', $custom['user_id'])->value('unique_id'), $tx->fresh()->status]);
        $this->assertMerged($custom, 'order-r5', 'sub-r5');
    }
}
