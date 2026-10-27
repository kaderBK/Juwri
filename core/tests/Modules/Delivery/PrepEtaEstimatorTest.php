<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Juwri\Modules\Ordering\Services\Orders\EtaRange;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track C — EtaEstimator unit behavior (C1.1).
 *
 * Deterministic: time frozen via Carbon::setTestNow, orders seeded with fixed
 * timestamps (never now()-relative flakiness). "PrepEta" naming keeps these
 * clearly apart from the Egyptian Tax Authority Eta* fiscal tests.
 */
class PrepEtaEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN = '2026-07-16 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse(self::FROZEN));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /**
     * Seed one completed history order: started $startAgoMin minutes before the
     * frozen now, prepped for $prepMin minutes, with $lines order lines.
     */
    private function historyOrder(Store $r, Menu $menu, int $startAgoMin, float $prepMin, int $lines = 1): Order
    {
        $preparing = Carbon::parse(self::FROZEN)->subMinutes($startAgoMin);
        $order = Order::create([
            'store_id' => $r->id,
            'status' => 'completed',
            'type' => 'takeaway',
            'customer_name' => 'H',
            'track_token' => Str::random(32),
            'created_at' => $preparing->copy()->subMinutes(2),
            'preparing_at' => $preparing,
            'ready_at' => $preparing->copy()->addSeconds((int) round($prepMin * 60)),
            'completed_at' => $preparing->copy()->addSeconds((int) round($prepMin * 60) + 60),
        ]);
        for ($i = 0; $i < $lines; $i++) {
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);
        }

        return $order;
    }

    /** Seed a currently-preparing (live load) ticket. */
    private function liveOrder(Store $r, Menu $menu): Order
    {
        $order = Order::create([
            'store_id' => $r->id,
            'status' => 'preparing',
            'type' => 'takeaway',
            'customer_name' => 'L',
            'track_token' => Str::random(32),
            'created_at' => now()->subMinutes(5),
            'preparing_at' => now()->subMinutes(4),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return $order;
    }

    /**
     * Non-overlapping 20-minute history (median concurrency 1, per-item p50
     * clamps to 5): each order starts 60 min after the previous one ended.
     */
    private function seedFlatHistory(Store $r, Menu $menu, int $count, float $prepMin = 20.0): void
    {
        for ($i = 0; $i < $count; $i++) {
            // spread over the last ~20 days, well inside the 30-day window
            $this->historyOrder($r, $menu, 120 + $i * 60, $prepMin);
        }
    }

    // ------------------------------------------------------------------ tests

    public function test_abstains_below_sample_floor_and_engages_at_it(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();

        $this->seedFlatHistory($r, $menu, EtaEstimator::MIN_SAMPLES - 1);
        $this->assertNull(EtaEstimator::estimate($r), '19 samples must abstain');

        Cache::flush(); // drop the cached under-sampled aggregate
        $this->historyOrder($r, $menu, 60, 20.0);
        $eta = EtaEstimator::estimate($r);
        $this->assertInstanceOf(EtaRange::class, $eta, '20 samples must estimate');
        $this->assertSame(EtaEstimator::MIN_SAMPLES, $eta->samples);
        $this->assertSame('heuristic', $eta->source);
    }

    public function test_idle_kitchen_clamps_load_multiplier_low(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 20, 20.0);

        // No live preparing tickets → ratio 0 → clamped to 0.8 → 16 min → 15–20.
        $eta = EtaEstimator::estimate($r);
        $this->assertSame(15, $eta->p25);
        $this->assertSame(20, $eta->p75);
        $this->assertSame('15–20', $eta->label());
        $this->assertSame('15-20', $eta->toApiString());
    }

    public function test_neutral_load_gives_flat_range_widened_to_five(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 20, 20.0);
        $this->liveOrder($r, $menu); // 1 live vs median concurrency 1 → ×1.0

        $eta = EtaEstimator::estimate($r);
        // flat 20-min history → 20..20, rounding widens the empty range
        $this->assertSame(20, $eta->p25);
        $this->assertSame(25, $eta->p75);
        $this->assertSame(20, $eta->p50);
    }

    public function test_busy_kitchen_clamps_load_multiplier_high(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 20, 20.0);
        for ($i = 0; $i < 10; $i++) {
            $this->liveOrder($r, $menu); // 10 live vs median 1 → clamp ×2.0
        }

        $eta = EtaEstimator::estimate($r);
        $this->assertSame(40, $eta->p25); // 20 × 2.0
        $this->assertSame(45, $eta->p75); // widened flat range
    }

    public function test_item_count_factor_is_additive_and_capped(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 20, 20.0); // median items 1, per-item p50 clamps to 5

        // idle kitchen → base 16; 3 lines → +2 extra lines × 5 min = +10 → 26 → 25–30
        $eta = EtaEstimator::estimate($r, 3);
        $this->assertSame(25, $eta->p25);
        $this->assertSame(30, $eta->p75);

        // cap: 40 lines → extra = min(15, 39×5) = 15 → 31 → 30–35
        $eta = EtaEstimator::estimate($r, 40);
        $this->assertSame(30, $eta->p25);
        $this->assertSame(35, $eta->p75);

        // at/below the median line count → no factor (same as base)
        $eta = EtaEstimator::estimate($r, 1);
        $this->assertSame(15, $eta->p25);
        $this->assertSame(20, $eta->p75);
    }

    public function test_percentiles_reflect_spread_history(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        // 21 samples: 10, 11, ..., 30 minutes → p25 = 15, p50 = 20, p75 = 25
        for ($i = 0; $i <= 20; $i++) {
            $this->historyOrder($r, $menu, 120 + $i * 60, 10.0 + $i);
        }
        $this->liveOrder($r, $menu); // neutral ×1.0

        $eta = EtaEstimator::estimate($r);
        $this->assertSame(15, $eta->p25);
        $this->assertSame(20, $eta->p50);
        $this->assertSame(25, $eta->p75);
        $this->assertSame(21, $eta->samples);
    }

    public function test_outliers_and_old_orders_are_excluded(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 19, 20.0);

        // 20th candidate is a stuck ticket (> 240 min) → excluded → abstain
        $this->historyOrder($r, $menu, 40_000, 500.0);
        // and one outside the 30-day window (> 43200 min ago) → excluded
        $this->historyOrder($r, $menu, 50_000, 20.0);

        $this->assertNull(EtaEstimator::estimate($r));
    }

    public function test_static_fallback_parses_validates_and_swaps(): void
    {
        ['r' => $r] = $this->storeFixture(); // no history at all

        $this->assertNull(EtaEstimator::estimate($r), 'no history + no option → null');

        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '25-35');
        $eta = EtaEstimator::estimate($r);
        $this->assertSame(25, $eta->p25);
        $this->assertSame(35, $eta->p75);
        $this->assertSame(30, $eta->p50);
        $this->assertSame('static', $eta->source);
        $this->assertSame(0, $eta->samples);

        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '35-25'); // reversed → swapped
        $eta = EtaEstimator::estimate($r);
        $this->assertSame(25, $eta->p25);
        $this->assertSame(35, $eta->p75);

        foreach (['banana', '5-', '-5', '0-0', '1000-2000', ''] as $bad) {
            StoreOption::updateStoreOption($r->id, 'prep_eta_static', $bad);
            $this->assertNull(EtaEstimator::estimate($r), "'$bad' must not parse");
        }
    }

    public function test_heuristic_wins_over_static_when_both_available(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 20, 20.0);
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '90-99');

        $eta = EtaEstimator::estimate($r);
        $this->assertSame('heuristic', $eta->source);
        $this->assertSame(15, $eta->p25);
    }

    public function test_for_order_hides_after_ready_completed_cancelled(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '20-30');

        $order = Order::create([
            'store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway',
            'customer_name' => 'T', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        $this->assertNotNull(EtaEstimator::forOrder($order), 'pending shows eta');

        $order->status = 'preparing';
        $order->preparing_at = now();
        $order->save();
        $this->assertNotNull(EtaEstimator::forOrder($order->fresh()), 'preparing shows eta');

        $order->status = 'ready';
        $order->ready_at = now();
        $order->save();
        $this->assertNull(EtaEstimator::forOrder($order->fresh()), 'ready hides eta');

        $order->status = 'completed';
        $order->completed_at = now();
        $order->save();
        $this->assertNull(EtaEstimator::forOrder($order->fresh()), 'completed hides eta');

        $order2 = Order::create([
            'store_id' => $r->id, 'status' => 'cancelled', 'type' => 'takeaway',
            'customer_name' => 'T', 'track_token' => Str::random(32), 'created_at' => now(),
            'cancelled_at' => now(),
        ]);
        $this->assertNull(EtaEstimator::forOrder($order2), 'cancelled hides eta');
    }

    public function test_stats_are_cached_for_five_minutes(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu, 20, 20.0);

        $first = EtaEstimator::estimate($r);
        $this->assertSame(20, $first->samples);

        // new history arriving within the TTL is invisible (cached aggregate) …
        $this->historyOrder($r, $menu, 60, 60.0);
        $this->assertSame(20, EtaEstimator::estimate($r)->samples);

        // … and visible again after the cache is dropped
        EtaEstimator::forget($r->id);
        $this->assertSame(21, EtaEstimator::estimate($r)->samples);
    }

    public function test_median_concurrency_sweep_and_rounding_helpers(): void
    {
        // three fully-overlapping tickets → depths at start 1,2,3 → median 2
        $this->assertSame(2.0, EtaEstimator::medianConcurrency([[0, 600], [0, 600], [0, 600]]));
        // back-to-back (end == next start) does not overlap
        $this->assertSame(1.0, EtaEstimator::medianConcurrency([[0, 600], [600, 1200]]));
        $this->assertSame(1.0, EtaEstimator::medianConcurrency([]));

        $this->assertSame([15, 20], EtaEstimator::roundBounds(16.0, 16.0));
        $this->assertSame([5, 10], EtaEstimator::roundBounds(3.0, 4.0));
        $this->assertSame([20, 25], EtaEstimator::roundBounds(20.0, 20.0));
        $this->assertSame([20, 30], EtaEstimator::roundBounds(22.4, 28.1));

        $this->assertSame(25.0, EtaEstimator::percentile([10, 20, 30, 40], 0.5));
        $this->assertSame(0.0, EtaEstimator::percentile([], 0.5));
    }

    public function test_range_dto_payload_shape(): void
    {
        $range = new EtaRange(20, 24, 30, 25, 'heuristic');
        $arr = $range->toArray();

        $this->assertSame(20, $arr['p25']);
        $this->assertSame(30, $arr['p75']);
        $this->assertSame('20–30', $arr['text']);
        $this->assertStringContainsString('20–30', $arr['line']);
        $this->assertSame('heuristic', $arr['source']);
    }
}
