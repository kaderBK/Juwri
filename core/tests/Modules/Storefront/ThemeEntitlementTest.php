<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\Theme;
use App\Services\ThemePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Mail\WalletPurchaseReceiptMail;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\WalletPurchaseReceipt;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-1: entitlement — purchases are per-account licenses on the wallet rails,
 * and the render path serves a PAID marketplace theme only to licensed
 * accounts (unlicensed → first-party Blade fallback, never blank).
 */
class ThemeEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

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

    private function makeRestaurant(string $template): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return [$owner, $store];
    }

    public function test_purchase_debits_the_wallet_once_and_licenses_the_account(): void
    {
        $theme = Theme::create(['name' => 'Crave', 'slug' => 'crave', 'status' => 'published', 'price' => 25]);
        $buyer = User::factory()->create(['status' => 1]);
        WalletService::credit($buyer, 60, 'seed');

        $service = new ThemePurchaseService;
        $first = $service->purchase($buyer, $theme);
        $again = $service->purchase($buyer, $theme); // idempotent

        $this->assertSame($first->id, $again->id);
        $this->assertSame(35.0, WalletService::balanceFor($buyer), 'charged exactly once');
        $this->assertSame(25.0, (float) $first->price_paid);
        $this->assertTrue(ThemePurchaseService::entitled($buyer, $theme));
    }

    public function test_free_and_reference_themes_need_no_license(): void
    {
        $free = Theme::create(['name' => 'Freebie', 'slug' => 'freebie', 'status' => 'published', 'price' => 0]);
        $user = User::factory()->create(['status' => 1]);

        $this->assertTrue(ThemePurchaseService::entitled($user, $free));
        $this->assertTrue(ThemePurchaseService::entitledForSlug($user->id, 'crave'), 'no Theme row → reference theme, allowed');
    }

    public function test_paid_theme_renders_only_for_licensed_accounts(): void
    {
        // 'crave' resolves from the in-repo reference dir; a priced Theme row
        // makes it a PAID marketplace theme for entitlement purposes.
        $theme = Theme::create(['name' => 'Crave', 'slug' => 'crave', 'status' => 'published', 'price' => 25]);
        [$owner, $store] = $this->makeRestaurant('mp:crave@1');

        // Unlicensed → Blade fallback (page still renders, no Liquid markup).
        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('crave-lq', $html);
        $this->assertStringContainsString($store->title, $html, 'fallback rendered, never blank');

        // Purchase → the Liquid theme serves.
        WalletService::credit($owner, 30, 'seed');
        new ThemePurchaseService()->purchase($owner, $theme);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringContainsString('crave-lq', $html, 'licensed account gets the marketplace theme');
    }

    public function test_insufficient_funds_blocks_the_purchase(): void
    {
        $theme = Theme::create(['name' => 'Pricey', 'slug' => 'pricey', 'status' => 'published', 'price' => 100]);
        $buyer = User::factory()->create(['status' => 1]);
        WalletService::credit($buyer, 5, 'seed');

        $this->expectException(\RuntimeException::class);
        new ThemePurchaseService()->purchase($buyer, $theme);
    }

    /** P6-252: a wallet-paid theme licence writes one transaction + receipt and queues one receipt e-mail, even on retry. */
    public function test_a_paid_theme_gets_one_receipt_and_one_receipt_email(): void
    {
        Mail::fake();
        $theme = Theme::create(['name' => 'Crave', 'slug' => 'crave', 'status' => 'published', 'price' => 25]);
        $buyer = User::factory()->create(['status' => 1]);
        WalletService::credit($buyer, 60, 'seed');

        $service = new ThemePurchaseService;
        $service->purchase($buyer, $theme);
        $service->purchase($buyer, $theme);

        $txn = Transaction::query()->where('user_id', $buyer->id)->where('transaction_method', 'theme_purchase')->sole();
        $this->assertSame('wallet', $txn->transaction_gatway);
        $this->assertSame(25.0, (float) $txn->amount);
        $receipt = BillingReceipt::query()->where('transaction_id', $txn->id)->sole();
        $this->assertSame(25.0, (float) $receipt->amount);
        Mail::assertQueued(WalletPurchaseReceiptMail::class, 1);
        Mail::assertQueued(WalletPurchaseReceiptMail::class, fn ($m) => $m->hasTo($buyer->email));

        // A direct retry of the receipt step itself is a no-op too.
        WalletPurchaseReceipt::issue($txn);
        Mail::assertQueued(WalletPurchaseReceiptMail::class, 1);
        $this->assertSame(1, BillingReceipt::query()->where('transaction_id', $txn->id)->count());
    }
}
