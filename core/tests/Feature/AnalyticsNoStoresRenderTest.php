<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-036: the owner analytics page must render for an owner with NO stores.
 *
 * The controller's no-stores branch renders the same view without a currency
 * (there is no store to read one from). The view's money closure bound
 * `$currencySymbol`/`$currencyDecimals` in `use()` — PHP raises "Undefined
 * variable" on an unset binding when the closure is CREATED, so the in-closure
 * `??` fallback could never run and the page 500'd. The currency is now
 * defaulted before the closure.
 *
 * Both paths are pinned: the empty owner still gets the page, and the
 * single-store owner keeps getting it (the defaulting must not change the
 * currency the store's own numbers are printed with).
 */
class AnalyticsNoStoresRenderTest extends TestCase
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

        // Keep the real pipeline but skip the install-gate, CSRF and
        // staff-permission middlewares that would 4xx unrelated to the render.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
        $this->shareLayoutGlobals();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999],
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function store(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An owner with no store at all still gets the analytics page. */
    public function test_owner_without_stores_gets_the_analytics_page(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('analytics.index'))
            ->assertStatus(200)
            ->assertSee('data-analytics-window', false)
            ->assertSee(___('No analytics yet'));
    }

    /** An owner with one store still gets the analytics page. */
    public function test_owner_with_a_store_still_gets_the_analytics_page(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $this->actingAs($owner)->get(route('analytics.index'))
            ->assertStatus(200)
            ->assertSee('data-analytics-window', false)
            ->assertSee(___('No analytics yet'));
    }
}
