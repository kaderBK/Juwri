<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Customer;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P2-8: the commerce runtime seam — the server-built config and the
 * `{% commerce %}` tag that emits it + the versioned bundle exactly once.
 * (End-to-end order placement is verified in the browser during the P2-9 port.)
 */
class CommerceRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);
    }

    private function vars(): array
    {
        $store = new Store(['slug' => 'pizza']);
        $store->id = 5;

        $options = (object) [
            'currency_sign' => '$', 'currency_pos' => '1',
            'restaurant_taxx_charge' => 10, 'restaurant_tax_label' => 'VAT',
            'restaurant_delivery_charge' => 3, 'min_order_value' => 5,
            'allow_coupons' => 1, 'allow_tips' => 1, 'allow_call_waiter' => 1,
            'customer_auth_mode' => 'guest',
        ];

        $item = new Menu(['price' => 12]);
        $item->id = 1;
        $item->name = 'Burger';
        $item->image = 'b.png';
        $item->is_sold_out = false;
        // Real shape: a Size dimension + a combination row {dimId: choiceIdx}.
        $dimension = tap(new MenuVariantOption(['title' => 'Size', 'options' => ['Small', 'Large']]), fn ($g) => $g->id = 40);
        $item->setRelation('variantOptions', collect([$dimension]));
        $item->setRelation('variants', collect([tap(new MenuVariant(['price' => 15, 'options' => [40 => 1]]), fn ($v) => $v->id = 1)]));
        $item->setRelation('extras', collect([tap(new MenuExtra(['title' => 'Cheese', 'price' => 1]), fn ($e) => $e->id = 1)]));

        return [
            'store' => $store,
            'postOptions' => $options,
            'total_menus' => [1 => $item],
            'flags' => ['allow_order' => true, 'on_table' => true, 'takeaway' => true, 'delivery' => true, 'payment' => true, 'scheduling' => false],
            'customer' => null,
            'table' => null,
        ];
    }

    public function test_config_maps_the_server_contract(): void
    {
        $cfg = new CommerceRuntime()->config($this->vars());

        $this->assertSame(5, $cfg['storeId']);
        $this->assertSame('pizza', $cfg['restaurantSlug']);
        $this->assertSame('$', $cfg['currency']['sign']);
        $this->assertSame(10.0, $cfg['charges']['tax']);
        $this->assertSame('VAT', $cfg['charges']['taxLabel']);
        $this->assertSame(3.0, $cfg['charges']['delivery']);
        $this->assertSame(5.0, $cfg['charges']['minOrder']);
        $this->assertTrue($cfg['flags']['allowCallWaiter']); // allow_call_waiter && on_table
        $this->assertTrue($cfg['flags']['allowCoupons']);
        $this->assertSame(route('store.submitOrder', 5), $cfg['endpoints']['submitOrder']);
        $this->assertSame(route('store.applyCoupon', 'pizza'), $cfg['endpoints']['coupon']);

        // B6: the account panel endpoints — customer.* key on store_id in
        // the POST body (route param = id), orderLookup keys on the slug.
        $this->assertSame(route('customer.login', 5), $cfg['endpoints']['login']);
        $this->assertSame(route('customer.register', 5), $cfg['endpoints']['register']);
        $this->assertSame(route('customer.logout', 5), $cfg['endpoints']['logout']);
        $this->assertSame(route('customer.otp.request', 5), $cfg['endpoints']['otpRequest']);
        $this->assertSame(route('customer.otp.verify', 5), $cfg['endpoints']['otpVerify']);
        $this->assertSame(route('store.orderLookup', 'pizza'), $cfg['endpoints']['orderLookup']);

        // B6: auth flags the account panel reads (guest fixture → not logged in).
        $this->assertSame('guest', $cfg['flags']['authMode']);
        $this->assertFalse($cfg['flags']['isLoggedIn']);
        $this->assertNull($cfg['flags']['customerName']);

        // P7-5b: a guest has no store credit — pay-with-credit stays off.
        $this->assertFalse($cfg['flags']['allowStoreCredit']);
        $this->assertSame(0.0, $cfg['storeCredit']['balance']);

        $item = $cfg['items'][1];
        $this->assertSame('Burger', $item['name']);
        $this->assertSame('Large', $item['variants'][0]['label']);
        $this->assertSame(15.0, $item['variants'][0]['price']);
        $this->assertSame('Cheese', $item['extras'][0]['title']);
        $this->assertNotSame('', $item['img']);
        // P8-5: product-view fields.
        $this->assertArrayHasKey('desc', $item);
        $this->assertIsArray($item['gallery']);
        $this->assertNotSame('', $item['gallery'][0]);
        $this->assertArrayHasKey('rating', $item);
        $this->assertArrayHasKey('popular', $item);
    }

    public function test_config_parses_manual_currency_rates(): void
    {
        // P8-8: "CODE = rate" lines → {CODE: rate}; base is implicit (1.0).
        $vars = $this->vars();
        $vars['postOptions']->currency_rates_manual = "EUR = 0.9\nGBP=0.8\nbad line\nZERO = 0";

        $cfg = new CommerceRuntime()->config($vars);

        $this->assertSame(['EUR' => 0.9, 'GBP' => 0.8], $cfg['currency']['rates']);
        $this->assertSame('USD', $cfg['currency']['code']);
    }

    public function test_config_has_no_rates_by_default(): void
    {
        $this->assertSame([], new CommerceRuntime()->config($this->vars())['currency']['rates']);
    }

    public function test_config_exposes_logged_in_customer_to_the_account_panel(): void
    {
        // B6: a logged-in diner flips isLoggedIn + surfaces the name only
        // (CustomerDrop still leaks nothing else — this is the panel's greeting).
        $vars = $this->vars();
        $vars['customer'] = (object) ['name' => 'Sam'];

        $cfg = new CommerceRuntime()->config($vars);

        $this->assertTrue($cfg['flags']['isLoggedIn']);
        $this->assertSame('Sam', $cfg['flags']['customerName']);
    }

    // P7-5b: a logged-in diner with a positive balance turns on pay-with-credit
    // and exposes the balance in the runtime bridge.
    public function test_config_exposes_store_credit_for_logged_in_customer(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'sc-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'd', 'main_image' => 'a.png',
            'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $customer = Customer::create(['name' => 'Sam', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        $acct = CustomerWalletService::accountFor($customer);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -30, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
            ['account' => $acct, 'amount' => 30, 'ref' => "seed:{$customer->id}:usr"],
        ]);

        $vars = $this->vars();
        $vars['store'] = $store;
        $vars['customer'] = $customer;

        $cfg = new CommerceRuntime()->config($vars);

        $this->assertTrue($cfg['flags']['allowStoreCredit']);
        $this->assertSame(30.0, $cfg['storeCredit']['balance']);
        $this->assertStringContainsString('30.00', $cfg['storeCredit']['formatted']);
    }

    public function test_registers_point_at_the_versioned_bundle(): void
    {
        config(['platform.commerce.runtime_version' => 'v1']);
        $reg = new CommerceRuntime()->registers($this->vars());

        $this->assertIsArray($reg['commerce_config']);
        $this->assertStringContainsString('assets/commerce-runtime/v1/commerce.js', $reg['commerce_runtime_url']);
    }

    public function test_commerce_tag_emits_config_and_bundle_exactly_once(): void
    {
        $registers = [
            'commerce_config' => ['storeId' => 5],
            'commerce_runtime_url' => 'https://cdn.test/assets/commerce-runtime/v1/commerce.js',
            'csp_nonce' => 'abc123',
        ];

        $out = $this->renderer()->render('A{% commerce %}B{% commerce %}C', [], 'live', $registers);

        $this->assertSame(1, substr_count($out, 'window.MB_COMMERCE='), 'config emitted once');
        $this->assertSame(1, substr_count($out, 'commerce.js'), 'bundle emitted once');
        $this->assertStringContainsString('nonce="abc123"', $out);
        $this->assertStringStartsWith('A', $out);
        $this->assertStringEndsWith('BC', $out); // second {% commerce %} is inert
    }

    public function test_commerce_tag_escapes_script_breakout_in_config(): void
    {
        $registers = [
            'commerce_config' => ['name' => '</script><script>alert(1)</script>'],
            'commerce_runtime_url' => 'https://cdn.test/commerce.js',
        ];

        $out = $this->renderer()->render('{% commerce %}', [], 'live', $registers);

        // Only the runtime's own two closers — the injected </script> is hex-escaped.
        $this->assertSame(2, substr_count($out, '</script>'));
        $this->assertStringNotContainsString('<script>alert(1)', $out);
    }

    public function test_commerce_tag_is_inert_without_config(): void
    {
        $out = $this->renderer()->render('X{% commerce %}Y', []);
        $this->assertSame('XY', $out);
    }
}
