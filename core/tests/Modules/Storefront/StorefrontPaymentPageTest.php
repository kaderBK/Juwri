<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-03 — contract of the storefront payment page (GET /payment/{transaction},
 * view store_templates.payment) that a diner lands on for an online order.
 *
 * Written against the page before it was rebuilt, so the rebuilt page keeps
 * what callers rely on: the form posts to payment.pay, every installed gateway
 * is one `payment_method` radio (id = folder, value = gateway id) with its
 * title, Paystack asks for an email, the others say the diner is redirected,
 * a flashed alert is shown, and no installed gateway means an explicit notice.
 */
class StorefrontPaymentPageTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_lists_each_installed_gateway_as_a_payment_method_radio(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card (Stripe)');
        StoreOption::updateStoreOption($store->id, 'restaurant_paystack_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_paystack_title', 'Paystack');

        $res = $this->get("/payment/{$tx->id}");

        $res->assertOk();
        $res->assertSeeHtml('action="'.route('payment.pay', $tx->id).'"');
        $res->assertSeeHtml('method="POST"');
        $res->assertSeeHtml('name="_token"');
        $res->assertSeeHtml('id="stripe"');
        $res->assertSeeHtml('value="stripe"');
        $res->assertSee('Card (Stripe)');
        $res->assertSeeHtml('id="paystack"');
        $res->assertSeeHtml('value="paystack"');
        $res->assertSeeHtml('name="payment_method"');
        $res->assertSee($store->title);
    }

    public function test_first_gateway_is_preselected_server_side(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_paystack_install', '1');

        $html = $this->get("/payment/{$tx->id}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'checked="checked"') + substr_count($html, ' checked>'), 'exactly one method is preselected');
        $this->assertMatchesRegularExpression('/id="stripe"[^>]*checked/', $html, 'the first installed gateway is the default choice');
    }

    public function test_paystack_asks_for_an_email_and_redirect_gateways_say_so(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('paystack', 50.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');

        $res = $this->get("/payment/{$tx->id}");

        $res->assertOk();
        $res->assertSeeHtml('name="email"');
        $res->assertSeeHtml('type="email"');
        $res->assertSee('Next you will go to the payment page to finish paying.');
    }

    public function test_no_installed_gateway_shows_a_notice_and_no_submit(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '0');

        $res = $this->get("/payment/{$tx->id}");

        $res->assertOk();
        $res->assertSee('No payment methods available right now please try again later.');
        $res->assertDontSeeHtml('name="payment_method"');
        $res->assertDontSeeHtml('type="submit"');
    }

    public function test_flashed_alert_is_shown(): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 50.0);

        $res = $this->withSession(['app_alert_message' => 'Card was declined'])->get("/payment/{$tx->id}");

        $res->assertOk();
        $res->assertSee('Card was declined');
    }
}
