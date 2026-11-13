<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MoyasarController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TapController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Services\Gateways\GatewaySandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Phase 4 item 18 — the optional LIVE sandbox journey: a real payment is opened on each provider's sandbox and must come
 * back with the provider's hosted page. It runs only when the environment holds the owner's sandbox keys (RELEASE-GATE
 * item "payment sandbox keys"); without them every case is skipped. Only TEST keys are ever accepted here — a live key
 * in these variables fails the case before any call.
 *
 *   STRIPE_SANDBOX_SECRET_KEY=sk_test_…   TAP_SANDBOX_SECRET_KEY=sk_test_…   MOYASAR_SANDBOX_SECRET_KEY=sk_test_…
 *   PAYPAL_SANDBOX_CLIENT_ID=…  PAYPAL_SANDBOX_SECRET=…   (PayPal sandbox app)
 */
#[Group('live-sandbox')]
class LiveSandboxJourneyTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return ['stripe' => ['stripe'], 'tap' => ['tap'], 'moyasar' => ['moyasar'], 'paypal' => ['paypal']];
    }

    #[DataProvider('providers')]
    public function test_a_real_sandbox_payment_opens_on_the_providers_hosted_page(string $gateway): void
    {
        $env = fn (string $name): string => trim((string) (getenv($name) ?: ''));
        [$config, $host] = match ($gateway) {
            'stripe' => [['settings.stripe_secret_key' => $env('STRIPE_SANDBOX_SECRET_KEY'), 'settings.stripe_payment_mode' => 'one_time'], 'checkout.stripe.com'],
            'tap' => [['settings.tap_secret_key' => $env('TAP_SANDBOX_SECRET_KEY')], 'tap.company'],
            'moyasar' => [['settings.moyasar_secret_key' => $env('MOYASAR_SANDBOX_SECRET_KEY')], 'moyasar.com'],
            'paypal' => [['settings.paypal_api_client_id' => $env('PAYPAL_SANDBOX_CLIENT_ID'), 'settings.paypal_api_secret' => $env('PAYPAL_SANDBOX_SECRET'),
                'settings.paypal_sandbox_mode' => 'Yes', 'settings.paypal_payment_mode' => 'one_time'], 'paypal.com'],
        };
        if (in_array('', $config, true)) {
            $this->markTestSkipped("No {$gateway} sandbox keys in the environment (RELEASE-GATE: the owner supplies them).");
        }
        $secret = (string) ($config['settings.'.$gateway.'_secret_key'] ?? '');
        if ($gateway !== 'paypal') {
            $this->assertTrue(GatewaySandbox::isTestKey($gateway, $secret), 'only a TEST key may be used here');
        }

        $this->withoutMiddleware([Installed::class]);
        PaymentGateway::create(['payment_folder' => $gateway, 'payment_title' => $gateway, 'payment_install' => '1']);
        config($config + ['payments.sandbox.enabled' => true, 'settings.currency_code' => $gateway === 'paypal' ? 'USD' : 'SAR']);
        [, $tx] = $this->makePlanTransaction(10.0, $gateway === 'paypal' ? 'USD' : 'SAR', 'wallet_topup');

        $controller = ['stripe' => StripeController::class, 'tap' => TapController::class, 'moyasar' => MoyasarController::class, 'paypal' => PaypalController::class][$gateway];
        $response = $controller::adapter()->initiate($tx->fresh());

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString($host, (string) parse_url($location, PHP_URL_HOST), "sent to the {$gateway} sandbox page");
    }
}
