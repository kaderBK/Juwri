<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Billing;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-09p — fixes made while rewriting the storefront order payment: the
 * WhatsApp hand-off is escaped, only the store's own gateways can be chosen,
 * and only order transactions are payable here.
 */
class StorefrontPaymentHardeningTest extends TestCase
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

    private function settle(Transaction $tx): string
    {
        ob_start();
        PaymentController::paySuccess($tx);

        return (string) ob_get_clean();
    }

    public function test_whatsapp_hand_off_cannot_break_out_of_the_script(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 10.0);
        $evil = 'https://wa.me/1?text=a"; alert(1); //</script><script>alert(2)</script>\'&x=<b>';
        $tx->forceFill(['details' => ['whatsapp_url' => $evil]])->save();

        $out = $this->settle($tx->fresh());

        $this->assertSame(1, substr_count($out, '<script'), 'exactly one script element');
        $this->assertSame(1, substr_count($out, '</script>'));
        $this->assertStringNotContainsString('alert(2)</script>', $out);
        $this->assertStringNotContainsString('"; alert(1)', $out);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringContainsString('\\u003C/script\\u003E', $out);
        $this->assertStringContainsString('\\u0022; alert(1)', $out);
        $this->assertStringContainsString($order->track_token, $out);

        // The escaped string still decodes to the exact URL for the browser.
        preg_match('/window\.open\(("(?:[^"\\\\]|\\\\.)*")/', $out, $m);
        $this->assertSame($evil, json_decode($m[1]));
    }

    public function test_only_http_links_are_opened(): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 10.0);
        $tx->forceFill(['details' => ['whatsapp_url' => 'javascript:alert(document.cookie)']])->save();

        $out = $this->settle($tx->fresh());

        $this->assertStringNotContainsString('window.open', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('location.href', $out);
    }

    public function test_a_missing_order_no_longer_breaks_the_settlement_answer(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 10.0);
        $order->delete();

        $out = $this->settle($tx);

        $this->assertStringContainsString(json_encode(route('publicView', $store->slug), JSON_UNESCAPED_SLASHES), $out);
        $this->assertSame(Transaction::STATUS_CONSUMED, $tx->fresh()->status);
    }

    public function test_pay_refuses_a_gateway_the_store_has_not_switched_on(): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 10.0);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])
            ->assertRedirect(route('payment.index', $tx));
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'no_such_gateway'])
            ->assertRedirect(route('payment.index', $tx));
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => '../../Admin'])
            ->assertRedirect(route('payment.index', $tx));
        $this->assertNull($tx->fresh()->transaction_gatway);
    }

    public function test_only_order_transactions_are_payable_here(): void
    {
        [$user, $tx] = $this->makePlanTransaction(49.0, 'SAR', 'membership');

        $this->get("/payment/{$tx->id}")->assertNotFound();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stripe'])->assertNotFound();
    }
}
