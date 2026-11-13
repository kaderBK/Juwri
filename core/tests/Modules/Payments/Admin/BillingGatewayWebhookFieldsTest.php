<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Admin;

use App\Support\PlatformSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\SecretMask;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Support\GatewaySettings;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * WI-10f: the webhook settings the WI-06a adapters read have a form field AND
 * a catalogue key (a key without a field would be wiped by every save).
 */
class BillingGatewayWebhookFieldsTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
    }

    public function test_paypal_webhook_id_has_a_field_and_is_saved(): void
    {
        $admin = $this->admin();
        $paypal = PaymentGateway::where('payment_folder', 'paypal')->firstOrFail();
        $this->assertContains('paypal_webhook_id', GatewaySettings::keysFor('paypal'));

        $this->actingAs($admin)->get(route('admin.gateways.edit', $paypal), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSeeHtml('name="paypal_webhook_id"')->assertSeeHtml('data-ui-panel-save');

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.gateways.update', $paypal), [
            'title' => 'PayPal', 'active' => 1, 'paypal_webhook_id' => 'WH-123',
        ]));
        $this->assertSame('WH-123', PlatformSettings::stored('paypal_webhook_id'));
    }

    public function test_razorpay_webhook_secret_is_a_masked_encrypted_password_field(): void
    {
        $admin = $this->admin();
        $razorpay = PaymentGateway::where('payment_folder', 'razorpay')->firstOrFail();
        $this->assertContains('razorpay_webhook_secret', GatewaySettings::keysFor('razorpay'));
        $this->assertTrue(PlatformSecrets::isSecret('razorpay_webhook_secret'));

        $this->actingAs($admin)->get(route('admin.gateways.edit', $razorpay), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSeeHtml('type="password" id="razorpay_webhook_secret" name="razorpay_webhook_secret"')->assertSeeHtml('webhook/razorpay');

        $form = ['title' => 'Razorpay', 'active' => 1, 'razorpay_api_key' => 'rzp_test_x', 'razorpay_secret_key' => 'sec'];
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.gateways.update', $razorpay), $form + ['razorpay_webhook_secret' => 'whsec_razor_1']));
        $stored = PlatformSettings::stored('razorpay_webhook_secret');
        $this->assertNotSame('whsec_razor_1', $stored, 'stored encrypted');
        $this->assertSame('whsec_razor_1', PlatformSecrets::decrypt($stored));

        // Saving the mask back (field left untouched) keeps the stored secret.
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.gateways.update', $razorpay), $form + ['razorpay_webhook_secret' => SecretMask::mask('whsec_razor_1')]));
        $this->assertSame('whsec_razor_1', PlatformSecrets::decrypt(PlatformSettings::stored('razorpay_webhook_secret')));
    }

    public function test_store_level_razorpay_webhook_secret_is_vaulted_and_saved_by_the_store_form(): void
    {
        $this->assertTrue(GatewaySecretVault::isSecret('restaurant_razorpay_webhook_secret'));
        $this->assertContains('restaurant_razorpay_webhook_secret', StoreSettingsService::OPTION_KEYS);
        $view = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("['webhook_secret','Webhook Secret']", $view);
        $this->assertStringContainsString("'restaurant_razorpay_webhook_secret',", $view);
    }

    public function test_store_payments_tab_has_refund_opt_in_webhook_urls_and_paytabs_region_select(): void
    {
        $this->assertContains('auto_refunds_enabled', StoreSettingsService::OWNER_KEYS);
        $view = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString('name="auto_refunds_enabled"', $view);
        $this->assertStringContainsString("['razorpay', 'paytm', 'iyzico', 'ccavenue', 'telr', 'moyasar']", $view);
        $this->assertStringContainsString('name="restaurant_paytabs_region"', $view);
        foreach (['ARE', 'SAU', 'OMN', 'JOR', 'EGY', 'IRQ', 'PSE', 'GLOBAL'] as $region) {
            $this->assertStringContainsString("'{$region}' =>", $view);
        }
    }

    public function test_platform_auto_refunds_switch_saves_off_by_default(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch"[^>]*name="auto_refunds_enabled" value="1"(?![^>]*checked)/', $html);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.settings.update'), ['billing_settings' => 1, 'auto_refunds_enabled' => '1']));
        $this->assertSame('1', PlatformSettings::stored('auto_refunds_enabled'));
    }

    /**
     * Phase 4 item 18: every gateway row has an admin form (before: Tap, Flutterwave and wallet transfer had none, so
     * their edit page failed), and the Tap / Moyasar keys are catalogued, secret and saved.
     */
    public function test_every_gateway_has_an_admin_form_and_tap_moyasar_keys_are_saved(): void
    {
        config(['payments.show_deferred_gateways' => true]);
        $admin = $this->admin();
        foreach (PaymentGateway::query()->pluck('payment_folder') as $folder) {
            $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/'.$folder.'.blade.php'), "admin form for {$folder}");
        }
        foreach (['tap', 'moyasar', 'flutterwave', 'wallet_transfer'] as $folder) {
            $gateway = PaymentGateway::where('payment_folder', $folder)->firstOrFail();
            $this->actingAs($admin)->get(route('admin.gateways.edit', $gateway), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        }
        $this->assertTrue(PlatformSecrets::isSecret('moyasar_webhook_secret'));
        $this->assertTrue(GatewaySecretVault::isSecret('restaurant_moyasar_secret_key'));
        $this->assertContains('restaurant_moyasar_webhook_secret', StoreSettingsService::OPTION_KEYS);

        $moyasar = PaymentGateway::where('payment_folder', 'moyasar')->firstOrFail();
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.gateways.update', $moyasar), [
            'title' => 'Moyasar', 'active' => 1, 'moyasar_secret_key' => 'sk_test_x', 'moyasar_webhook_secret' => 'tok_1',
        ]));
        $this->assertSame('tok_1', PlatformSecrets::decrypt(PlatformSettings::stored('moyasar_webhook_secret')));
        $tap = PaymentGateway::where('payment_folder', 'tap')->firstOrFail();
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.gateways.update', $tap), ['title' => 'Tap', 'active' => 1, 'tap_secret_key' => 'sk_test_t']));
        $this->assertSame('sk_test_t', PlatformSecrets::decrypt(PlatformSettings::stored('tap_secret_key')));
    }
}
