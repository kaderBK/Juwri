<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Services\BnplEligibility;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * V-P1 — PAYMENTS-RUN verification pass. Cross-cutting invariants that must hold
 * across all nine gateways (per-gateway settlement E2E lives in each
 * {Gateway}GatewayTest; this asserts the seams that tie them together).
 */
class PaymentsRunVerificationTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** All nine gateway folders are registered so the dispatcher can resolve them. */
    public function test_all_nine_gateways_registered(): void
    {
        foreach (['paylink', 'paymob', 'geidea', 'tamara', 'tabby', 'fawry', 'fatora', 'noon', 'gocardless'] as $folder) {
            $this->assertDatabaseHas('payment_gateways', ['payment_folder' => $folder]);
        }
    }

    /** Every new gateway has a driver class with the required static entrypoints. */
    public function test_every_driver_exposes_pay_ipn_webhook(): void
    {
        $drivers = [
            'PaylinkController', 'PaymobController', 'GeideaController', 'TamaraController',
            'TabbyController', 'FawryController', 'FatoraController', 'NoonController',
        ];
        foreach ($drivers as $d) {
            $fqcn = 'App\\Http\\Controllers\\User\\PaymentMethods\\'.$d;
            $this->assertTrue(method_exists($fqcn, 'pay'), "$d::pay missing");
            $this->assertTrue(method_exists($fqcn, 'ipn'), "$d::ipn missing");
            $this->assertTrue(method_exists($fqcn, 'webhook'), "$d::webhook missing");
        }
        // GoCardless = plan-only, still has the trio (order path aborts).
        $this->assertTrue(method_exists('Juwri\\Modules\\Payments\\Http\\Controllers\\User\\PaymentMethods\\GocardlessController', 'webhook'));
    }

    /** BNPL gateways are gated by the eligibility layer; non-BNPL are not. */
    public function test_bnpl_layer_governs_only_bnpl_gateways(): void
    {
        [$store] = $this->makeOrderTransaction('tabby', 300.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_tamara_install', '1');

        $this->assertTrue(BnplEligibility::availableForOrder($store, 300.0, 'tabby'));
        $this->assertTrue(BnplEligibility::availableForOrder($store, 300.0, 'tamara'));
        // Below threshold hides them.
        $this->assertFalse(BnplEligibility::availableForOrder($store, 10.0, 'tabby'));
        // Non-BNPL gateway is never governed by this layer.
        $this->assertFalse(BnplEligibility::availableForOrder($store, 300.0, 'paymob'));
    }

    /** GoCardless never appears in the restaurant order-checkout list. */
    public function test_gocardless_absent_from_order_checkout(): void
    {
        $src = file_get_contents(app_path('Modules/Ordering/Http/Controllers/User/PaymentController.php'));
        // The checkout gateway loop must not include gocardless.
        $this->assertStringNotContainsString("'gocardless'", $src);
    }

    /** Checkout list registers the 8 order-context gateways. */
    public function test_checkout_list_includes_order_gateways(): void
    {
        $src = file_get_contents(app_path('Modules/Ordering/Http/Controllers/User/PaymentController.php'));
        foreach (['paylink', 'paymob', 'geidea', 'tamara', 'tabby', 'fawry', 'fatora', 'noon'] as $gw) {
            $this->assertStringContainsString("'$gw'", $src, "$gw missing from checkout list");
        }
    }

    /** Paylink Partner is superadmin-gated and OFF by default. */
    public function test_partner_feature_default_off(): void
    {
        $this->assertFalse(FeatureRegistry::isEnabled('paylink_partner'));
    }
}
