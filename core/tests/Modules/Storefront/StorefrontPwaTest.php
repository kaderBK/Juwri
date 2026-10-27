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
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F14-1: the storefront PWA service worker is registered with a versioned URL
 * (?v=appinfo.version) so a deploy bump rotates the cache and activate() purges
 * the old one. Verified on orbit (keep-list).
 */
class StorefrontPwaTest extends TestCase
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

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $r = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($r->id, 'store_template', 'orbit');
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        return $r;
    }

    public function test_service_worker_registration_is_versioned(): void
    {
        $r = $this->store();

        $this->get(route('publicView', $r->slug))->assertOk()->assertSeeHtml('/sw-menu.js?v='.config('appinfo.version'));
    }

    public function test_store_worker_trim_never_evicts_the_offline_page_or_core_scripts(): void
    {
        $sw = (string) file_get_contents(dirname(base_path()).'/sw-store.js');

        $this->assertStringContainsString('new URL(k.url).pathname', $sw, 'trim inspects the request pathname');
        $this->assertStringContainsString('p !== OFFLINE_URL && CORE.indexOf(p) === -1', $sw, 'trim skips the offline page and the core scripts');
    }

    public function test_menu_worker_trim_never_evicts_the_offline_page(): void
    {
        $sw = (string) file_get_contents(dirname(base_path()).'/sw-menu.js');

        $this->assertStringContainsString('new URL(k.url).pathname !== OFFLINE_URL', $sw, 'trim skips the offline page');
    }
}
