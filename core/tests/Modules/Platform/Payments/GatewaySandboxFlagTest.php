<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaddleController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-06e (audit M3) — every gateway sandbox flag goes through one normaliser.
 * The string 'No' / '0' is live and 'Yes' / '1' is sandbox, whichever form the
 * setting was saved in; a `(bool)` cast used to read 'No' as sandbox ON and a
 * `=== 'Yes'` test read '1' as sandbox OFF. Fake keys only.
 */
class GatewaySandboxFlagTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_the_normaliser_reads_every_saved_form(): void
    {
        foreach (['Yes', 'yes', '1', 'true', 'on', 'sandbox', 'test', 1, true] as $on) {
            $this->assertTrue(gateway_is_sandbox($on, false), var_export($on, true).' is sandbox');
            $this->assertTrue(Settlement::isSandbox($on), var_export($on, true).' is sandbox');
        }
        foreach (['No', 'no', '0', 'false', 'off', 'live', 'production', 0, false] as $off) {
            $this->assertFalse(gateway_is_sandbox($off), var_export($off, true).' is live');
            $this->assertFalse(Settlement::isSandbox($off), var_export($off, true).' is live');
        }
        // Missing, blank or unknown: the caller's default (sandbox unless told otherwise).
        foreach ([null, '', '  ', 'maybe'] as $unset) {
            $this->assertTrue(gateway_is_sandbox($unset));
            $this->assertFalse(gateway_is_sandbox($unset, false));
            $this->assertFalse(Settlement::isSandbox($unset));
        }
    }

    public function test_store_tlync_saved_as_no_goes_live(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('tdsp', 25.0, ['store_id' => 'store_fake', 'token' => 'tok_fake'], 'LYD');
        StoreOption::updateStoreOption($store->id, 'restaurant_tdsp_sandbox_mode', 'No');
        Http::fake(['*' => Http::response(['result' => 'failed'], 200)]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.tlync.ly/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'uat-api.tlync.ly'));
    }

    public function test_platform_flags_saved_as_zero_go_live(): void
    {
        config(['settings.tdsp_store_id' => 'PLAT', 'settings.tdsp_token' => 'ptok_fake', 'settings.tdsp_sandbox' => '0']);
        [, $tx] = $this->makePlanTransaction(30.0, 'LYD');
        Http::fake(['*' => Http::response(['result' => 'failed'], 200)]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '30.000'])->assertOk();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.tlync.ly/'));
        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_platform_tamara_saved_as_no_uses_the_live_api(): void
    {
        config(['settings.tamara_api_token' => 'tok_fake', 'settings.tamara_notification_token' => 'nt_fake', 'settings.tamara_sandbox' => 'No']);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        $tx->forceFill(['details' => ['tamara_order_id' => 'ord_live']])->save();
        Http::fake(['*' => Http::response([], 500)]);

        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $claims = ['order_id' => 'ord_live', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved'];
        $signing = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));
        $jwt = $signing.'.'.$b64(hash_hmac('sha256', $signing, 'nt_fake', true));

        $this->withHeaders(['tamaraToken' => $jwt])->post('/webhook/tamara', $claims)->assertOk();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.tamara.co/orders/ord_live/authorise'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api-sandbox.tamara.co'));
    }

    public function test_paddle_saved_as_one_uses_the_sandbox_api(): void
    {
        config(['settings.paddle_sandbox_mode' => '1', 'settings.paddle_api_key' => 'pdl_key_fake']);
        Http::fake(['*' => Http::response(['data' => ['checkout' => ['url' => 'https://checkout.example/x']]], 200)]);
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(4), 'description' => 'x', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 300, 'settings' => ['restaurant_limit' => 1], 'status' => 1,
        ]);
        $user = User::factory()->create();
        $tx = Transaction::create([
            'product_name' => $plan->name, 'product_id' => TestPlans::productId($plan), 'user_id' => $user->id, 'base_amount' => 10,
            'amount' => 10, 'currency_code' => 'USD', 'transaction_method' => 'membership', 'frequency' => 'MONTHLY',
        ]);

        PaddleController::pay($tx);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://sandbox-api.paddle.com/transactions'));
    }
}
