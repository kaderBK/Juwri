<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\PartnerEarning;
use App\Models\Theme;
use App\Services\AppInstallService;
use App\Services\SellerPayoutService;
use App\Services\ThemePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\AppUsageRecord;
use Juwri\Modules\AppStore\Models\ThemePurchase;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\SellerEarnings;
use Tests\TestCase;

/**
 * P1-05 slice 8c (characterisation): the public money behaviour of the app/theme marketplace — app list prices and
 * per-unit usage prices, metered usage charges, partner earnings (gross/share/net, currency) and the partner earnings
 * page sums, seller withdrawals (request / reject), theme prices, theme purchases (price paid) and the theme store's
 * free/paid facets and price sort. Platform money (the platform currency). Written against the decimal columns BEFORE
 * the move to `*_minor` and kept green after it: only the storage changes.
 */
class AppStoreMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD']);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function user(float $balance = 0): User
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        if ($balance > 0) {
            WalletService::credit($user, $balance, 'seed');
        }

        return $user;
    }

    public function test_app_prices_usage_and_partner_earnings(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $dev = $this->user();
        $owner = $this->user(100);
        $app = App::create([
            'name' => 'Dev App', 'slug' => 'dev-app', 'scopes' => ['menu:read'], 'status' => 'approved',
            'developer_user_id' => $dev->id, 'billing_type' => 'recurring', 'price' => 9.99, 'billing_period_days' => 30,
        ]);
        $this->assertSame(9.99, (float) $app->fresh()->price);
        $this->assertEquals(9.99, $app->fresh()->toArray()['price']);
        $free = App::create(['name' => 'Free', 'slug' => 'free-app', 'scopes' => ['menu:read'], 'status' => 'approved', 'billing_type' => 'free']);
        $this->assertSame(0.0, (float) $free->fresh()->price, 'column default');

        (new AppInstallService)->install($app, $owner);
        $this->assertSame(6.99, WalletService::balanceFor($dev), 'floor(9.99 × 0.70) to the cent');
        $earning = PartnerEarning::where('partner_id', $dev->id)->first();
        $this->assertSame([9.99, 0.7, 6.99, 'USD'], [$earning->gross, $earning->share, $earning->net, $earning->currency]);
        $this->assertSame([9.99, 6.99], [$earning->toArray()['gross'], $earning->toArray()['net']]);

        $meter = App::create([
            'name' => 'Meter', 'slug' => 'meter-app', 'scopes' => ['menu:read'], 'status' => 'approved', 'developer_user_id' => $dev->id,
            'billing_type' => 'usage', 'price' => 0, 'usage_unit' => 'msg', 'usage_price' => 0.0125,
        ]);
        $this->assertSame(0.0125, (float) $meter->fresh()->usage_price, 'a per-unit rate keeps 4 decimals');
        $installation = (new AppInstallService)->install($meter, $owner);
        $record = (new AppBillingService)->recordUsage($installation, 100, 'evt-1');
        $this->assertSame(1.25, (float) $record->fresh()->charged);
        $this->assertSame(1.25, (float) AppUsageRecord::where('ref', 'evt-1')->first()->charged);
        $this->assertSame(7.86, WalletService::balanceFor($dev), '6.99 + floor(1.25 × 0.70)');

        $page = $this->actingAs($dev)->get(route('partner.earnings'))->assertOk();
        $this->assertEqualsWithDelta(7.86, (float) $page->viewData('earned'), 0.00001);
    }

    public function test_seller_withdrawals(): void
    {
        $seller = $this->user();
        SellerEarnings::seedMatured($seller, 50); // AUDIT-money L-1: only earned money is withdrawable
        $w = SellerPayoutService::requestWithdrawal($seller, 12.5, 'manual');
        $this->assertSame('12.50', $w->fresh()->amount);
        $this->assertSame('12.50', $w->fresh()->toArray()['amount']);
        $this->assertSame(37.5, WalletService::balanceFor($seller));

        SellerPayoutService::rejectWithdrawal($w->fresh(), $this->user(), 'no');
        $this->assertSame(50.0, WalletService::balanceFor($seller));
        $this->assertSame(['rejected', '12.50'], [$w->fresh()->status, $w->fresh()->amount]);
    }

    public function test_theme_prices_purchases_and_store_facets(): void
    {
        $seller = $this->user();
        $buyer = $this->user(100);
        $make = function (string $slug, float $price) use ($seller): Theme {
            $t = Theme::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'published', 'price' => $price, 'category' => 'cafe', 'seller_id' => $seller->id]);
            $t->versions()->create(['semver' => '1.0.0', 'manifest' => ['t' => $slug], 'checksum' => hash('sha256', $slug), 'status' => 'published']);

            return $t;
        };
        $paid = $make('noir', 40.5);
        $make('free', 0);
        $make('cheap', 19.99);
        $this->assertSame(40.5, (float) $paid->fresh()->price);
        $this->assertEquals(40.5, $paid->fresh()->toArray()['price']);

        $purchase = (new ThemePurchaseService)->purchase($buyer, $paid->fresh());
        $this->assertSame(40.5, (float) $purchase->fresh()->price_paid);
        $this->assertSame(40.5, (float) ThemePurchase::where('theme_id', $paid->id)->first()->price_paid);
        $this->assertSame(59.5, WalletService::balanceFor($buyer));

        $res = $this->get(route('marketplace.index', ['sort' => 'price_low']));
        $this->assertSame(['free', 'cheap', 'noir'], $res->viewData('results')->pluck('slug')->all());
        $this->assertSame([1, 2], [$res->viewData('freeCount'), $res->viewData('paidCount')]);
        $high = $this->get(route('marketplace.index', ['sort' => 'price_high', 'price' => 'paid']));
        $this->assertSame(['noir', 'cheap'], $high->viewData('results')->pluck('slug')->all());
    }
}
