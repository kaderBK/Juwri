<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Regression guard: every modern theme's display-currency switcher is LIVE — its
 * own assets/store_templates/<theme>/js/script.js reprices the menu on switch
 * (money(base) * S.curRate, or vivid's fmt()/.vd-amt handler). A 2026-08 change
 * wrongly assumed only `aurora` repriced and hid the switcher on the others; this
 * pins that a multi-currency store renders the switcher (data-cur buttons) so the
 * control cannot be silently suppressed again.
 */
class CurrencySwitcherRendersTest extends TestCase
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
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function repriceThemeProvider(): array
    {
        $themes = ['aurora', 'crave', 'matcha', 'glow', 'vivid', 'dough', 'plate', 'lime', 'orbit', 'velora', 'crumb', 'soiree', 'ember'];

        return array_combine($themes, array_map(fn ($t) => [$t], $themes));
    }

    private function multiCurrencyStore(string $theme): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'allow_pro' => '1', 'allow_advanced' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234']);

        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($store->id, 'enable_multi_currency', 1);
        StoreOption::updateStoreOption($store->id, 'currency_rates_manual', "EUR = 0.90\nOMR = 0.385");

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return $store;
    }

    #[DataProvider('repriceThemeProvider')]
    public function test_multi_currency_store_renders_the_switcher(string $theme): void
    {
        $res = $this->get(route('publicView', $this->multiCurrencyStore($theme)->slug));

        $this->assertLessThan(500, $res->status(), "[$theme] storefront 500d");
        $this->assertStringContainsString('data-cur=', $res->getContent(), "[$theme] multi-currency switcher must render (it reprices client-side)");
    }

    /* ---- MC-34 shopper guardrail: the display-currency estimate notice ---- */

    public function test_shared_estimate_notice_ships_on_a_script_js_theme(): void
    {
        // crave reprices via its own script.js; the shared MC-34 notice is included
        // by the layout so a shopper sees "prices are estimates, charged in base".
        $res = $this->get(route('publicView', $this->multiCurrencyStore('crave')->slug));

        $this->assertStringContainsString('qc-cur-estimate', $res->getContent(), 'MC-34 notice partial must render on crave');
    }

    public function test_themes_with_their_own_notice_skip_the_shared_one(): void
    {
        // aurora and vivid ship their own currency disclaimer; the layout must NOT
        // also inject the shared one (double notice).
        foreach (['aurora', 'vivid'] as $theme) {
            $html = $this->get(route('publicView', $this->multiCurrencyStore($theme)->slug))->getContent();
            $this->assertStringNotContainsString('qc-cur-estimate', $html, "[$theme] must not double the estimate notice");
        }
    }
}
