<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Models\StoreView;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Storefront\Models\SlugRedirect;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09f characterisation of the storefront menu page
 * (User\StoreController::publicView): store lookup (slug, numeric id, renamed
 * slug), the owner/plan gate, QR scan counting and its limit, the scanned
 * table, the menu language, ordering flags, the menu list and the view data.
 */
class StorefrontPublicViewContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        unset($_COOKIE['Quick_user_lang_code']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        parent::tearDown();
    }

    private function store(array $settings = [], array $user = [], array $options = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => array_merge(['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999], $settings),
            'status' => 1,
        ]);
        $owner = User::factory()->create(array_merge(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()], $user));
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Store', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $options += ['store_template' => app(StorefrontThemeService::class)->defaultSlug()];
        foreach ($options as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        return $store;
    }

    public function test_unknown_store_is_404_and_a_renamed_slug_redirects_permanently(): void
    {
        $this->get('/no-such-store-'.Str::random(6))->assertNotFound();

        $store = $this->store();
        SlugRedirect::create(['old_slug' => 'old-name-'.$store->id, 'store_id' => $store->id]);
        $this->get('/old-name-'.$store->id)->assertStatus(301)->assertRedirect(route('publicView', $store->slug));
    }

    public function test_a_numeric_path_opens_the_store_with_that_id(): void
    {
        $store = $this->store();

        $this->get(route('publicView', $store->id))->assertOk()
            ->assertViewHas('store', fn ($p) => $p->id === $store->id);
    }

    public function test_an_inactive_owner_hides_the_store(): void
    {
        $store = $this->store([], ['status' => 0]);

        $this->get(route('publicView', $store->slug))->assertNotFound();
    }

    public function test_a_qr_scan_is_counted_then_redirects_to_the_clean_url(): void
    {
        $store = $this->store();
        $qr = app_xor_encrypt($store->slug, 'qr-scan');

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148',
            'Accept-Language' => 'fr-FR,fr;q=0.9',
        ])->get(route('publicView', $store->slug).'?qr-id='.urlencode($qr).'&table=T9&src=fly%3Cer')
            ->assertRedirect(route('publicView', $store->slug).'?table=T9&src=fly%3Cer');

        $view = StoreView::where('store_id', $store->id)->sole();
        $this->assertSame('Mobile', $view->device);
        $this->assertSame('fr', $view->lang);
        $this->assertSame('flyer', $view->source);
        $this->assertSame('127.0.0.1', $view->ip);
    }

    /** J52b review: at the monthly cap the scan is not counted and the diner still gets the menu (not sent home). */
    public function test_a_qr_scan_over_the_monthly_limit_serves_the_menu_without_counting(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $store = $this->store(['scan_limit' => 1]);
        StoreView::create(['store_id' => $store->id, 'date' => now(), 'ip' => '1.1.1.1']);
        $qr = app_xor_encrypt($store->slug, 'qr-scan');

        $this->get(route('publicView', $store->slug).'?qr-id='.urlencode($qr))->assertOk();
        $this->assertSame(1, StoreView::where('store_id', $store->id)->count());
    }

    public function test_the_scanned_table_needs_the_table_qr_entitlement(): void
    {
        $store = $this->store(['table_qr' => '1']);
        $table = StoreTable::create(['store_id' => $store->id, 'table_no' => 7, 'key' => 'k'.Str::random(8), 'seats' => 2]);

        $this->get(route('publicView', $store->slug).'?table='.$table->key)->assertOk()
            ->assertViewHas('table', fn ($t) => $t && $t->id === $table->id);

        $plain = $this->store();
        $other = StoreTable::create(['store_id' => $plain->id, 'table_no' => 8, 'key' => 'k'.Str::random(8), 'seats' => 2]);
        $this->get(route('publicView', $plain->slug).'?table='.$other->key)->assertOk()
            ->assertViewHas('table', null);
    }

    public function test_menu_language_defaults_then_follows_the_cookie(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        Language::updateOrCreate(['code' => 'xq'], ['name' => 'Off', 'direction' => 'ltr', 'active' => 0]);
        $store = $this->store([], [], ['menu_languages' => json_encode(['en', 'ar', 'xq']), 'default_language' => 'ar']);

        $this->get(route('publicView', $store->slug))->assertOk()
            ->assertViewHas('menu_languages', fn ($l) => $l->pluck('code')->sort()->values()->all() === ['ar', 'en'])
            ->assertViewHas('default_menu_language', fn ($l) => $l->code === 'ar');
        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('ar', session('locale'));

        $_COOKIE['Quick_user_lang_code'] = 'en';
        $this->get(route('publicView', $store->slug))->assertOk()
            ->assertViewHas('default_menu_language', fn ($l) => $l->code === 'en');
        $this->assertSame('en', session('locale'));
    }

    public function test_without_menu_languages_the_view_gets_empty_arrays(): void
    {
        $store = $this->store();

        $this->get(route('publicView', $store->slug))->assertOk()
            ->assertViewHas('menu_languages', [])
            ->assertViewHas('default_menu_language', []);
    }

    public function test_ordering_flags_follow_the_entitlement_and_store_options(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        config(['settings.admin_allow_online_payment' => 1]);
        $options = ['restaurant_on_table_order' => '1', 'restaurant_takeaway_order' => '0', 'restaurant_delivery_order' => '1', 'restaurant_online_payment' => '1'];

        $store = $this->store(['allow_ordering' => '1'], [], $options);
        $this->get(route('publicView', $store->slug))->assertOk()
            ->assertViewHas('allow_on_table', '1')->assertViewHas('allow_takeaway', '0')
            ->assertViewHas('allow_delivery', '1')->assertViewHas('allow_payment', '1')
            ->assertViewHas('allow_room_service', 0)
            ->assertViewHas('allow_order', true);

        config(['settings.admin_allow_online_payment' => 0]);
        $this->get(route('publicView', $store->slug))->assertViewHas('allow_payment', 0);

        $closed = $this->store([], [], $options);
        $response = $this->get(route('publicView', $closed->slug))->assertOk();
        foreach (['allow_order', 'allow_on_table', 'allow_takeaway', 'allow_delivery', 'allow_payment', 'allow_room_service'] as $flag) {
            $response->assertViewHas($flag, 0);
        }
        foreach (['allow_advanced', 'allow_pro', 'allow_on_free', 'allow_on_try', 'allow_on_main', 'allow_viewonly', 'allow_custom', 'allow_onlybio'] as $legacy) {
            $response->assertViewHas($legacy, 0);
        }
    }

    public function test_menu_lists_active_items_by_position_with_active_extras(): void
    {
        $store = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        $second = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Second', 'price' => 5, 'active' => '1', 'position' => 2]);
        $first = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'First', 'price' => 4, 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Hidden', 'price' => 3, 'active' => '0', 'position' => 0]);
        MenuExtra::create(['menu_id' => $first->id, 'title' => 'Cheese', 'price' => 1, 'active' => 1]);
        MenuExtra::create(['menu_id' => $first->id, 'title' => 'Off', 'price' => 1, 'active' => 0]);

        $this->get(route('publicView', $store->slug))->assertOk()
            ->assertViewHas('total_menus', function ($menus) use ($first, $second) {
                return array_keys($menus) === [$first->id, $second->id]
                    && $menus[$first->id]->extras->pluck('title')->all() === ['Cheese'];
            });
    }

    public function test_the_store_currency_is_applied_and_the_view_data_is_complete(): void
    {
        $store = $this->store([], [], ['currency_sign' => '€', 'currency_code' => '']);

        $response = $this->get(route('publicView', $store->slug))->assertOk()
            ->assertViewHas('theme', app(StorefrontThemeService::class)->defaultSlug())
            ->assertViewHas('plan', fn ($p) => $p && (int) $p->status === 1)
            ->assertViewHas('scans_today', 0)
            ->assertViewHas('allergies')
            ->assertViewHas('menuBanners')
            ->assertViewHas('branches')
            ->assertViewHas('activeBranch', null)
            ->assertViewHas('postOptions', fn ($o) => $o->store_template === app(StorefrontThemeService::class)->defaultSlug());
        $this->assertSame('€', config('settings.currency_sign'));
        $this->assertSame('USD', config('settings.currency_code'));
        $this->assertNotNull($response->getOriginalContent());
    }
}
