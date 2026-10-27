<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways;
use Juwri\Modules\Payments\Services\Gateways\GatewaySandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Lane D: with PAYMENTS_SANDBOX_ENABLED off, EVERY adapter refuses a sandbox-mode credential set or a test key
 * ("not configured": no payment, status call, refund or webhook verification), and a blank sandbox setting is refused
 * in production too. Before, only Stripe/Tap/Moyasar/PayPal were guarded; the other adapters picked their sandbox from
 * their own setting. Each adapter's credential lookup is read straight (the one place every call and webhook uses).
 */
class AllAdaptersSandboxGuardTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** Adapters with no sandbox / test-account concept at all (live host only, no test-key convention). */
    private const NO_SANDBOX = ['Ccavenue', 'Fatora', 'Paytabs', 'WalletTransfer', 'WireTransfer'];

    /**
     * adapter => [credential method, platform settings in sandbox mode / with a test key, the sandbox setting key or null].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: ?string}>
     */
    public static function adapters(): array
    {
        return [
            'Fawry' => ['Fawry', 'creds', ['fawry_merchant_code' => 'M', 'fawry_security_key' => 'K', 'fawry_sandbox' => '1'], 'fawry_sandbox'],
            'Geidea' => ['Geidea', 'creds', ['geidea_merchant_public_key' => 'P', 'geidea_api_password' => 'K', 'geidea_sandbox' => '1'], 'geidea_sandbox'],
            'Gocardless' => ['Gocardless', 'creds', ['gocardless_access_token' => 'T', 'gocardless_webhook_secret' => 'W', 'gocardless_sandbox' => '1'], 'gocardless_sandbox'],
            'Iyzico' => ['Iyzico', 'credentials', ['iyzico_api_key' => 'A', 'iyzico_secret_key' => 'S', 'iyzico_sandbox_mode' => 'Yes'], null],
            'Midtrans' => ['Midtrans', 'credentials', ['midtrans_server_key' => 'S', 'midtrans_client_key' => 'C', 'midtrans_sandbox_mode' => 'Yes'], null],
            'Noon' => ['Noon', 'creds', ['noon_business_id' => 'B', 'noon_application_id' => 'A', 'noon_api_key' => 'K', 'noon_sandbox' => '1'], 'noon_sandbox'],
            'Paylink' => ['Paylink', 'creds', ['paylink_api_id' => 'A', 'paylink_secret_key' => 'S', 'paylink_sandbox' => '1'], 'paylink_sandbox'],
            'Paymob' => ['Paymob', 'creds', ['paymob_secret_key' => 'S', 'paymob_public_key' => 'P', 'paymob_integration_id' => '1', 'paymob_hmac_secret' => 'H', 'paymob_sandbox' => '1'], 'paymob_sandbox'],
            'Paytm' => ['Paytm', 'credentials', ['PAYTM_MERCHANT_MID' => 'M', 'PAYTM_MERCHANT_KEY' => 'K', 'PAYTM_MERCHANT_WEBSITE' => 'W', 'PAYTM_ENVIRONMENT' => 'test'], null],
            'Payumoney' => ['Payumoney', 'credentials', ['payumoney_merchant_pos_id' => 'P', 'payumoney_signature_key' => 'S', 'payumoney_oauth_client_id' => 'C', 'payumoney_oauth_client_secret' => 'X', 'payumoney_sandbox_mode' => 'Yes'], null],
            'Tamara' => ['Tamara', 'creds', ['tamara_api_token' => 'A', 'tamara_notification_token' => 'N', 'tamara_sandbox' => '1'], 'tamara_sandbox'],
            'Tdsp' => ['Tdsp', 'creds', ['tdsp_store_id' => 'S', 'tdsp_token' => 'T', 'tdsp_sandbox' => '1'], 'tdsp_sandbox'],
            'Telr' => ['Telr', 'credentials', ['telr_store_id' => 'S', 'telr_authkey' => 'K', 'telr_sandbox_mode' => 'Yes'], null],
            'TwoCheckout' => ['TwoCheckout', 'credentials', ['checkout_account_number' => 'S', 'checkout_public_key' => 'P', 'checkout_private_key' => 'K', '2checkout_sandbox_mode' => 'Yes'], null],
            'Paypal' => ['Paypal', 'credentials', ['paypal_api_client_id' => 'C', 'paypal_api_secret' => 'S', 'paypal_sandbox_mode' => 'Yes'], null],
            'Stripe' => ['Stripe', 'secretFor', ['stripe_secret_key' => 'sk_test_abc'], null],
            'Tap' => ['Tap', 'secret', ['tap_secret_key' => 'sk_test_abc'], null],
            'Moyasar' => ['Moyasar', 'secret', ['moyasar_secret_key' => 'sk_test_abc'], null],
            'Tabby' => ['Tabby', 'creds', ['tabby_secret_key' => 'sk_test_abc', 'tabby_merchant_code' => ''], null],
            'Paystack' => ['Paystack', 'secretKey', ['paystack_secret_key' => 'sk_test_abc'], null],
            'Flutterwave' => ['Flutterwave', 'secret', ['flutterwave_secret_key' => 'FLWSECK_TEST-abc'], null],
            'Razorpay' => ['Razorpay', 'keys', ['razorpay_api_key' => 'rzp_test_abc', 'razorpay_secret_key' => 'S'], null],
            'Mollie' => ['Mollie', 'client', ['mollie_api_key' => 'test_'.str_repeat('a', 30)], null],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function platformTransaction(): Transaction
    {
        return new Transaction(['transaction_method' => 'membership', 'amount' => 10, 'currency_code' => 'USD']);
    }

    /** True when the credential lookup came back configured (any secret string set / the client was built). */
    private function configured(string $adapter, string $method): bool
    {
        $class = 'Juwri\\Modules\\Payments\\Services\\Gateways\\'.$adapter.'Adapter';
        $ref = new \ReflectionMethod($class, $method);
        $args = $ref->getNumberOfParameters() > 0 ? [$this->platformTransaction()] : [];
        try {
            $result = $ref->invoke(app($class), ...$args);
        } catch (\RuntimeException) {
            return false; // Mollie: "This payment method is not configured."
        }
        if (is_string($result)) {
            return $result !== '';
        }
        if (is_array($result)) {
            $strings = array_filter($result, 'is_string');
            unset($strings['base'], $strings['snap'], $strings['api']);

            return array_filter($strings, fn (string $v): bool => $v !== '') !== [];
        }

        return true; // a built API client
    }

    /** @param array<string, mixed> $settings */
    private function settings(array $settings): void
    {
        config(collect($settings)->mapWithKeys(fn ($v, $k) => ['settings.'.$k => $v])->all());
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('adapters')]
    public function test_sandbox_credentials_are_refused_while_sandboxes_are_off(string $adapter, string $method, array $settings, ?string $modeKey): void
    {
        $this->settings($settings);

        config(['payments.sandbox.enabled' => true]);
        $this->assertTrue($this->configured($adapter, $method), "{$adapter}: sandbox creds work while sandboxes are on");

        config(['payments.sandbox.enabled' => false]);
        $this->assertFalse($this->configured($adapter, $method), "{$adapter}: sandbox creds must be refused while sandboxes are off");
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('adapters')]
    public function test_a_blank_sandbox_setting_is_refused_in_production(string $adapter, string $method, array $settings, ?string $modeKey): void
    {
        if ($modeKey === null) {
            $this->assertTrue(true); // blank = live for this adapter (Settlement::isSandbox / PayPal / key-based)

            return;
        }
        $settings[$modeKey] = '';
        $this->settings($settings);
        config(['payments.sandbox.enabled' => false]);

        $this->assertFalse($this->configured($adapter, $method), "{$adapter}: a blank sandbox setting must not run");

        $settings[$modeKey] = '0';
        $this->settings($settings);
        $this->assertTrue($this->configured($adapter, $method), "{$adapter}: an explicit live setting runs");
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('adapters')]
    public function test_live_credentials_are_untouched_while_sandboxes_are_off(string $adapter, string $method, array $settings, ?string $modeKey): void
    {
        $live = collect($settings)->map(function ($v, $k) {
            if (preg_match('/sandbox|ENVIRONMENT/', (string) $k)) {
                return '0';
            }

            return is_string($v) ? str_replace(['sk_test_', 'FLWSECK_TEST-', 'rzp_test_', 'test_'], ['sk_live_', 'FLWSECK-', 'rzp_live_', 'live_'], $v) : $v;
        })->all();
        $this->settings($live);
        config(['payments.sandbox.enabled' => false]);

        $this->assertTrue($this->configured($adapter, $method), "{$adapter}: live creds work with sandboxes off");
    }

    public function test_every_adapter_is_covered(): void
    {
        $covered = array_merge(array_keys(self::adapters()), self::NO_SANDBOX, ['Ecash', 'Qrpay']);
        foreach (glob(app_path('Modules/Payments/Services/Gateways/*Adapter.php')) ?: [] as $file) {
            $name = basename($file, 'Adapter.php');
            if ($name === 'Gateway') {
                continue;
            }
            $this->assertContains($name, $covered, "{$name}Adapter has no sandbox-guard case in this test");
        }
    }

    public function test_ecash_and_qrpay_refuse_sandbox_mode_even_with_sandboxes_on_and_blank_means_live(): void
    {
        config(['payments.sandbox.enabled' => true]);
        $this->settings(['ecash_merchant_id' => 'M', 'ecash_terminal_id' => 'T', 'ecash_api_key' => 'K', 'ecash_sandbox' => '1',
            'qrpay_client_id' => 'C', 'qrpay_secret_id' => 'S', 'qrpay_sandbox' => '1']);
        $this->assertFalse($this->configured('Ecash', 'creds'));
        $this->assertFalse($this->configured('Qrpay', 'creds'));

        config(['payments.sandbox.enabled' => false]);
        $this->settings(['ecash_sandbox' => '', 'qrpay_sandbox' => '']);
        $this->assertTrue($this->configured('Ecash', 'creds'));
        $this->assertTrue($this->configured('Qrpay', 'creds'));
        $this->assertFalse(app(Gateways\QrpayAdapter::class)->supportsRefund());
    }

    public function test_a_store_in_sandbox_mode_is_refused_while_sandboxes_are_off(): void
    {
        [, , $tx] = $this->makeOrderTransaction('noon', 10.0, ['business_id' => 'B', 'application_id' => 'A', 'api_key' => 'K']);
        $creds = new \ReflectionMethod(Gateways\NoonAdapter::class, 'creds');

        config(['payments.sandbox.enabled' => true]);
        $this->assertSame('K', $creds->invoke(app(Gateways\NoonAdapter::class), $tx)['api_key']);
        config(['payments.sandbox.enabled' => false]);
        $this->assertSame('', $creds->invoke(app(Gateways\NoonAdapter::class), $tx)['api_key']);
    }

    public function test_a_sandbox_webhook_is_refused_while_sandboxes_are_off(): void
    {
        $this->settings(['gocardless_access_token' => 'T', 'gocardless_webhook_secret' => 'W', 'gocardless_sandbox' => '1']);
        config(['payments.sandbox.enabled' => false]);
        $body = (string) json_encode(['events' => [['id' => 'EV1', 'resource_type' => 'payments', 'action' => 'confirmed', 'links' => ['payment' => 'PM1']]]]);

        $this->call('POST', '/webhook/gocardless', [], [], [], [
            'HTTP_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'W'), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_gate_blanks_strings_only_when_refused(): void
    {
        config(['payments.sandbox.enabled' => false]);
        $this->assertSame(['k' => '', 'sandbox' => true], GatewaySandbox::gate('x', true, ['k' => 'v', 'sandbox' => true]));
        $this->assertSame(['k' => 'v', 'sandbox' => false], GatewaySandbox::gate('x', false, ['k' => 'v', 'sandbox' => false]));
        config(['payments.sandbox.enabled' => true]);
        $this->assertSame(['k' => 'v', 'sandbox' => true], GatewaySandbox::gate('x', true, ['k' => 'v', 'sandbox' => true]));
    }
}
