<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Regression (browser e2e a-payment-stub): the pay endpoint only dispatches to a gateway in the store's
 * offered list (hard-coded STORE_GATEWAYS + per-store `restaurant_<folder>_install` options), so the
 * E2E-only `stub` gateway was never reachable after the payments refactor and the journey bounced back to
 * the pay page unpaid. The stub must be offered when — and only when — the stubs are armed and the E2E
 * catalog row is installed.
 */
class E2eStubGatewayOfferedTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function installStubRow(): void
    {
        PaymentGateway::query()->updateOrCreate(['payment_folder' => 'stub'], ['payment_install' => '1', 'payment_title' => 'E2E Stub Gateway']);
    }

    public function test_an_armed_stub_settles_the_order_through_the_real_success_path(): void
    {
        config(['app.e2e_stubs' => true]);
        $this->installStubRow();
        [, $order, $tx] = $this->makeOrderTransaction('stub', 25.0);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stub', 'e2e_outcome' => 'success'])->assertOk();

        $this->assertSettledOnce($order, $tx);
        $this->assertSame('stub', $tx->fresh()->transaction_gatway);
    }

    public function test_an_unarmed_stub_is_not_offered_even_with_the_catalog_row(): void
    {
        config(['app.e2e_stubs' => false]);
        $this->installStubRow();
        [, $order, $tx] = $this->makeOrderTransaction('stub', 25.0);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stub', 'e2e_outcome' => 'success'])->assertRedirect();

        $this->assertNotSettled($order, $tx);
    }

    public function test_an_armed_stub_without_the_catalog_row_is_not_offered(): void
    {
        config(['app.e2e_stubs' => true]);
        [, $order, $tx] = $this->makeOrderTransaction('stub', 25.0);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stub', 'e2e_outcome' => 'success'])->assertRedirect();

        $this->assertNotSettled($order, $tx);
    }
}
