<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P8 — RollupStorePageViews aggregates raw store_page_views into store_page_view_daily idempotently, and
 * prunes raw rows only when retention is enabled.
 */
class RollupPostViewsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): int
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ])->id;
    }

    private function seedRaw(int $storeId, string $date, string $device, ?string $lang, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            StoreView::create([
                'store_id' => $storeId, 'ip' => '1.2.3.4', 'device' => $device, 'lang' => $lang,
                'date' => $date.' 10:0'.($i % 6).':00',
            ]);
        }
    }

    public function test_rollup_is_idempotent(): void
    {
        $pid = $this->store();
        $day = now()->subDay()->toDateString();
        $this->seedRaw($pid, $day, 'Mobile', 'en', 3);
        $this->seedRaw($pid, $day, 'Desktop', 'ar', 2);

        $this->artisan('postviews:rollup', ['--date' => $day])->assertSuccessful();
        $this->artisan('postviews:rollup', ['--date' => $day])->assertSuccessful(); // twice

        $rows = DB::table('store_page_view_daily')->where('store_id', $pid)->where('date', $day)->get();
        $this->assertCount(2, $rows, 'one row per device/lang, not duplicated on re-run');
        $this->assertSame(3, (int) $rows->firstWhere('device', 'Mobile')->scans);
        $this->assertSame(2, (int) $rows->firstWhere('device', 'Desktop')->scans);
    }

    public function test_daily_totals_match_the_raw_counts(): void
    {
        $pid = $this->store();
        $day = now()->subDay()->toDateString();
        $this->seedRaw($pid, $day, 'Mobile', 'en', 5);

        $this->artisan('postviews:rollup', ['--all' => true])->assertSuccessful();

        $rawCount = StoreView::where('store_id', $pid)->whereRaw('DATE(date) = ?', [$day])->count();
        $dailyScans = (int) DB::table('store_page_view_daily')->where('store_id', $pid)->where('date', $day)->sum('scans');
        $this->assertSame($rawCount, $dailyScans);
    }

    public function test_prune_is_disabled_by_default(): void
    {
        $pid = $this->store();
        $old = now()->subDays(120)->toDateString();
        $this->seedRaw($pid, $old, 'Mobile', 'en', 4);

        // No retention configured → rollup runs but deletes nothing.
        config(['perf.post_view_retention' => 0]);
        $this->artisan('postviews:rollup', ['--all' => true])->assertSuccessful();

        $this->assertSame(4, StoreView::where('store_id', $pid)->count(), 'default retention (0) must not prune');
    }

    public function test_prune_removes_only_rows_past_the_cutoff(): void
    {
        $pid = $this->store();
        $old = now()->subDays(120)->toDateString();
        $recent = now()->subDays(2)->toDateString();
        $this->seedRaw($pid, $old, 'Mobile', 'en', 4);
        $this->seedRaw($pid, $recent, 'Mobile', 'en', 3);

        // Retention 90 days: the 120-day-old rows go, the 2-day-old rows stay.
        $this->artisan('postviews:rollup', ['--all' => true, '--days' => 90])->assertSuccessful();

        $this->assertSame(0, StoreView::where('date', '<', now()->subDays(90)->startOfDay())->count());
        $this->assertSame(3, StoreView::where('store_id', $pid)->count(), 'recent rows are retained');
        // The pruned day still exists in the daily rollup.
        $this->assertSame(4, (int) DB::table('store_page_view_daily')->where('date', $old)->sum('scans'));
    }
}
