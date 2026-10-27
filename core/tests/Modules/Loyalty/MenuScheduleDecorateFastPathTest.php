<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\Storefront\StorefrontMenuCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-24b: decorate() precomputes the item-independent work once per store. It must give, item by item, exactly the
 * answer of isAvailable() + quote(); its query count must not grow with the item count; and every write that changes
 * a decorated card (schedule, schedule target, category window) must bust the storefront card cache, whose key also
 * moves with the store-clock minute.
 */
class MenuScheduleDecorateFastPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_menu_schedules' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MenuScheduleService::flush();
        parent::tearDown();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function schedule(Store $store, array $attrs, array $targets = []): PriceSchedule
    {
        $s = PriceSchedule::create(array_merge([
            'store_id' => $store->id, 'name' => 'S', 'label' => 'Deal', 'scope' => 'store',
            'adjustment_type' => 'percent', 'adjustment_value' => 10, 'active' => true,
        ], $attrs));
        foreach ($targets as [$type, $id]) {
            PriceScheduleTarget::create(['schedule_id' => $s->id, 'target_type' => $type, 'target_id' => $id]);
        }

        return $s;
    }

    /** @return array{0: Store, 1: list<int>} a store with nested categories, mixed-scope schedules and $n items */
    private function busyStore(int $n): array
    {
        $store = $this->store();
        $parent = MenuCategory::create(['store_id' => $store->id, 'name' => 'Parent']);
        $child = MenuCategory::create(['store_id' => $store->id, 'name' => 'Child', 'parent' => $parent->id]);
        $closed = MenuCategory::create(['store_id' => $store->id, 'name' => 'Late', 'available_from' => '23:00:00', 'available_to' => '23:30:00']);
        $cats = [$parent->id, $child->id, $closed->id, null];
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = Menu::create([
                'store_id' => $store->id, 'name' => 'Item '.$i, 'price' => [0, 9.99, 12.5, 3.333, 100][$i % 5],
                'active' => '1', 'category_id' => $cats[$i % 4],
            ])->id;
        }
        $this->schedule($store, ['adjustment_value' => 20, 'priority' => 1]);
        $this->schedule($store, ['scope' => 'category', 'adjustment_type' => 'amount', 'adjustment_value' => 2.5], [['category', $parent->id]]);
        $this->schedule($store, ['scope' => 'item', 'adjustment_value' => 50], [['item', $ids[1]], ['item', $ids[6]]]);
        $this->schedule($store, ['scope' => 'store', 'adjustment_value' => 90, 'starts_at' => '23:00:00', 'ends_at' => '23:30:00']);
        $this->schedule($store, ['scope' => 'store', 'adjustment_value' => 20, 'priority' => 5]); // tie with the first
        MenuScheduleService::flush();

        return [$store, $ids];
    }

    public function test_decorate_matches_is_available_and_quote_item_by_item(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        [$store] = $this->busyStore(20);

        foreach ([null, 'delivery', 'pickup'] as $orderType) {
            $items = Menu::where('store_id', $store->id)->orderBy('id')->get();
            MenuScheduleService::decorate($items, (int) $store->id, $orderType);
            $at = MenuScheduleService::nowFor((int) $store->id);

            foreach ($items as $decorated) {
                $fresh = Menu::find($decorated->id);
                $quote = MenuScheduleService::quote($fresh, (float) $fresh->price, $at, $orderType);
                $available = MenuScheduleService::isAvailable($fresh, $at, $orderType);

                $this->assertSame($available, $decorated->available_now, 'available '.$decorated->id);
                $this->assertSame($available ? null : MenuScheduleService::opensAt($fresh), $decorated->available_from_label);
                $this->assertSame($quote['label'], $decorated->schedule_label, 'label '.$decorated->id);
                $this->assertSame($quote['schedule'] ? $quote['base'] : null, $decorated->original_price);
                $this->assertSame((string) $quote['price'], (string) $decorated->price, 'price '.$decorated->id);
            }
        }
    }

    public function test_decorate_query_count_does_not_grow_with_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        $count = function (int $n): int {
            [$store] = $this->busyStore($n);
            $items = Menu::where('store_id', $store->id)->get();
            MenuScheduleService::flush();
            FeatureAccess::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            MenuScheduleService::decorate($items, (int) $store->id);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };

        $small = $count(10);
        $this->assertLessThanOrEqual($small, $count(200), 'decorate queries grew with the item count');
    }

    public function test_writes_that_change_cards_bust_the_storefront_cache(): void
    {
        $store = $this->store();
        $sid = (int) $store->id;
        $category = MenuCategory::create(['store_id' => $sid, 'name' => 'Mains']);

        $v = StorefrontMenuCache::version($sid);
        $schedule = $this->schedule($store, []);
        $this->assertNotSame($v, $v = StorefrontMenuCache::version($sid), 'schedule create');

        $schedule->update(['adjustment_value' => 30]);
        $this->assertNotSame($v, $v = StorefrontMenuCache::version($sid), 'schedule edit');

        PriceScheduleTarget::create(['schedule_id' => $schedule->id, 'target_type' => 'category', 'target_id' => $category->id]);
        $this->assertNotSame($v, $v = StorefrontMenuCache::version($sid), 'schedule target');

        $category->update(['available_from' => '07:00:00', 'available_to' => '11:00:00']);
        $this->assertNotSame($v, $v = StorefrontMenuCache::version($sid), 'category window');

        $schedule->delete();
        $this->assertNotSame($v, StorefrontMenuCache::version($sid), 'schedule delete');
    }

    public function test_card_cache_bucket_moves_with_the_store_minute(): void
    {
        $store = $this->store();
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:59:30', 'UTC'));
        $before = MenuScheduleService::cacheBucket((int) $store->id, 1);
        Carbon::setTestNow(Carbon::parse('2026-10-13 11:00:00', 'UTC'));
        $this->assertNotSame($before, MenuScheduleService::cacheBucket((int) $store->id, 1));
    }
}
