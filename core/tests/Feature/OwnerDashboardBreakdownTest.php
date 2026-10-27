<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

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
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R4 — render the previously-discarded breakdowns (scans by hour / device /
 * language) as server-side cards driven by the selected window.
 */
class OwnerDashboardBreakdownTest extends TestCase
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
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwnerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function scan(int $storeId, string $device, string $lang, int $hour): void
    {
        StoreView::create([
            'store_id' => $storeId, 'ip' => '10.0.0.'.random_int(1, 254),
            'date' => Carbon::now()->startOfMonth()->addDays(2)->setTime($hour, 0),
            'device' => $device, 'lang' => $lang,
        ]);
    }

    public function test_breakdowns_render_with_correct_values(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        // 3 mobile, 2 desktop; languages en x4, ar x1; all at hour 9.
        $this->scan($store->id, 'Mobile', 'en', 9);
        $this->scan($store->id, 'Mobile', 'en', 9);
        $this->scan($store->id, 'Mobile', 'en', 9);
        $this->scan($store->id, 'Desktop', 'en', 9);
        $this->scan($store->id, 'Desktop', 'ar', 9);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('breakdown-hours');
        $response->assertSeeHtml(___('By device'));
        $response->assertSeeHtml(___('Top languages'));

        $devices = $response->viewData('scansByDevice');
        $this->assertSame(3, $devices['Mobile']);
        $this->assertSame(2, $devices['Desktop']);
        $this->assertSame(0, $devices['Tablet']);

        $this->assertSame(5, array_sum($response->viewData('scansByHour')));
        $this->assertSame(5, $response->viewData('scansByHour')[9]);

        $langs = collect($response->viewData('topLanguages'));
        $this->assertSame('en', $langs->first()->lang);
        $this->assertSame(4, (int) $langs->firstWhere('lang', 'en')->count);
        $this->assertSame(1, (int) $langs->firstWhere('lang', 'ar')->count);
    }

    public function test_breakdowns_show_empty_states_with_no_scans(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('No device data'));
        $response->assertSeeHtml(___('No language data'));
    }
}
