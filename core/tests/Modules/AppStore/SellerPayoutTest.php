<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Theme;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Services\AppBillingService;
use App\Services\AppInstallService;
use App\Services\SellerPayoutService;
use App\Services\ThemePurchaseService;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4-6 (Option A): every marketplace sale credits the seller's wallet with
 * the configured share — theme purchases, app installs, renewals, and
 * metered usage — idempotently and without ever breaking the buyer's flow.
 */
class SellerPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(float $balance = 0): User
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        if ($balance > 0) {
            WalletService::credit($user, $balance, 'seed');
        }

        return $user;
    }

    public function test_theme_sale_credits_the_seller_seventy_percent(): void
    {
        $seller = $this->makeUser();
        $buyer = $this->makeUser(100);
        $theme = Theme::create(['name' => 'Paid', 'slug' => 'paid-theme', 'status' => 'published', 'price' => 40, 'seller_id' => $seller->id]);

        new ThemePurchaseService()->purchase($buyer, $theme);

        $this->assertSame(60.0, WalletService::balanceFor($buyer));
        $this->assertSame(28.0, WalletService::balanceFor($seller)); // 40 × 0.70
    }

    public function test_platform_owned_theme_credits_nobody(): void
    {
        $buyer = $this->makeUser(50);
        $theme = Theme::create(['name' => 'Ours', 'slug' => 'ours', 'status' => 'published', 'price' => 20, 'seller_id' => null]);

        new ThemePurchaseService()->purchase($buyer, $theme);

        $this->assertSame(30.0, WalletService::balanceFor($buyer));
        $this->assertDatabaseMissing('wallet_transactions', ['reason' => 'theme sale: ours']);
    }

    public function test_share_is_configurable_and_rounds_down(): void
    {
        config(['platform.marketplace.seller_share' => 0.85]);
        $seller = $this->makeUser();

        // 9.99 × 0.85 = 8.4915 → floor to 8.49 (platform absorbs the dust).
        $credited = SellerPayoutService::creditSale($seller->id, 9.99, 'test:1', 'test');

        $this->assertSame(8.49, $credited);
        $this->assertSame(8.49, WalletService::balanceFor($seller));

        // Same ref again → idempotent, no double pay.
        SellerPayoutService::creditSale($seller->id, 9.99, 'test:1', 'test');
        $this->assertSame(8.49, WalletService::balanceFor($seller));
    }

    public function test_app_install_renewal_and_usage_all_credit_the_developer(): void
    {
        $developer = $this->makeUser();
        $owner = $this->makeUser(100);

        $app = App::create([
            'name' => 'Dev App', 'slug' => 'dev-app', 'scopes' => ['menu:read'],
            'status' => 'approved', 'developer_user_id' => $developer->id,
            'billing_type' => 'recurring', 'price' => 10, 'billing_period_days' => 30,
        ]);

        // Install → dev gets 7.
        new AppInstallService()->install($app, $owner);
        $this->assertSame(7.0, WalletService::balanceFor($developer));

        // Renewal → dev gets another 7.
        AppInstallation::query()->update(['current_period_ends_at' => now()->subDay()]);
        new AppBillingService()->renewDue();
        $this->assertSame(14.0, WalletService::balanceFor($developer));

        // Usage app → share of the metered charge.
        $usageApp = App::create([
            'name' => 'Meter App', 'slug' => 'meter-app', 'scopes' => ['menu:read'],
            'status' => 'approved', 'developer_user_id' => $developer->id,
            'billing_type' => 'usage', 'price' => 0, 'usage_unit' => 'msg', 'usage_price' => 0.10,
        ]);
        $installation = new AppInstallService()->install($usageApp, $owner);
        new AppBillingService()->recordUsage($installation, 100, 'evt-x'); // charge 10 → share 7

        $this->assertSame(21.0, WalletService::balanceFor($developer));
    }
}
