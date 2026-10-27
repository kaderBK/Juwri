<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B2 — the owner dashboard banner ("your public menu redirects to …") and its
 * one-click Disable. Banner render is asserted through the real dashboard route;
 * disable through the real POST endpoint (clears the option + audits).
 */
class ExternalRedirectDashboardBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    public function test_banner_shows_when_menu_redirect_is_active(): void
    {
        [$owner, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/menu');

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('https://legacy.example.com/menu');
        $response->assertSeeHtml(route('stores.externalRedirect.disable', $store->id));
    }

    public function test_banner_absent_when_redirect_off(): void
    {
        [$owner, $store] = $this->makeStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertDontSeeHtml(route('stores.externalRedirect.disable', $store->id));
    }

    public function test_disable_post_clears_option_and_audits(): void
    {
        [$owner, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/menu');

        $response = $this->actingAs($owner)->post(route('stores.externalRedirect.disable', $store->id));

        $response->assertRedirect();
        $this->assertSame('0', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
        // URL is kept for easy re-enable.
        $this->assertSame('https://legacy.example.com/menu', (string) StoreOption::getStoreOption($store->id, 'external_redirect_url'));

        $log = ActivityLog::where('action', 'external_redirect.clear')
            ->where('store_id', $store->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($owner->id, (int) $log->actor_user_id);
    }

    public function test_disable_forbidden_for_a_non_owner(): void
    {
        [, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/menu');
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($intruder)
            ->post(route('stores.externalRedirect.disable', $store->id))
            ->assertNotFound(); // BL-043: the owner tenant scope hides another account's store (was 403 from the controller check)

        // Untouched.
        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $store];
    }
}
