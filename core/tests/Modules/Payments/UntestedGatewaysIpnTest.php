<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * T5 — Iyzico, 2Checkout and Payumoney had ZERO test coverage. These drive each
 * gateway's ipn through the real ipn/{gateway} route (PaymentController's
 * folder→class resolution) and assert that invalid/missing provider data settles
 * nothing and never 500s. (The SDK happy-path — a real provider callback that
 * settles — needs per-SDK mocking and is a follow-up; this closes the
 * zero-coverage gap and nets any change that settles on bad input.)
 */
class UntestedGatewaysIpnTest extends TestCase
{
    use RefreshDatabase, GatewayTestHelpers;

    private function seedGw(string $folder): array
    {
        PaymentGateway::create(['payment_folder' => $folder, 'payment_title' => $folder, 'payment_install' => '1']);

        return $this->makeOrderTransaction($folder, 100.0, ['secret_key' => 'x']);
    }

    private function postIpn(string $folder, array $body)
    {
        return $this->withoutMiddleware([\App\Http\Middleware\Installed::class])
            ->post(route('ipn', $folder), $body);
    }

    public function test_iyzico_without_token_settles_nothing(): void
    {
        [, $order, $tx] = $this->seedGw('iyzico');

        $res = $this->postIpn('iyzico', []); // no token → early return

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_twocheckout_unknown_order_settles_nothing(): void
    {
        [, $order, $tx] = $this->seedGw('two_checkout');

        $res = $this->postIpn('two_checkout', ['order_id' => 99999999]); // no matching pending tx

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_payumoney_without_order_settles_nothing(): void
    {
        [, $order, $tx] = $this->seedGw('payumoney');

        $res = $this->postIpn('payumoney', []); // no order_id → no settle path

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }
}
