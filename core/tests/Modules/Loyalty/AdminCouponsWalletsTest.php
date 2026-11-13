<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\LedgerService;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coupons & Wallets P6-1: superadmin oversight overview across all wallet and
 * coupon tiers.
 */
class AdminCouponsWalletsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);

        // The admin-layout composers only register when APP_INSTALLED (unset in
        // tests) — share the layout deps directly so the Blade page can render.
        config(['settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'site_admin_logo' => 'logo.png', 'currency_sign' => '$', 'currency_pos' => 1])
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
        view()->share(['admin_languages' => collect(), 'notifications' => collect(), 'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0]);
    }

    private function fund(string $ownerType, int $ownerId, string $kind, float $amount): void
    {
        $acct = LedgerService::accountFor($ownerType, $ownerId, $kind);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -$amount, 'allow_negative' => true, 'ref' => "seed:{$kind}:{$ownerId}:clr"],
            ['account' => $acct, 'amount' => $amount, 'ref' => "seed:{$kind}:{$ownerId}:usr"],
        ]);
    }

    public function test_overview_reports_balances_and_coupon_stats(): void
    {
        $this->fund('reseller', 1, 'reseller', 100);
        $this->fund('customer', 2, 'customer_store_credit', 40);

        Coupon::create(['code' => 'P1', 'percentage' => 10, 'limit' => 9, 'used' => 3, 'coupon_type' => 'percentage', 'user_id' => null, 'issuer_scope' => 'platform', 'applies_to' => 'plan_checkout', 'expiry_at' => now()->addMonth()]);
        Coupon::create(['code' => 'O1', 'percentage' => 10, 'limit' => 9, 'used' => 5, 'coupon_type' => 'percentage', 'user_id' => 42, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth()]);
        Coupon::create(['code' => 'R1', 'percentage' => 10, 'limit' => 9, 'used' => 1, 'coupon_type' => 'percentage', 'reseller_id' => 1, 'issuer_scope' => 'reseller', 'applies_to' => 'plan_checkout', 'expiry_at' => now()->addMonth()]);

        $res = $this->actingAs($this->admin)->getJson(route('admin.couponsWallets.overview'))->assertOk();

        $res->assertJsonPath('wallets.reseller.balance', 100)
            ->assertJsonPath('wallets.customer_store_credit.balance', 40);
        // Platform clearing carries the negative counterparty of both credits.
        $res->assertJsonPath('wallets.platform.balance', -140);

        $scopes = collect($res->json('coupons'))->keyBy('issuer_scope');
        $this->assertSame(3, $scopes['platform']['total_used']);
        $this->assertSame(5, $scopes['owner']['total_used']);
        $this->assertSame(1, $scopes['reseller']['total_used']);
    }

    public function test_non_admin_blocked(): void
    {
        $stranger = User::factory()->create(['status' => 1, 'user_type' => 'user', 'email_verified_at' => now()]);
        $res = $this->actingAs($stranger)->get(route('admin.couponsWallets.overview'));
        $this->assertContains($res->status(), [301, 302, 403]);
    }

    // Coupons & Wallets P7-6b: a browser GET renders the overview Blade page
    // (JSON is preserved for API/wantsJson callers — proven above).
    public function test_overview_renders_html_page_for_browser(): void
    {
        $this->fund('reseller', 1, 'reseller', 100);
        $this->fund('customer', 2, 'customer_store_credit', 40);
        Coupon::create(['code' => 'O1', 'percentage' => 10, 'limit' => 9, 'used' => 5, 'coupon_type' => 'percentage', 'user_id' => 42, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth()]);

        $res = $this->actingAs($this->admin)->get(route('admin.couponsWallets.overview'))->assertOk();
        $res->assertSeeHtml(___('Wallet balances'));
        $res->assertSeeHtml(___('Coupon redemptions'));
        $res->assertSee('100.00'); // reseller balance rendered
    }

    /**
     * WI-C5: the superadmin overview surfaces today's denied claims and the
     * pre-fix over-redemption damage counter (used > limit). Both are read by the
     * two new tiles, so proving the JSON + the rendered labels proves the tiles.
     */
    public function test_overview_surfaces_claim_denied_and_over_redeemed(): void
    {
        // A historically over-redeemed coupon (used > limit) — the pre-fix damage.
        Coupon::create(['code' => 'OVER', 'percentage' => 10, 'limit' => 1, 'used' => 3, 'coupon_type' => 'percentage', 'user_id' => 7, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth()]);
        // A healthy one must NOT count toward over-redeemed.
        Coupon::create(['code' => 'OK', 'percentage' => 10, 'limit' => 5, 'used' => 2, 'coupon_type' => 'percentage', 'user_id' => 7, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth()]);
        PlatformMetrics::record('coupon.claim_denied', 'food_order');
        PlatformMetrics::record('coupon.claim_denied', 'wallet_promo');

        $json = $this->actingAs($this->admin)->getJson(route('admin.couponsWallets.overview'))->assertOk();
        $json->assertJsonPath('claimDeniedToday', 2)
            ->assertJsonPath('overRedeemed', 1);

        $html = $this->actingAs($this->admin)->get(route('admin.couponsWallets.overview'))->assertOk();
        $html->assertSeeHtml(___('Coupon claims declined today'));
        $html->assertSeeHtml(___('Over-redeemed coupons'));
    }

    /**
     * WI-M3: the superadmin overview surfaces the per-customer-gate health — today's
     * per-customer denials and the total rows in the redemption ledger. Both feed the
     * two M3 tiles, so proving the JSON + rendered labels proves the tiles.
     */
    public function test_overview_surfaces_per_customer_gate_health(): void
    {
        CouponRedemption::create(['coupon_id' => 1, 'customer_id' => 1, 'seq' => 1, 'created_at' => now()]);
        CouponRedemption::create(['coupon_id' => 1, 'customer_id' => 2, 'seq' => 1, 'created_at' => now()]);
        PlatformMetrics::record('coupon.per_customer_denied', 'food_order');

        $json = $this->actingAs($this->admin)->getJson(route('admin.couponsWallets.overview'))->assertOk();
        $json->assertJsonPath('perCustomerDeniedToday', 1)
            ->assertJsonPath('redemptionsTracked', 2);

        $html = $this->actingAs($this->admin)->get(route('admin.couponsWallets.overview'))->assertOk();
        $html->assertSeeHtml(___('Per-customer denials today'));
        $html->assertSeeHtml(___('Redemptions tracked'));
    }
}
