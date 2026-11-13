<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Analytics\Services\BehaviorAnalyticsService;
use Tests\TestCase;

class BehaviorAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        View::share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
    }

    private function mkPost(): Store
    {
        $o = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $t = 'R'.Str::random(5);

        return Store::create(['user_id' => $o->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function pv(int $pid, ?string $lang, ?int $dwell, ?string $exit): void
    {
        StoreView::create(['store_id' => $pid, 'ip' => '1.1.1.1', 'date' => now(), 'lang' => $lang, 'dwell_ms' => $dwell, 'exit_section' => $exit]);
    }

    public function test_language_split_dwell_and_exits(): void
    {
        $a = $this->mkPost();
        $b = $this->mkPost();
        $this->pv($a->id, 'ar', 10000, 'drinks');
        $this->pv($a->id, 'ar', 20000, 'desserts');
        $this->pv($a->id, 'en', 30000, 'drinks');
        $this->pv($b->id, 'fr', 5000, 'mains');
        $s = BehaviorAnalyticsService::summary([$a->id]);
        $this->assertSame(2, (int) $s['language_split']['ar']);
        $this->assertSame(1, (int) $s['language_split']['en']);
        $this->assertSame(20.0, $s['avg_time_on_menu_sec']);
        $this->assertSame(2, (int) $s['exit_sections']['drinks']);
    }

    public function test_heatmap_buckets_by_day_and_hour(): void
    {
        $a = $this->mkPost();
        StoreView::create(['store_id' => $a->id, 'ip' => '1', 'date' => '2026-07-01 14:00:00']);
        StoreView::create(['store_id' => $a->id, 'ip' => '1', 'date' => '2026-07-01 14:30:00']);
        $g = BehaviorAnalyticsService::heatmap([$a->id], '2026-06-01', '2026-07-31');
        $dow = (int) Carbon::parse('2026-07-01')->dayOfWeek;
        $this->assertSame(2, $g[$dow][14]);
    }

    public function test_source_split(): void
    {
        $a = $this->mkPost();
        StoreView::create(['store_id' => $a->id, 'ip' => '1', 'date' => now(), 'source' => 'qr']);
        StoreView::create(['store_id' => $a->id, 'ip' => '1', 'date' => now(), 'source' => 'qr']);
        StoreView::create(['store_id' => $a->id, 'ip' => '1', 'date' => now(), 'source' => 'link']);
        $s = BehaviorAnalyticsService::sourceSplit([$a->id]);
        $this->assertSame(2, (int) $s['qr']);
        $this->assertSame(1, (int) $s['link']);
    }

    // D10: the Analytics page surfaces the behavior signals.
    public function test_analytics_page_renders_menu_behavior_cards(): void
    {
        $store = $this->mkPost();
        $owner = User::find($store->user_id);
        $this->pv($store->id, 'en', 42000, 'drinks'); // 42s dwell, exit at drinks
        StoreView::create(['store_id' => $store->id, 'ip' => '2', 'date' => now(), 'source' => 'qr']);

        $res = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('analytics.index'));

        $res->assertStatus(200);
        $res->assertViewHas('behavior');
        $res->assertViewHas('behaviorSources');
        $behavior = $res->viewData('behavior');
        $this->assertEqualsWithDelta(42.0, $behavior['avg_time_on_menu_sec'], 0.5);
        $res->assertSeeHtml(___('Menu behavior'));
    }
}
