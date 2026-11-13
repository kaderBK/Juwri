<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Pci;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Pci\PaymentPageProbes;
use Juwri\Core\Pci\PaymentScriptInventory;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsPaymentPagePolicy;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * PCI DSS 4.0 req. 6.4.3: every payment page is served with its strict, nonce-based CSP (no 'unsafe-inline', no
 * scheme-wide script source; only the inventory's scripts) that reports to the stored report endpoint, and the HTML
 * a browser receives has no script outside the inventory, no third-party script without its pinned SRI and no
 * inline script without the nonce.
 */
class PaymentPageCspTest extends TestCase
{
    use AssertsPaymentPagePolicy;
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        view()->share(['languages' => collect(), 'pages' => collect(), 'admin_languages' => collect()]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_the_storefront_payment_page_has_the_strict_policy_and_only_inventory_scripts(): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 50.0);

        $res = $this->get("/payment/{$tx->id}")->assertOk();

        $d = $this->assertStrictPaymentPolicy($res, 'storefront.pay');
        // Choice page: the gateway answers the POST with a redirect to its hosted page.
        $this->assertSame("'self' https:", $d['form-action']);
        // The SRI-pinned Popper from cdnjs (slider popup themes) is allowed by its exact path, never the whole CDN.
        $this->assertStringContainsString('https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.11.0/umd/popper.min.js', $d['script-src']);
        $this->assertStringNotContainsString('https://cdnjs.cloudflare.com ', $d['script-src'].' ');
    }

    public function test_third_party_chat_widgets_never_load_on_a_payment_page(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        config(['settings.storefront_wati_widget' => true, 'settings.tawk_to' => (object) ['status' => '1', 'chat_link' => 'https://tawk.to/chat/abc/def']]);
        $this->assertNotEmpty($store->phone);

        $html = (string) $this->get("/payment/{$tx->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('clare.ai', $html);
        $this->assertStringNotContainsString('embed.tawk.to', $html);
    }

    public function test_a_gateway_hand_off_from_checkout_gets_its_own_page_policy(): void
    {
        PaymentGateway::create(['payment_folder' => 'midtrans', 'payment_title' => 'Midtrans', 'payment_install' => '1']);
        [, , $tx] = $this->makeOrderTransaction('midtrans', 150000.0, ['server_key' => 'SB-Mid-server-fake', 'client_key' => 'SB-Mid-client-fake'], 'IDR');
        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-tok', 'redirect_url' => 'https://x'], 201)]);

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'midtrans'])->assertOk()->assertViewIs('owner.billing.gateways.midtrans');

        $d = $this->assertStrictPaymentPolicy($res, 'gateway.midtrans');
        $this->assertStringContainsString('https://app.sandbox.midtrans.com/snap/snap.js', $d['script-src']);
        $this->assertStringContainsString('https://app.sandbox.midtrans.com', $d['frame-src']);
        $this->assertStringNotContainsString('paystack', $d['script-src'], 'another gateway\'s SDK is not allowed here');
        $this->assertSame("'self'", $d['form-action']);
    }

    /** @return array<string, array{0: string}> */
    public static function probePages(): array
    {
        return ['midtrans' => ['gateway.midtrans'], 'paystack' => ['gateway.paystack'], 'razorpay' => ['gateway.razorpay'],
            'two checkout' => ['gateway.two_checkout'], 'redirect form' => ['gateway.redirect_form'], 'wire transfer' => ['gateway.wire_transfer'],
            'storefront pay' => ['storefront.pay'], 'bank transfer' => ['storefront.bank_transfer'], 'wallet transfer' => ['storefront.wallet_transfer']];
    }

    #[DataProvider('probePages')]
    public function test_each_probe_renders_its_payment_page_with_the_strict_policy(string $page): void
    {
        $this->makeOrderTransaction('stripe', 10.0); // the probe store

        $res = $this->get(PaymentPageProbes::url($page))->assertOk();

        $this->assertStrictPaymentPolicy($res, $page);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame(0, Transaction::query()->where('product_name', 'Payment page check')->count(), 'a probe stores nothing');
    }

    public function test_the_redirect_form_may_post_only_to_the_providers(): void
    {
        $d = self::directives((string) $this->get(PaymentPageProbes::url('gateway.redirect_form'))->assertOk()->headers->get('Content-Security-Policy'));

        $this->assertSame("'self' https://secure.ccavenue.com https://test.ccavenue.com https://securegw.paytm.in https://securegw-stage.paytm.in", $d['form-action']);
    }

    public function test_every_probe_is_a_payment_page_of_the_inventory(): void
    {
        foreach (self::probePages() as [$page]) {
            $this->assertContains($page, PaymentPageProbes::pages());
        }
        foreach (PaymentPageProbes::pages() as $page) {
            $this->assertNotNull(PaymentScriptInventory::page($page), "probe {$page} is not an inventory page");
        }
    }

    public function test_the_probe_url_must_be_signed_and_probes_can_be_switched_off(): void
    {
        $this->get('/pci/probe/gateway.paystack')->assertForbidden();
        $this->get(PaymentPageProbes::url('no.such.page'))->assertNotFound();

        config(['payment_scripts.monitor.probes' => false]);
        $this->get(PaymentPageProbes::url('gateway.paystack'))->assertNotFound();
    }

    public function test_report_only_when_enforcement_is_switched_off(): void
    {
        config(['payment_scripts.csp.enforce' => false]);

        $res = $this->get(PaymentPageProbes::url('gateway.paystack'))->assertOk();

        $this->assertNull($res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://js.paystack.co/v1/inline.js', (string) $res->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_payment_policy_replaces_a_weaker_policy_and_leaves_other_pages_alone(): void
    {
        Route::middleware('web')->get('/_pci_test/weak', fn () => response()->view('owner.billing.gateways.redirect-form', ['url' => 'https://secure.ccavenue.com/x', 'data' => []])
            ->header('Content-Security-Policy', "script-src * 'unsafe-inline'"));
        Route::middleware('web')->get('/_pci_test/plain', fn () => response('<html><body>plain</body></html>')
            ->header('Content-Security-Policy', "script-src * 'unsafe-inline'"));

        $weak = $this->get('/_pci_test/weak')->assertOk();
        $this->assertStringStartsWith("default-src 'self'", (string) $weak->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString("'unsafe-inline'", self::directives((string) $weak->headers->get('Content-Security-Policy'))['script-src']);

        $plain = $this->get('/_pci_test/plain')->assertOk();
        $this->assertSame("script-src * 'unsafe-inline'; frame-ancestors 'self'", $plain->headers->get('Content-Security-Policy'));
        $this->assertNull($plain->headers->get('Reporting-Endpoints'));
    }

    public function test_a_redirect_carries_no_payment_policy(): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 10.0);

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'no_such_gateway'])->assertRedirect();

        $this->assertNull($res->headers->get('Reporting-Endpoints'));
    }

    public function test_the_storefront_store_currency_option_is_untouched_by_the_probe(): void
    {
        [$store] = $this->makeOrderTransaction('stripe', 10.0);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD');

        $this->get(PaymentPageProbes::url('storefront.pay'))->assertOk();

        $this->assertSame('KWD', store_options($store->id)->currency_code);
    }
}
