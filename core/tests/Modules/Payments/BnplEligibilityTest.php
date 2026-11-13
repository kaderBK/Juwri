<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Services\BnplEligibility;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P0 — BNPL eligibility matrix + shared harness self-test.
 */
class BnplEligibilityTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    public function test_disabled_when_owner_toggle_off(): void
    {
        [$store] = $this->makeOrderTransaction('tabby', 300.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '0');

        $this->assertFalse(BnplEligibility::availableForOrder($store, 300.0, 'tabby'));
    }

    public function test_disabled_below_threshold(): void
    {
        [$store] = $this->makeOrderTransaction('tamara', 100.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_min_total', '200');

        $this->assertFalse(BnplEligibility::availableForOrder($store, 100.0, 'tamara'));
    }

    public function test_enabled_at_threshold(): void
    {
        [$store] = $this->makeOrderTransaction('tamara', 200.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_min_total', '200');

        $this->assertTrue(BnplEligibility::availableForOrder($store, 200.0, 'tamara'));
    }

    public function test_default_threshold_is_200(): void
    {
        [$store] = $this->makeOrderTransaction('tabby', 199.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        // no min_total set -> default 200
        $this->assertSame(200.0, BnplEligibility::minTotal($store->id));
        $this->assertFalse(BnplEligibility::availableForOrder($store, 199.0, 'tabby'));
        $this->assertTrue(BnplEligibility::availableForOrder($store, 250.0, 'tabby'));
    }

    public function test_requires_gateway_install(): void
    {
        [$store] = $this->makeOrderTransaction('tabby', 300.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_tabby_install', '0');

        $this->assertFalse(BnplEligibility::availableForOrder($store, 300.0, 'tabby'));
    }

    public function test_plan_context_has_no_threshold_platform_install_only(): void
    {
        // Not installed at platform -> false.
        $this->assertFalse(BnplEligibility::availableForPlan('tamara'));

        PaymentGateway::create(['payment_folder' => 'tamara', 'payment_title' => 'Tamara', 'payment_install' => '1']);
        $this->assertTrue(BnplEligibility::availableForPlan('tamara'));
    }

    public function test_non_bnpl_gateway_never_eligible(): void
    {
        [$store] = $this->makeOrderTransaction('paymob', 500.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        $this->assertFalse(BnplEligibility::availableForOrder($store, 500.0, 'paymob'));
        $this->assertFalse(BnplEligibility::availableForPlan('paymob'));
    }

    public function test_harness_self_test_order_fixture(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('paymob', 75.0, ['secret_key' => 'sk_x']);
        $this->assertSame(0, (int) $order->is_paid);
        $this->assertNull($tx->status);
        $this->assertSame('sk_x', (string) StoreOption::getStoreOption($store->id, 'restaurant_paymob_secret_key'));
        $this->assertNotSettled($order, $tx);
    }
}
