<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.10 wiring: the public storefront loads the commerce runtime
 * the THEME asks for (theme.json "commerce_runtime"), v1 for everyone else.
 */
class StudioRuntimeWiringTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/runtimewire';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        foreach (['v2' => ['commerce_runtime' => 'v2'], 'plain' => []] as $v => $extra) {
            $dir = storage_path(self::BASE.'/'.$v);
            File::ensureDirectoryExists($dir.'/templates');
            File::put($dir.'/theme.json', json_encode(['name' => 'Wire', 'slug' => 'runtimewire', 'version' => $v] + $extra));
            File::put($dir.'/templates/index.liquid', '<main>shop</main>{% commerce %}');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function storeOn(string $version): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:runtimewire@'.$version);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }

    public function test_storefront_loads_the_runtime_the_theme_asks_for(): void
    {
        $v2 = $this->get(route('publicView', $this->storeOn('v2')->slug))->assertOk()->getContent();
        $this->assertStringContainsString('assets/commerce-runtime/v2/commerce.js', $v2);

        $plain = $this->get(route('publicView', $this->storeOn('plain')->slug))->assertOk()->getContent();
        $this->assertStringContainsString('assets/commerce-runtime/v1/commerce.js', $plain);
        $this->assertStringNotContainsString('commerce-runtime/v2', $plain);
    }
}
