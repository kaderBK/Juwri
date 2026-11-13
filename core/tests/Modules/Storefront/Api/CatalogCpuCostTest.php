<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P6-49: the catalogue call and the home page of a 2,000-item store cost ~450 ms / ~370 ms of CPU because every request
 * loaded and decorated every item. The card cache is now keyed by the store's menu version plus a signature of what is
 * time-dependent (decorationSignature), so a hit loads no items. These tests pin the three things that must not change:
 * the answer, the moment it changes (a time window or schedule opening or closing), and that an edit shows at once.
 */
class CatalogCpuCostTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        config(['settings.admin_allow_menu_schedules' => true, 'perf.storefront_catalog_cache_ttl' => 60]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-10-13 18:40:00', 'UTC')); // a Tuesday
        $this->fx = new ApiFixtures;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MenuScheduleService::flush();
        parent::tearDown();
    }

    private const HEADERS = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001', 'Accept-Language' => 'en'];

    /** @return array<string, mixed> */
    private function catalog(string $query = ''): array
    {
        return $this->getJson('/api/v1/storefront/catalog'.$query, self::HEADERS)->assertOk()->json();
    }

    /** the same answer with the card cache switched off (what every request used to do) */
    private function uncachedCatalog(string $query = ''): array
    {
        config(['perf.storefront_catalog_cache_ttl' => 0]);
        try {
            return $this->catalog($query);
        } finally {
            config(['perf.storefront_catalog_cache_ttl' => 60]);
        }
    }

    public function test_a_cache_hit_loads_no_item_models_and_answers_what_a_miss_answers(): void
    {
        $this->fx->saffron();
        $miss = $this->catalog();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $hit = $this->catalog();
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame($miss, $hit);
        $this->assertSame($miss, $this->uncachedCatalog(), 'a cached answer is the answer of a fresh build');
        foreach ($sql as $q) {
            if (str_contains($q, 'from `items`') || str_contains($q, 'from "items"')) {
                $this->assertStringContainsString('distinct', $q, 'a hit may only read the distinct item windows, never the item rows: '.$q);
            }
        }
    }

    public function test_an_edit_shows_at_once(): void
    {
        $this->fx->saffron();
        $before = $this->catalog();
        $id = ApiFixtures::TAWOOK;

        $item = Menu::query()->where('public_id', $id)->firstOrFail();
        $item->price = 55;
        $item->save();
        $after = $this->catalog();

        $this->assertNotSame($before['catalog']['items'][$id]['price'], $after['catalog']['items'][$id]['price']);
        $this->assertSame($after, $this->uncachedCatalog());

        $category = MenuCategory::query()->where('store_id', $item->store_id)->where('name', 'Grills')->firstOrFail();
        $category->update(['name' => 'Charcoal grills']);
        $this->assertSame($this->uncachedCatalog(), $this->catalog());
    }

    public function test_a_window_opening_and_closing_changes_the_cached_answer_exactly_when_a_fresh_build_does(): void
    {
        $this->fx->saffron();
        $id = ApiFixtures::TAWOOK;
        Menu::query()->where('public_id', $id)->firstOrFail()->update(['available_from' => '19:00:00', 'available_to' => '20:00:00']);

        $seen = [];
        foreach (['18:40', '18:59', '19:00', '19:30', '20:00', '20:01', '23:00'] as $hm) {
            $this->travelTo(Carbon::parse('2026-10-13 '.$hm.':00', 'UTC'));
            $cached = $this->catalog();
            $this->assertSame($this->uncachedCatalog(), $cached, 'at '.$hm);
            $seen[$hm] = isset($cached['catalog']['items'][$id]);
        }
        $this->assertSame(['18:40' => false, '18:59' => false, '19:00' => true, '19:30' => true, '20:00' => true, '20:01' => false, '23:00' => false], $seen);
    }

    public function test_a_schedule_going_live_changes_the_cached_answer(): void
    {
        $store = $this->fx->saffron();
        $id = ApiFixtures::TAWOOK;
        PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'Happy', 'label' => 'Happy hour', 'scope' => 'store', 'adjustment_type' => 'percent',
            'adjustment_value' => 50, 'active' => true, 'starts_at' => '19:00:00', 'ends_at' => '20:00:00',
        ]);

        $prices = [];
        foreach (['18:40', '19:10', '19:11', '20:30'] as $hm) {
            $this->travelTo(Carbon::parse('2026-10-13 '.$hm.':00', 'UTC'));
            $cached = $this->catalog();
            $this->assertSame($this->uncachedCatalog(), $cached, 'at '.$hm);
            $prices[$hm] = $cached['catalog']['items'][$id]['price']['minor'] ?? $cached['catalog']['items'][$id]['from_price']['minor'];
        }
        $this->assertSame($prices['18:40'], $prices['20:30']);
        $this->assertSame($prices['19:10'], $prices['19:11']);
        $this->assertLessThan($prices['18:40'], $prices['19:10']);
    }

    /** the signature may only repeat when the decoration repeats: same signature => same price, label and availability, item by item */
    public function test_equal_signatures_decorate_every_item_the_same_way(): void
    {
        $store = $this->fx->saffron();
        $sid = (int) $store->id;
        $parent = MenuCategory::create(['store_id' => $sid, 'name' => 'Parent']);
        $late = MenuCategory::create(['store_id' => $sid, 'name' => 'Late', 'available_from' => '21:00:00', 'available_to' => '22:30:00']);
        $mk = fn (string $n, array $a) => Menu::create(['store_id' => $sid, 'name' => $n, 'price' => 10, 'active' => '1', 'category_id' => $parent->id] + $a);
        $mk('window', ['available_from' => '11:00:00', 'available_to' => '14:00:00']);
        $mk('overnight', ['available_from' => '22:00:00', 'available_to' => '02:00:00']);
        $mk('days', ['available_days' => 'tue,wed']);
        $mk('delivery only', ['available_order_types' => 'delivery']);
        $mk('late cat', ['category_id' => $late->id]);
        $this->schedule($sid, ['starts_at' => '12:00:00', 'ends_at' => '13:30:00', 'adjustment_value' => 25]);
        $this->schedule($sid, ['scope' => 'category', 'days' => 'tue', 'adjustment_value' => 10], [['category', $parent->id]]);

        $bySignature = [];
        $states = [];
        foreach ([null, 'delivery', 'pickup'] as $orderType) {
            for ($minute = 0; $minute < 24 * 60; $minute += 5) {
                $this->travelTo(Carbon::parse('2026-10-13 00:00:00', 'UTC')->addMinutes($minute));
                MenuScheduleService::flush();
                $items = Menu::query()->where('store_id', $sid)->orderBy('id')->get();
                MenuScheduleService::decorate($items, $sid, $orderType);
                $state = $items->map(fn (Menu $m) => implode('|', [
                    $m->id, sprintf('%.6f', (float) $m->price), var_export($m->original_price, true), (string) $m->schedule_label,
                    var_export($m->available_now, true), (string) $m->available_from_label,
                ]))->implode("\n");
                $signature = MenuScheduleService::decorationSignature($sid, $orderType);
                $key = (string) $orderType.'#'.$signature;
                $this->assertSame($bySignature[$key] ?? $state, $state, 'two moments with one signature decorated differently ('.$orderType.' '.$minute.')');
                $bySignature[$key] = $state;
                $states[$orderType.'#'.md5($state)] = true;
            }
        }
        // the sweep crosses real boundaries, so the signature really moves (and never more than the states do, by the check above)
        $this->assertGreaterThan(6, count($bySignature));
        $this->assertGreaterThan(6, count($states));
    }

    public function test_the_time_window_fast_path_answers_as_before(): void
    {
        $at = Carbon::parse('2026-10-13 12:00:00', 'UTC'); // a Tuesday
        $this->assertTrue(Menu::windowCovers(null, null, null, $at));
        $this->assertTrue(Menu::windowCovers('', '', '', $at));
        $this->assertTrue(Menu::windowCovers(null, '11:00', null, $at), 'one end alone is not a window');
        $this->assertTrue(Menu::windowCovers('tue', null, null, $at));
        $this->assertFalse(Menu::windowCovers('mon,wed', null, null, $at));
        $this->assertTrue(Menu::windowCovers(null, '11:00', '13:00', $at));
        $this->assertFalse(Menu::windowCovers(null, '13:00', '14:00', $at));
        $this->assertTrue(Menu::windowCovers(null, '22:00', '02:00', $at->copy()->setTime(1, 30)));
    }

    public function test_the_cast_memo_follows_changes_and_is_not_serialized(): void
    {
        $menu = new Menu;
        $first = $menu->getCasts();
        $this->assertSame($first, $menu->getCasts());
        $this->assertArrayHasKey('price', $first);

        $menu->mergeCasts(['note_flag' => 'boolean']);
        $this->assertSame('boolean', $menu->getCasts()['note_flag'], 'a cast added later is seen');

        $this->assertStringNotContainsString('castsMerged', serialize($menu));
        $copy = unserialize(serialize($menu));
        $this->assertSame($menu->getCasts(), $copy->getCasts());
    }

    public function test_storefront_menus_read_restores_the_eager_loading_switch(): void
    {
        $this->fx->saffron();
        $before = Model::isAutomaticallyEagerLoadingRelationships();
        Model::automaticallyEagerLoadRelationships(true);
        try {
            $slug = \Juwri\Core\Models\Store::query()->value('slug');
            $this->get('/'.$slug)->assertOk();
            $this->assertTrue(Model::isAutomaticallyEagerLoadingRelationships(), 'the switch is back on after the page');
        } finally {
            Model::automaticallyEagerLoadRelationships($before);
        }
    }

    /** @param list<array{0: string, 1: int}> $targets */
    private function schedule(int $storeId, array $attrs, array $targets = []): PriceSchedule
    {
        $s = PriceSchedule::create(array_merge([
            'store_id' => $storeId, 'name' => 'S', 'label' => 'Deal', 'scope' => 'store',
            'adjustment_type' => 'percent', 'adjustment_value' => 10, 'active' => true,
        ], $attrs));
        foreach ($targets as [$type, $id]) {
            PriceScheduleTarget::create(['schedule_id' => $s->id, 'target_type' => $type, 'target_id' => $id]);
        }

        return $s;
    }
}
