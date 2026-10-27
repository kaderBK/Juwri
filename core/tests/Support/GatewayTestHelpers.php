<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * PAYMENTS-RUN P0 — shared gateway test harness.
 *
 * The nine gateway tracks reuse these fixtures + assertions instead of
 * copy-pasting the TdspGatewayTest scaffolding, so every driver is exercised
 * against the SAME money guardrails (settle-once, amount match, replay).
 */
trait GatewayTestHelpers
{
    /**
     * Build a paying restaurant + order + PENDING order-context transaction,
     * with the given gateway's BYOK creds seeded.
     *
     * @param  array<string,string>  $creds  post_option key => value (without the
     *                                       `restaurant_{gw}_` prefix), e.g.
     *                                       ['secret_key' => 'sk_test'].
     * @return array{0:Store,1:Order,2:Transaction}
     */
    protected function makeOrderTransaction(string $gateway, float $amount = 100.0, array $creds = [], string $currency = 'SAR'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);

        StoreOption::updateStoreOption($store->id, 'restaurant_'.$gateway.'_install', '1');
        // Lane D: eCash and QRPay have no sandbox host — sandbox mode is refused there, so their test stores run live (faked).
        StoreOption::updateStoreOption($store->id, 'restaurant_'.$gateway.'_sandbox_mode', in_array($gateway, ['ecash', 'qrpay'], true) ? 'No' : 'Yes');
        foreach ($creds as $k => $v) {
            StoreOption::updateStoreOption($store->id, 'restaurant_'.$gateway.'_'.$k, $v);
        }

        $order = Order::create([
            'store_id' => $store->id, 'track_token' => Str::random(12),
            'is_paid' => 0, 'status' => 'pending', 'type' => 'takeaway', 'created_at' => now(),
        ]);

        $tx = new Transaction;
        $tx->store_id = $store->id; // order payment: the store (user_id stays NULL)
        $tx->product_id = $order->id;
        $tx->amount = $amount;
        $tx->currency_code = $currency;
        $tx->product_name = 'Order';
        $tx->transaction_method = 'order';
        $tx->status = null;
        $tx->payment_id = null;
        $tx->save();

        return [$store, $order, $tx];
    }

    /**
     * Build a plan/wallet-context PENDING transaction (platform creds path).
     *
     * @return array{0:User,1:Transaction}
     */
    protected function makePlanTransaction(float $amount = 49.0, string $currency = 'SAR', string $method = 'wallet_topup'): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $tx = new Transaction;
        $tx->user_id = $user->id;
        $tx->amount = $amount;
        $tx->currency_code = $currency;
        $tx->product_name = 'Plan';
        $tx->transaction_method = $method;
        $tx->status = null;
        $tx->payment_id = null;
        $tx->save();

        return [$user, $tx];
    }

    /** Assert the order settled exactly once (paid + transaction consumed). */
    protected function assertSettledOnce(Order $order, Transaction $tx): void
    {
        $this->assertSame(1, (int) $order->fresh()->is_paid, 'order should be marked paid');
        // MC-02: a settled order payment is now CONSUMED (durable charge history),
        // not deleted — the row survives so the charge can never vanish.
        $fresh = Transaction::find($tx->id);
        $this->assertNotNull($fresh, 'settled transaction should survive as history');
        $this->assertSame(Transaction::STATUS_CONSUMED, $fresh->status, 'settled transaction should be consumed');
    }

    /** Assert nothing settled (order still unpaid, transaction still pending). */
    protected function assertNotSettled(Order $order, Transaction $tx): void
    {
        $this->assertSame(0, (int) $order->fresh()->is_paid, 'order must not be paid');
        $fresh = Transaction::find($tx->id);
        $this->assertNotNull($fresh, 'transaction must survive');
        $this->assertNull($fresh->status, 'transaction must stay pending');
    }
}
