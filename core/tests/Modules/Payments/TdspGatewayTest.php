<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * S3-P1: TDSP / T-Lync gateway — order payments (sandbox). Focus is the MONEY
 * guardrails: initiate payload, server-side receipt re-verify, amount match,
 * idempotency. No live keys; the gateway HTTP is faked.
 */
class TdspGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class, // APP_INSTALLED=false -> 302 /install
        ]);
    }

    /** @return array{0:User,1:Store,2:Order,3:Transaction} */
    private function orderSetup(float $amount = 25.0): array
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
        StoreOption::updateStoreOption($store->id, 'restaurant_tdsp_store_id', 'store123');
        StoreOption::updateStoreOption($store->id, 'restaurant_tdsp_token', 'tok_abc');
        StoreOption::updateStoreOption($store->id, 'restaurant_tdsp_sandbox_mode', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_tdsp_install', '1');

        $order = Order::create([
            'store_id' => $store->id, 'track_token' => Str::random(12),
            'is_paid' => 0, 'status' => 'pending', 'created_at' => now(),
        ]);

        $tx = new Transaction;
        $tx->store_id = $store->id;
        $tx->product_id = $order->id;
        $tx->amount = $amount;
        $tx->currency_code = 'LYD'; // WI-06e: T-Lync charges LYD only
        $tx->product_name = 'Order';
        $tx->transaction_method = 'order';
        $tx->status = null;
        $tx->payment_id = null;
        $tx->save();

        return [$user, $store, $order, $tx];
    }

    private function fakeInitiate(): void
    {
        Http::fake([
            'uat-api.tlync.ly/api/v3/payment/initiate' => Http::response([
                'result' => 'success', 'custom_ref' => 'X', 'url' => 'https://pay.tlync.ly/go/abc',
            ], 200),
        ]);
    }

    public function test_initiate_redirects_to_gateway_url(): void
    {
        [, , , $tx] = $this->orderSetup();
        $this->fakeInitiate();

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'tdsp', 'email' => 'c@example.com', 'phone' => '+218900000',
        ]);

        $res->assertRedirect('https://pay.tlync.ly/go/abc');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'payment/initiate')
            && $r['id'] === 'store123' && $r['custom_ref'] === (string) $tx->id
            && str_contains($r['backend_url'], '/webhook/tdsp'));
    }

    public function test_webhook_verified_and_matching_amount_settles(): void
    {
        [, , $order, $tx] = $this->orderSetup(25.0);
        Http::fake([
            'uat-api.tlync.ly/api/v3/receipt/transaction' => Http::response(['result' => 'success', 'data' => ['reference' => 'RCPT1', 'amount' => '25.000', 'currency' => 'LYD', 'gateway_name' => 'edfaly']], 200),
        ]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])
            ->assertOk();

        $this->assertEquals(1, Order::find($order->id)->is_paid);   // settled
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: consumed, not deleted
    }

    public function test_webhook_receipt_amount_mismatch_does_not_settle(): void
    {
        [, , $order, $tx] = $this->orderSetup(25.0);
        Http::fake(['uat-api.tlync.ly/api/v3/receipt/transaction' => Http::response(['result' => 'success', 'data' => ['reference' => 'R', 'amount' => '5.000', 'currency' => 'LYD', 'gateway_name' => 'edfaly']], 200)]);

        // WI-17b: the (unsigned) callback's amount is irrelevant — the receipt's is not ours.
        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();

        $this->assertEquals(0, Order::find($order->id)->is_paid);
        $this->assertNotNull(Transaction::find($tx->id));
        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_webhook_unverified_receipt_does_not_settle(): void
    {
        [, , $order, $tx] = $this->orderSetup(25.0);
        Http::fake(['uat-api.tlync.ly/api/v3/receipt/transaction' => Http::response(['result' => 'incomplete'], 200)]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();

        $this->assertEquals(0, Order::find($order->id)->is_paid);
        $this->assertNotNull(Transaction::find($tx->id));
    }

    public function test_webhook_claimed_amount_only_triggers_the_receipt_lookup(): void
    {
        [, , $order, $tx] = $this->orderSetup(25.0);
        Http::fake(['uat-api.tlync.ly/api/v3/receipt/transaction' => Http::response(['result' => 'incomplete'], 200)]);

        // WI-17b: a forged claim (any amount, result=success) only makes us ask the receipt.
        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '9999.000', 'result' => 'success'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'receipt/transaction') && $r['custom_ref'] === (string) $tx->id);
        $this->assertEquals(0, Order::find($order->id)->is_paid);
        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_webhook_unknown_ref_is_ok_noop(): void
    {
        Http::fake();
        $this->post('/webhook/tdsp', ['custom_ref' => '99999', 'amount' => '10.000'])->assertOk();
        Http::assertNothingSent();
    }

    /** S3-P2: non-order (membership/top-up) path settles via config creds + updateUserPlan. */
    public function test_membership_path_settles_with_platform_creds(): void
    {
        config(['settings.tdsp_store_id' => 'PLAT', 'settings.tdsp_token' => 'ptok', 'settings.tdsp_sandbox' => true]);
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $tx = new Transaction;
        $tx->user_id = $user->id;
        $tx->amount = 30.0;
        $tx->currency_code = 'LYD';
        $tx->product_name = 'Wallet top-up';
        $tx->transaction_method = 'wallet_topup';
        $tx->status = null;
        $tx->payment_id = null;
        $tx->save();

        Http::fake(['uat-api.tlync.ly/api/v3/receipt/transaction' => Http::response(['result' => 'success', 'data' => ['reference' => 'R', 'amount' => '30.000', 'currency' => 'LYD', 'gateway_name' => 'edfaly']], 200)]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '30.000']);

        // Settled server-side (status flipped) — use Eloquent (table prefix-safe).
        $settled = Transaction::find($tx->id);
        $this->assertNotNull($settled);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('tdsp', $settled->transaction_gatway);
        // It used the SANDBOX platform base + store id.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'uat-api.tlync.ly') && $r['store_id'] === 'PLAT');
    }

    /** S3-P3: per-restaurant settings UI + save whitelist wired. */
    public function test_restaurant_settings_ui_and_whitelist_wired(): void
    {
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'tdsp'", $edit);   // data-driven Payments tab
        $ctrl = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_tdsp_store_id', $ctrl); // save whitelist
        $this->assertStringContainsString('restaurant_tdsp_token', $ctrl);
        $this->assertStringContainsString('restaurant_tdsp_sandbox_mode', $ctrl);
    }

    /** S3-P2: admin form + settings-save wiring is in place. */
    public function test_admin_form_and_settings_wiring_exists(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/tdsp.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'tdsp' =>", $ctrl);        // save case
        $this->assertStringContainsString('tdsp_store_id', $ctrl);
        $this->assertStringContainsString('tdsp_token', $ctrl);
    }
}
