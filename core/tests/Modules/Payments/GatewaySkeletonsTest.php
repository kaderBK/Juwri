<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FlutterwaveController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TapController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

class GatewaySkeletonsTest extends TestCase
{
    use RefreshDatabase;

    private function orderTxn(float $amount = 25.00): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = Order::create([
            'store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway',
            'is_paid' => 0, 'track_token' => Str::random(20),
        ]);
        $txn = Transaction::create([
            'product_name' => 'Order', 'product_id' => $order->id, 'store_id' => $store->id,
            'transaction_method' => 'order', 'amount' => $amount, 'currency_code' => 'USD',
            'status' => null, 'payment_id' => null,
        ]);

        return [$store, $order, $txn];
    }

    private function silently(callable $fn): void
    {
        ob_start();
        try {
            $fn();
        } finally {
            ob_get_clean();
        }
    }

    // --- Tap ---------------------------------------------------------------

    public function test_tap_marks_order_paid_on_captured_matching_amount(): void
    {
        [$store, $order, $txn] = $this->orderTxn(25.00);
        StoreOption::updateStoreOption($store->id, 'restaurant_tap_secret_key', 'sk_test_store');
        Http::fake(['api.tap.company/v2/charges/*' => Http::response([
            'id' => 'chg_1', 'live_mode' => false,
            'status' => 'CAPTURED', 'amount' => 25.00, 'currency' => 'USD',
            'reference' => ['transaction' => (string) $txn->id],
        ], 200)]);

        $this->silently(fn () => TapController::ipn(Request::create('/ipn/tap', 'GET', ['tap_id' => 'chg_1', 'txn' => $txn->id])));

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        // MC-02: settled order payment is consumed (durable history), not deleted.
        $this->assertDatabaseHas('transaction', ['id' => $txn->id, 'status' => Transaction::STATUS_CONSUMED]);
    }

    public function test_tap_rejects_amount_mismatch(): void
    {
        [$store, $order, $txn] = $this->orderTxn(25.00);
        StoreOption::updateStoreOption($store->id, 'restaurant_tap_secret_key', 'sk_test_store');
        Http::fake(['api.tap.company/v2/charges/*' => Http::response([
            'id' => 'chg_1', 'live_mode' => false,
            'status' => 'CAPTURED', 'amount' => 5.00, 'currency' => 'USD', // tampered
            'reference' => ['transaction' => (string) $txn->id],
        ], 200)]);

        $this->silently(fn () => TapController::ipn(Request::create('/ipn/tap', 'GET', ['tap_id' => 'chg_1', 'txn' => $txn->id])));

        $this->assertSame(0, (int) $order->fresh()->is_paid);
        $this->assertDatabaseHas('transaction', ['id' => $txn->id, 'status' => null]);
    }

    // --- Flutterwave -------------------------------------------------------

    public function test_flutterwave_webhook_rejects_bad_signature(): void
    {
        config(['settings.flutterwave_secret_hash' => 'right-hash']);
        $req = Request::create('/webhook/flutterwave', 'POST', [], [], [], ['HTTP_VERIF-HASH' => 'wrong'], json_encode(['data' => ['id' => 1]]));
        $req->headers->set('verif-hash', 'wrong');

        $res = FlutterwaveController::webhook($req);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_flutterwave_webhook_marks_paid_on_valid_verify(): void
    {
        config(['settings.flutterwave_secret_hash' => 'h', 'settings.flutterwave_secret_key' => 'sk']);
        [$store, $order, $txn] = $this->orderTxn(25.00);
        StoreOption::updateStoreOption($store->id, 'restaurant_flutterwave_secret_key', 'sk-store');

        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response([
            'status' => 'success',
            'data' => ['status' => 'successful', 'amount' => 25.00, 'currency' => 'USD', 'tx_ref' => (string) $txn->id],
        ], 200)]);

        $req = Request::create('/webhook/flutterwave', 'POST', [], [], [], [], json_encode(['data' => ['id' => 'flw_1', 'tx_ref' => (string) $txn->id]]));
        $req->headers->set('verif-hash', 'h');
        $req->headers->set('Content-Type', 'application/json');

        $this->silently(fn () => FlutterwaveController::webhook($req));

        $this->assertSame(1, (int) $order->fresh()->is_paid);
    }

    public function test_flutterwave_webhook_rejects_amount_mismatch(): void
    {
        config(['settings.flutterwave_secret_hash' => 'h', 'settings.flutterwave_secret_key' => 'sk']);
        [$store, $order, $txn] = $this->orderTxn(25.00);
        StoreOption::updateStoreOption($store->id, 'restaurant_flutterwave_secret_key', 'sk-store');

        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response([
            'status' => 'success',
            'data' => ['status' => 'successful', 'amount' => 1.00, 'currency' => 'USD', 'tx_ref' => (string) $txn->id],
        ], 200)]);

        $req = Request::create('/webhook/flutterwave', 'POST', [], [], [], [], json_encode(['data' => ['id' => 'flw_1', 'tx_ref' => (string) $txn->id]]));
        $req->headers->set('verif-hash', 'h');
        $req->headers->set('Content-Type', 'application/json');

        $this->silently(fn () => FlutterwaveController::webhook($req));

        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_gateways_registered_in_table(): void
    {
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'tap']);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'flutterwave']);
    }
}
