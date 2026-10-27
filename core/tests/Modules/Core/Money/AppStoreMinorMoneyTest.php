<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\App;
use App\Models\PartnerEarning;
use App\Models\Theme;
use Juwri\Modules\AppStore\Models\ThemePurchase;
use Juwri\Core\Models\User;
use App\Services\SellerPayoutService;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\LegacyMoneyColumns;
use Tests\TestCase;

/**
 * P1-05 slice 8c: app/theme marketplace money in `*_minor` — platform-currency stamping (KWD fils, JPY whole yen),
 * facts keep their currency after a platform-currency change while catalogue rows follow it when edited, the
 * per-unit usage price stays a 4-decimal rate, per-row SQL sums, legacy rows.
 */
class AppStoreMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_currency_stamping_and_facts(): void
    {
        config(['settings.currency_code' => 'KWD']);
        $app = App::create(['name' => 'A', 'slug' => 'a-app', 'scopes' => [], 'billing_type' => 'recurring', 'price' => 4.125, 'usage_price' => 0.0125]);
        $this->assertSame(['KWD', 4125, 4.125], [$app->currency_code, $app->price_minor, $app->fresh()->price]);
        $this->assertSame(0.0125, (float) DB::table('apps')->where('id', $app->id)->value('usage_price'));
        $theme = Theme::create(['name' => 'T', 'slug' => 't-theme', 'status' => 'published', 'price' => 12.5]);
        $this->assertSame(['KWD', 12500], [$theme->currency_code, $theme->price_minor]);

        $seller = User::factory()->create();
        WalletService::credit($seller, 20, 'seed');
        $w = SellerPayoutService::requestWithdrawal($seller, 1.235, 'manual');
        $this->assertSame(['KWD', 1235, '1.24'], [$w->currency_code, $w->amount_minor, $w->fresh()->amount]);
        SellerPayoutService::creditSale($seller->id, 10, 'sale:1', 'theme sale: t');
        $earning = PartnerEarning::where('source_ref', 'sale:1')->first();
        $this->assertSame(['KWD', 10000, 7000], [$earning->currency, $earning->gross_minor, $earning->net_minor]);

        // The platform moves to JPY: facts keep KWD; catalogue rows follow when edited.
        config(['settings.currency_code' => 'JPY']);
        $purchase = ThemePurchase::create(['user_id' => $seller->id, 'theme_id' => $theme->id, 'price_paid' => 1500.4]);
        $this->assertSame(['JPY', 1500], [$purchase->currency_code, $purchase->price_paid_minor]);
        $w->fresh()->update(['note' => 'x']);
        $this->assertSame(['KWD', 1235], [$w->fresh()->currency_code, $w->fresh()->amount_minor]);
        $app->fresh()->update(['price' => 990.6]);
        $this->assertSame(['JPY', 991, 991.0], [$app->fresh()->currency_code, $app->fresh()->price_minor, $app->fresh()->price]);
        $theme->fresh()->update(['name' => 'Renamed']);
        $this->assertSame(['KWD', 12500], [$theme->fresh()->currency_code, $theme->fresh()->price_minor], 'an untouched price is not re-stamped');
    }

    public function test_earnings_sum_per_row_and_legacy_rows(): void
    {
        config(['settings.currency_code' => 'USD']);
        $p = User::factory()->create();
        PartnerEarning::create(['partner_id' => $p->id, 'source_type' => 'app_sale', 'source_ref' => 'r1', 'gross' => 10, 'share' => 0.7, 'net' => 7, 'currency' => 'USD', 'status' => 'pending']);
        PartnerEarning::create(['partner_id' => $p->id, 'source_type' => 'app_sale', 'source_ref' => 'r2', 'gross' => 1.5, 'share' => 0.7, 'net' => 1.05, 'currency' => 'KWD', 'status' => 'pending']);
        PartnerEarning::create(['partner_id' => $p->id, 'source_type' => 'app_sale', 'source_ref' => 'r3', 'gross' => 1, 'share' => 0.7, 'net' => 0.7, 'currency' => null, 'status' => 'pending']);
        $this->assertSame('USD', PartnerEarning::where('source_ref', 'r3')->value('currency'), 'stamped with the platform currency');
        $sum = (float) PartnerEarning::where('partner_id', $p->id)->sum(DB::raw(PartnerEarning::minorMoneySql('net')));
        $this->assertEqualsWithDelta(8.75, $sum, 0.000001);

        $this->assertSame(['price_minor' => 999, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('apps', ['price' => '9.99'], 'USD'));
        $this->assertSame(['currency' => 'KWD', 'gross_minor' => 1500, 'net_minor' => 1050],
            LegacyMoneyColumns::upgradeRow('partner_earnings', ['currency' => 'KWD', 'gross' => '1.50', 'net' => '1.05'], 'USD'));
        $this->assertSame(['amount_minor' => 1250, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('seller_withdrawals', ['amount' => '12.50'], 'USD'));
        $this->assertSame(['usage_price' => '0.0125'], LegacyMoneyColumns::upgradeRow('app_usage_records', ['usage_price' => '0.0125'], 'USD'));
        $this->assertSame(['price_paid_minor' => 4050, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('theme_purchases', ['price_paid' => '40.50'], 'USD'));
        $this->assertSame(['price_minor' => 0, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('themes', ['price' => '0.00'], 'USD'));
    }
}
