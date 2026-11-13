<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F1: the shared checkout-OTP modal is injected into every theme via the shared
 * layout, but ONLY when the store turned the gate on (gateEnabled). This closes
 * the checkout dead-end on the ~30 legacy themes whose bundles ignore the
 * server's `otp_required` rejection. Verified on old-2-theme (legacy).
 */
class CheckoutOtpPartialTest extends TestCase
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

    private function makeLegacyStore(bool $gateOn): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234567', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'old-2-theme');
        if ($gateOn) {
            StoreOption::updateStoreOption($store->id, 'require_phone_verification', '1');
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return $store;
    }

    /** Gate ON: the shared OTP modal + verify wiring render on a legacy theme. */
    public function test_otp_modal_renders_on_legacy_theme_when_gate_enabled(): void
    {
        $r = $this->makeLegacyStore(true);

        // @json() escapes slashes, so the route URLs render as checkout\/otp\/send.
        $this->get(route('publicView', $r->slug))->assertOk()->assertSeeHtml('mbOtpBackdrop')->assertSeeHtml('mbOtpVerify')->assertSeeHtml('checkout\/otp\/send')->assertSeeHtml('checkout\/otp\/verify');       // VERIFY_URL wired
    }

    /** Gate OFF (default): zero OTP markup — no change for the ~3,391 legacy stores. */
    public function test_otp_modal_absent_when_gate_disabled(): void
    {
        $r = $this->makeLegacyStore(false);

        $this->get(route('publicView', $r->slug))->assertOk()->assertDontSeeHtml('mbOtpBackdrop');
    }
}
