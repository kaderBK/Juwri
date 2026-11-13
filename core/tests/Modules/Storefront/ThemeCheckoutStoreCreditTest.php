<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P7-6a (+ WIRING-RUN G3): the Blade checkouts show a
 * pay-with-credit control (posting use_wallet=1, which submitOrder already honours)
 * for a logged-in diner with a positive store-credit balance, and hide it
 * otherwise.
 */
class ThemeCheckoutStoreCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

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
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: Store, 1: Customer} */
    private function restaurantWithCredit(string $template, float $credit): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0',
                'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        // Enable an order type so themes that gate the whole checkout on
        // $allow_order (e.g. aurora) actually render the form. pulse reads its OWN
        // `allow_order` option key (not the controller var), so set that too.
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $customer = Customer::create(['name' => 'Ada', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        if ($credit > 0) {
            $acct = CustomerWalletService::accountFor($customer);
            $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
            LedgerService::post([
                ['account' => $clr, 'amount' => -$credit, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
                ['account' => $acct, 'amount' => $credit, 'ref' => "seed:{$customer->id}:usr"],
            ]);
        }

        return [$store, $customer];
    }

    private function assertThemeShowsCredit(string $theme): void
    {
        [$store, $customer] = $this->restaurantWithCredit($theme, 25.0);

        $res = $this->actingAs($customer, 'customer')->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertSeeHtml(___('Pay with store credit'));
        $res->assertSeeHtml('mbUseWallet'); // the use_wallet control id/name
    }

    // One method per theme — a fresh request avoids the storefront's per-request
    // static caches (e.g. store_options) leaking one restaurant into the next.
    public function test_crave_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('crave');
    }

    public function test_matcha_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('matcha');
    }

    public function test_glow_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('glow');
    }

    public function test_aurora_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('aurora');
    }

    public function test_pulse_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('pulse');
    }

    public function test_vivid_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('vivid');
    }

    // WIRING-RUN G3 — the themes coupons-wallets P7-6a left DEFERRED.
    public function test_velvet_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('velvet');
    }

    public function test_nebula_shows_pay_with_credit(): void
    {
        $this->assertThemeShowsCredit('nebula');
    }

    /**
     * The wallet-balance line now renders through store_money_format() (was an
     * inline money_ltr() concat): the amount carries the store's decimals and the
     * whole unit is LTR-isolated (U+2066…U+2069) so a USD "$" / Saudi Riyal sign
     * never bidi-flips in an RTL storefront.
     */
    public function test_store_credit_balance_renders_through_store_money_format(): void
    {
        [$store, $customer] = $this->restaurantWithCredit('crave', 25.0);
        // USD store: store_money_format keys its LTR-isolate wrap off the currency
        // CODE (USD / Saudi Riyal), so the fixture must declare it to exercise it.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_sign', '$');

        $res = $this->actingAs($customer, 'customer')->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertSeeHtml('25.00');        // store decimals via store_price_format
        $res->assertSeeHtml("\u{2066}");     // whole money unit LTR-isolated (USD "$")
    }

    public function test_theme_hides_pay_with_credit_without_balance(): void
    {
        [$store, $customer] = $this->restaurantWithCredit('crave', 0.0);

        $res = $this->actingAs($customer, 'customer')->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertDontSeeHtml('mbUseWallet');
    }

    public function test_guest_never_sees_pay_with_credit(): void
    {
        [$store] = $this->restaurantWithCredit('aurora', 25.0);

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertDontSeeHtml('mbUseWallet');
    }
}
