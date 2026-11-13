<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

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
 * Machine journeys (2026-10-13): pizzaflow and pulse (then every other theme, P6-127) rendered <img src=""> (a store with no logo,
 * plus the detail-sheet image slot). An empty src makes browsers re-request the page itself.
 */
class EmptyImgSrcThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals(['site_logo' => '', 'facebook_link' => '', 'instagram_link' => '']);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        // every shipped theme (same discovery rule as StorefrontThemeService::discover)
        $out = [];
        foreach (glob(dirname(__DIR__, 3).'/resources/views/store_templates/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $theme = basename($dir);
            if (is_file(dirname(__DIR__, 4).'/assets/store_templates/'.$theme.'/theme-info.txt')) {
                $out[$theme] = [$theme];
            }
        }

        return $out;
    }

    #[DataProvider('themes')]
    public function test_store_without_a_logo_renders_no_img_with_an_empty_src(string $theme): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'No Logo Grill', 'sub_title' => '', 'description' => 'd',
            'main_image' => '', 'cover_image' => '', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club', 'price' => 42.00, 'image' => '', 'active' => '1']);

        $menuId = Menu::where('store_id', $store->id)->value('id');
        foreach (['/'.$store->slug, '/'.$store->slug.'?item='.$menuId] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(?:""|\'\')[^>]*>/i', $html, $m);
            $this->assertSame([], array_map(fn (string $tag): string => substr($tag, 0, 160), $m[0]), "{$theme} {$url}: <img> with an empty src");
        }
    }
}
