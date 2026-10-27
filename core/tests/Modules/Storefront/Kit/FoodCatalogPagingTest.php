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
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Services\Storefront\FoodCatalogPaging;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P4-16: a very large food catalogue draws its first cards and loads the rest in place; a normal one is unchanged. */
class FoodCatalogPagingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seed(ThemeShowcaseSeeder::class);
        $this->store = Store::where('slug', 'crave-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($this->store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $this->store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($this->store->id, 'store_template', 'table');
    }

    /** the cards of the catalogue section (a page) or all cards (a fragment); featured / offers rails do not count */
    private function cards(string $html): int
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xp = new \DOMXPath($doc);
        $inCatalog = $xp->query("//*[@data-section='catalog']//li[@data-item]")->length;

        return $inCatalog > 0 ? $inCatalog : $xp->query('//li[@data-item]')->length;
    }

    private function total(): int
    {
        return Menu::query()->where('store_id', $this->store->id)->where('active', 1)->count();
    }

    public function test_a_normal_catalogue_is_drawn_in_full(): void
    {
        $html = $this->get('/crave-demo')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-kit-cat-more', $html);
        $this->assertGreaterThan(12, $this->cards($html));
    }

    public function test_a_large_catalogue_draws_one_page_and_each_category_can_load_the_rest(): void
    {
        $full = $this->cards($this->get('/crave-demo')->assertOk()->getContent());
        config(['perf.storefront_home_page_threshold' => 10, 'perf.storefront_home_page_items' => 12]);

        $html = $this->get('/crave-demo')->assertOk()->getContent();
        $this->assertSame(12, $this->cards($html), 'the first page: 12 cards');
        $this->assertLessThan($full, $this->cards($html));
        $this->assertStringContainsString('data-kit-cat-more', $html);
        $this->assertStringContainsString('data-kit-cat-load', $html);
        $this->assertStringContainsString('<ul class="', $html);
        // every category keeps its heading and anchor (the category bar still works)
        $this->assertSame(substr_count($html, 'class="c-cat__title"'), substr_count($html, 'data-kit-cat-list'));

        // the fragments return every remaining card exactly once
        preg_match_all('/data-kit-cat-more data-category="([^"]+)" data-offset="(\d+)" data-total="(\d+)"/', $html, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        $loaded = $this->cards($html);
        foreach ($m as [, $cat, $offset, $total]) {
            $next = (int) $offset;
            while ($next < (int) $total) {
                $res = $this->get('/crave-demo?fragment=cat&category='.$cat.'&offset='.$next)->assertOk();
                $res->assertHeader('X-Robots-Tag', 'noindex');
                $frag = $res->getContent();
                $n = $this->cards($frag);
                $this->assertGreaterThan(0, $n);
                $loaded += $n;
                $this->assertSame(1, preg_match('/data-next-offset="(\d*)" data-loaded="(\d+)"/', $frag, $mm));
                $next = $mm[1] === '' ? (int) $total : (int) $mm[1];
            }
        }
        $this->assertSame($full, $loaded, 'first page + all fragments = the whole catalogue');
    }

    public function test_the_no_script_link_draws_that_category_in_full(): void
    {
        config(['perf.storefront_home_page_threshold' => 10, 'perf.storefront_home_page_items' => 12]);
        $html = $this->get('/crave-demo')->assertOk()->getContent();
        preg_match('/data-kit-cat-more data-category="([^"]+)" data-offset="(\d+)" data-total="(\d+)"/', $html, $m);
        $this->assertStringContainsString('href="?category='.$m[1].'#cat-'.$m[1].'"', $html);

        $expanded = $this->get('/crave-demo?category='.$m[1])->assertOk()->getContent();
        $this->assertGreaterThan($this->cards($html), $this->cards($expanded));
        $this->assertStringNotContainsString('data-category="'.$m[1].'"', $expanded, 'that category has no Load more left');
    }

    public function test_a_category_that_does_not_exist_answers_an_empty_page_not_an_error(): void
    {
        config(['perf.storefront_home_page_threshold' => 10, 'perf.storefront_home_page_items' => 12]);
        $res = $this->get('/crave-demo?fragment=cat&category=nope&offset=0')->assertOk();
        $this->assertSame(0, $this->cards($res->getContent()));
    }

    public function test_the_plan_only_applies_above_the_threshold(): void
    {
        $catalog = ['categories' => [['id' => 'a', 'item_ids' => range(1, 5)], ['id' => 'b', 'item_ids' => range(6, 9)]]];
        config(['perf.storefront_home_page_threshold' => 9, 'perf.storefront_home_page_items' => 12]);
        $this->assertNull(FoodCatalogPaging::plan($catalog));
        config(['perf.storefront_home_page_threshold' => 8]);
        $this->assertSame(['a' => 5, 'b' => 4], FoodCatalogPaging::plan($catalog)['shown'], 'a 12-card page holds all 9');
    }
}
