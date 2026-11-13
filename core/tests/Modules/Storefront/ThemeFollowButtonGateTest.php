<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StoreFollow;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * The brand-row "Follow" button needs a customer account to do anything: the
 * theme click handler is
 *
 *     if (!hasToken()) { if (typeof openAuth === "function") openAuth(); return; }
 *
 * and on a `guest_only` store the auth sheet is never rendered, so `openAuth`
 * does not exist and the click returns silently — no fetch, no toast, no
 * feedback. Six of the seven theme-pack themes shipped that dead control;
 * `vivid` was the only one gating it correctly.
 *
 * Every theme now wraps the button in `@if($authMode !== 'guest_only' || $customer)`.
 * This test locks that in from both sides:
 *
 *  1. guest_only + no customer  -> the button is GONE (no dead control), and
 *  2. a non-guest auth mode     -> the button is BACK (the feature was gated,
 *                                  not deleted), and
 *  3. the informational parts of the brand row (follower count, "Official
 *     store" verified badge) are untouched in BOTH modes — they are not
 *     interactive and must never be gated.
 */
class ThemeFollowButtonGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * theme => [follow button id, follower-count element id]
     *
     * Ids verified against each theme's index.blade.php; each button id occurs
     * exactly once in its blade (in the button itself), so assertDontSee on it
     * is an unambiguous signal.
     */
    private const THEMES = [
        'crave' => ['cvFollowBtn',   'cvFollowerCount'],
        'glow' => ['cvFollowBtn',   'cvFollowerCount'],
        'matcha' => ['cvFollowBtn',   'cvFollowerCount'],
        'aurora' => ['au-follow-btn', 'au-follower-count'],
        'feast' => ['cvFollowBtn',   'cvFollowerCount'],
        'velvet' => ['cvFollowBtn',   'cvFollowerCount'],
        'vivid' => ['vd-follow-btn', 'vd-follower-count'],
    ];

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

    /**
     * A verified storefront on $theme. $authMode === null leaves the
     * customer_auth_mode option unset — the blade then falls back to '' (the
     * settings-form "Default", accounts AVAILABLE), so the auth sheet + follow
     * button render. To exercise the accounts-off path, pass 'guest_only'
     * explicitly (a legacy value the settings UI no longer offers).
     */
    private function storefront(string $theme, ?string $authMode = null): Store
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
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
            'is_verified' => 1, // renders the "Official store" badge in the brand row
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);
        if ($authMode !== null) {
            StoreOption::updateStoreOption($store->id, 'customer_auth_mode', $authMode);
        }

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        // active MUST be the string '1' — an int 1 is coerced to '0' and hides the menu.
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => '1']);

        // B1: the follower-count element renders only once the count clears the
        // display threshold (below it the brand row shows the value-copy CTA). Seed
        // enough follows that the count branch — asserted by assertBrandRowIntact — renders.
        foreach (range(1, follower_count_min()) as $i) {
            $c = Customer::create(['store_id' => $store->id, 'name' => 'F'.$i, 'phone' => '90000'.$i]);
            StoreFollow::create(['store_id' => $store->id, 'customer_id' => $c->id]);
        }

        return $store;
    }

    /** The non-interactive brand-row bits must render regardless of auth mode. */
    private function assertBrandRowIntact(TestResponse $res, string $countId): void
    {
        $res->assertSeeHtml($countId);        // follower count element
        $res->assertSeeHtml('Official store'); // verified badge
    }

    // ---- 1 + 3: guest_only (option unset) hides the dead button ------------

    private function assertHiddenForGuestOnly(string $theme): void
    {
        [$btnId, $countId] = self::THEMES[$theme];

        $store = $this->storefront($theme, 'guest_only'); // explicit accounts-off
        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertDontSeeHtml($btnId);
        $this->assertBrandRowIntact($res, $countId);
    }

    // ---- 0: DEFAULT (customer_auth_mode unset) SHOWS the button -----------
    // Regression guard for the reported "login button missing on the
    // storefront" bug: an unconfigured store must fall back to accounts-
    // available (''), not 'guest_only', so the account/login + follow UI is
    // present by default.

    private function assertShownByDefault(string $theme): void
    {
        [$btnId, $countId] = self::THEMES[$theme];

        $store = $this->storefront($theme); // customer_auth_mode unset -> '' (default, accounts on)
        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml($btnId);
        $this->assertBrandRowIntact($res, $countId);
    }

    public function test_crave_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('crave');
    }

    public function test_glow_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('glow');
    }

    public function test_matcha_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('matcha');
    }

    public function test_aurora_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('aurora');
    }

    public function test_feast_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('feast');
    }

    public function test_velvet_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('velvet');
    }

    public function test_vivid_shows_follow_button_by_default(): void
    {
        $this->assertShownByDefault('vivid');
    }

    public function test_crave_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('crave');
    }

    public function test_glow_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('glow');
    }

    public function test_matcha_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('matcha');
    }

    public function test_aurora_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('aurora');
    }

    public function test_feast_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('feast');
    }

    public function test_velvet_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('velvet');
    }

    public function test_vivid_hides_follow_button_on_guest_only_store(): void
    {
        $this->assertHiddenForGuestOnly('vivid');
    }

    // ---- 2 + 3: a non-guest auth mode brings the button back ---------------
    // This is the important half: it proves the fix hid a dead control rather
    // than deleting the follow feature outright.

    private function assertShownForOptionalAuth(string $theme): void
    {
        [$btnId, $countId] = self::THEMES[$theme];

        $store = $this->storefront($theme, 'optional');
        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml($btnId);
        $res->assertSeeHtml(___('Follow'));
        $this->assertBrandRowIntact($res, $countId);
    }

    public function test_crave_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('crave');
    }

    public function test_glow_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('glow');
    }

    public function test_matcha_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('matcha');
    }

    public function test_aurora_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('aurora');
    }

    public function test_feast_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('feast');
    }

    public function test_velvet_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('velvet');
    }

    public function test_vivid_shows_follow_button_when_auth_enabled(): void
    {
        $this->assertShownForOptionalAuth('vivid');
    }

    /**
     * The other branch of the gate: a logged-in customer keeps the button even
     * on a guest_only store, because the follow call can authenticate.
     */
    public function test_logged_in_customer_keeps_follow_button_on_guest_only_store(): void
    {
        $store = $this->storefront('crave', 'guest_only'); // explicit accounts-off
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);

        $res = $this->actingAs($customer, 'customer')->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('cvFollowBtn');
    }
}
