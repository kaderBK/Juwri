<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Database\Seeders\VerticalShowcaseSeeder;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Tests\Support\TestPlans;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-05 / THEME-CONTRACT 5.2: what the `table` theme shows the shopper is the server's number. The quote the cart and the
 * checkout print, the receipt of the tracking page and the Order that was really charged agree to the minor unit — for a
 * delivery order with a modifier, quantity 2, a coupon and a tip, in a 3-decimal currency with a fixed tax, and for a
 * priced variant. The theme prints `Money.formatted` as sent (never recomputed), so the HTML must carry the view model's
 * own strings.
 */
class KitTableTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function table(Store $store): void
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'table');
    }

    /** @return array<string, mixed> the theme's store page data for the store */
    private function home(Store $store): array
    {
        return app(KitStorefront::class)->home($store, Request::create('/'.$store->slug), 'table');
    }

    /** @param  array<string, mixed>  $quote */
    private function assertThemePrints(array $quote, string $html): void
    {
        $this->assertSame(1, preg_match('#<dd data-juwri-total>'.preg_quote($quote['total']['formatted'], '#').'</dd>#u', $html), 'the cart total is the quote total, as sent');
        foreach ($quote['rows'] as $row) {
            $this->assertStringContainsString('>'.($row['amount_label'] ?? $row['amount']['formatted']).'</dd>', $html, 'row '.$row['key']);
        }
        foreach ($quote['lines'] as $line) {
            $this->assertStringContainsString($line['line_total']['formatted'], $html);
        }
    }

    public function test_a_kwd_delivery_order_with_a_modifier_quantity_two_a_coupon_a_tip_and_a_fixed_tax_prints_the_order_total(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $store = Store::where('slug', 'crave-demo')->firstOrFail();
        $this->table($store);
        StoreSettings::setMany((int) $store->id, ['currency_code' => 'KWD', 'currency_sign' => 'KWD', 'restaurant_tax_type' => 'fixed', 'restaurant_taxx_charge' => '0.250']);
        Coupon::create(['user_id' => $store->user_id, 'code' => 'KIT10', 'coupon_type' => 'percentage', 'percentage' => 10, 'limit' => 0, 'used' => 0, 'applies_to' => 'food_order', 'status' => 1]);

        $s = new ViewModelScenario($store, 'table');
        $cart = $s->cart();
        $this->assertSame(2, $cart[0]['qty'], 'the first line has quantity 2');
        $this->assertNotSame([], $cart[0]['options'], 'the first line carries a modifier');

        $args = ['cart' => $cart, 'orderType' => 'delivery', 'coupon' => 'KIT10', 'tipMinor' => 500];
        $quote = $s->build('en', 'cart', $args)['page']['cart'];
        $this->assertTrue($quote['coupon']['applied']);
        $this->assertNotNull(collect($quote['rows'])->firstWhere('key', 'tip'));
        $this->assertSame(250, collect($quote['rows'])->firstWhere('key', 'tax')['amount']['minor'], 'the fixed tax is 0.250 KWD');
        $this->assertMatchesRegularExpression('/\d\.\d{3}$/u', $quote['total']['formatted'], 'KWD prints 3 decimals');

        $html = view('store_templates.table.index', ['cart' => $quote, 'open' => 'cart'] + $this->home($store))->render();
        $this->assertThemePrints($quote, $html);

        $order = $s->place($cart, ['type' => 'delivery', 'address' => 'Salmiya, Block 1', 'tip_amount' => 0.5, 'coupon_code' => 'KIT10']);
        $this->assertSame((int) $order->fresh()->grand_total_minor, $quote['total']['minor'], 'quoted total == Order total');

        $track = app(KitStorefront::class)->track($store, $order->fresh(), 'table');
        $receipt = $track['vm']['page']['order']['quote'];
        $this->assertSame($quote['total'], $receipt['total'], 'the receipt carries the quoted total');
        $trackHtml = view('store_templates.table.track', $track)->render();
        $this->assertStringContainsString('<bdi class="c-money">'.$quote['total']['formatted'].'</bdi>', $trackHtml);
        foreach ($receipt['lines'] as $line) {
            $this->assertStringContainsString($line['line_total']['formatted'], $trackHtml);
        }
        $sum = 0;
        foreach ($receipt['rows'] as $row) {
            if (empty($row['informational'])) {
                $sum += $row['amount']['minor'];
            }
        }
        $this->assertSame($receipt['total']['minor'], $sum, 'the receipt lines visibly sum to the charged total');
    }

    public function test_a_priced_variant_with_quantity_two_prints_the_order_total(): void
    {
        $this->seed(VerticalShowcaseSeeder::class);
        $store = Store::where('slug', 'retail-demo')->firstOrFail();
        $this->table($store);

        $variant = MenuVariant::query()->whereIn('menu_id', Menu::where('store_id', $store->id)->select('id'))->where('active', 1)->whereNotNull('public_id')->firstOrFail();
        $item = Menu::findOrFail($variant->menu_id);

        $s = new ViewModelScenario($store, 'table');
        $cart = [['item' => (string) $item->public_id, 'qty' => 2, 'options' => [], 'variant' => (string) $variant->public_id]];
        $quote = $s->build('en', 'cart', ['cart' => $cart])['page']['cart'];

        $html = view('store_templates.table.index', ['cart' => $quote, 'open' => 'cart'] + $this->home($store))->render();
        $this->assertThemePrints($quote, $html);

        $type = OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type']);
        $order = $s->place($cart, ['type' => $type]);
        $this->assertSame((int) $order->fresh()->grand_total_minor, $quote['total']['minor'], 'quoted total == Order total');
    }
}
