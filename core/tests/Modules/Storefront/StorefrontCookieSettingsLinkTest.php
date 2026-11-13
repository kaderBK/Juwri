<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

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
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-23 follow-up: a visitor can change the cookie choice on EVERY storefront theme. The modern templates carry
 * "Cookie settings" in the shared info footer; every other theme (legacy templates on the shared layout and the
 * themes with their own layout) gets _partials/cookie-settings-link, which also brings the banner it re-opens.
 * Discovered off disk, so a new theme is covered the day it lands.
 */
class StorefrontCookieSettingsLinkTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** @return array<string, array{0: string}> */
    public static function themeProvider(): array
    {
        $themes = [];
        foreach (glob(dirname(__DIR__, 3).'/resources/views/store_templates/*/index.blade.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            $themes[$slug] = [$slug];
        }
        ksort($themes);

        return $themes;
    }

    #[DataProvider('themeProvider')]
    public function test_every_theme_lets_the_visitor_reopen_the_cookie_settings(string $theme): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $html = (string) $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('~<button type="button" class="[^"]*" data-cookie-settings>'.preg_quote(e(__('site.cookie.settings')), '~').'</button>~', $html),
            "[{$theme}] needs exactly one Cookie settings link");
        $this->assertSame(1, substr_count($html, 'id="siteCookieConsent"'), "[{$theme}] needs the banner the link re-opens, once");
        $this->assertSame(KeptTemplates::isModern($theme) ? 0 : 1, substr_count($html, 'data-cookie-link'),
            "[{$theme}] modern themes use the info footer, the others the shared link partial");
    }
}
