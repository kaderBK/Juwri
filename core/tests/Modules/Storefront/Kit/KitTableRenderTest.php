<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/** P3-05: the `table` kit theme renders a real store page. */
class KitTableRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_the_store_page_renders_with_the_table_theme(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $store = Store::where('slug', 'crave-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'table');

        $res = $this->get('/crave-demo');
        $res->assertOk();
        $html = $res->getContent();
        file_put_contents(sys_get_temp_dir().'/table-home.html', $html);
        $this->assertStringContainsString('data-theme-slug="table"', $html);
        $this->assertStringContainsString('c-catalog', $html);
    }

    /** the controls the theme offers change the page (no dead switch): image shape, desktop cart, calories, allergens */
    public function test_the_customizer_controls_change_the_page(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $store = Store::where('slug', 'crave-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'table');

        $home = $this->get('/crave-demo')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ratio=', $home, 'the default shape is the design\'s own: no rule');
        $this->assertStringNotContainsString('data-desktop-cart=', $home);

        $item = (new ViewModelScenario($store, 'table'))->cart()[0]['item'];
        $sheet = $this->get('/crave-demo?fragment=item&item='.$item)->assertOk()->getContent();
        $this->assertStringContainsString('c-fact', $sheet, 'calories and allergens show by default');

        ThemeSetting::create(['store_id' => $store->id, 'template' => 'table', 'settings' => [
            'layout.image_ratio' => '16:9', 'layout.desktop_cart' => 'drawer', 'menu.show_calories' => false, 'menu.show_allergens' => false,
        ]]);
        request()->attributes->remove('__theme_setting_memo');

        $home = $this->get('/crave-demo')->assertOk()->getContent();
        $this->assertStringContainsString('data-ratio="16:9"', $home);
        $this->assertStringContainsString('data-desktop-cart="drawer"', $home);
        $sheet = $this->get('/crave-demo?fragment=item&item='.$item)->assertOk()->getContent();
        $this->assertStringNotContainsString('c-fact--allergen', $sheet);
        $this->assertStringNotContainsString(' kcal', $sheet, 'calories hidden by the switch');
    }
}
