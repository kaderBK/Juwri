<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
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
 * R5 — "today / live" ops strip: today's sales & orders, avg prep time, and
 * live (open) kitchen tickets. Always today/now, independent of the period.
 */
class OwnerDashboardLiveStripTest extends TestCase
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

    public function test_live_strip_counts_open_tickets_and_avg_prep(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        $row = fn (array $o) => array_merge([
            'store_id' => $store->id, 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1',
            'created_at' => Carbon::now(), 'preparing_at' => null, 'ready_at' => null, 'completed_at' => null,
            // P6-296: revenue-counted (paid) — keeps the realistic
            // "preparing = paid" shape so today's order count keeps the three
            // orders that match the live strip's "open in the kitchen" set.
            'is_paid' => 1,
        ], $o);
        Order::insert([
            // Open ticket with prep timestamps 10 min apart.
            $row(['status' => 'preparing', 'preparing_at' => Carbon::now()->subMinutes(10), 'ready_at' => Carbon::now()]),
            // Open ticket, no prep times.
            $row(['status' => 'preparing']),
            // Completed today — a today order but NOT a live ticket.
            $row(['status' => 'completed', 'completed_at' => Carbon::now()]),
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('dashboard-livestrip');
        $response->assertSeeHtml(___('Live tickets'));
        $response->assertSeeHtml(___('Avg prep time'));

        $this->assertSame(3, $response->viewData('todayOrderCount'), 'all 3 created today, all paid');
        $this->assertSame(2, $response->viewData('liveTickets'), 'two open, completed excluded');
        $this->assertSame(10, $response->viewData('avgPrepMin'), 'avg prep from the one timed order');
    }

    /** "Today" on a single-store dashboard is the store's calendar day, not the platform's. */
    public function test_live_strip_today_window_uses_store_timezone(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');

        $appTz = config('app.timezone') ?: 'UTC';
        // "Now" is midday in New York on Aug 9.
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00', 'America/New_York'));

        $row = fn (Carbon $at) => [
            'store_id' => $store->id, 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1',
            'status' => 'completed', 'completed_at' => $at,
            'created_at' => $at->copy()->setTimezone($appTz),
        ];
        Order::insert([
            $row(Carbon::parse('2026-08-09 01:00:00', 'America/New_York')), // NY today
            $row(Carbon::parse('2026-08-08 23:00:00', 'America/New_York')), // NY yesterday
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        // Only the NY-today order is in scope; the NY-yesterday one is excluded even
        // though the two straddle the platform-timezone midnight differently.
        $this->assertSame(1, $response->viewData('todayOrderCount'));

        Carbon::setTestNow();
    }

    public function test_live_strip_handles_no_orders(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame(0, $response->viewData('todayOrderCount'));
        $this->assertSame(0, $response->viewData('liveTickets'));
        $this->assertNull($response->viewData('avgPrepMin'));
    }
}
