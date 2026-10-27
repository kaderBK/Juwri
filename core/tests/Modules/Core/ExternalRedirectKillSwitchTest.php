<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ExternalRedirect;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A5 — the global kill-switch (superadmin Option `external_redirect_global`,
 * read as config('settings.external_redirect_global', true)) is resolver step 1,
 * and the shared ActivityLog audit write. The A2/A3 hooks already READ the
 * switch; this pins the OFF branch and the audit-row shape.
 */
class ExternalRedirectKillSwitchTest extends TestCase
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

    public function test_kill_switch_on_by_default_redirects_an_enabled_store(): void
    {
        // Control: with the switch at its default (on), the enabled store 302s.
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);

        $this->get(route('publicView', $store->slug))->assertStatus(302);
    }

    public function test_kill_switch_off_renders_menu_despite_an_enabled_active_store(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);

        // Superadmin flipped the global switch off — every store's redirect is
        // suppressed platform-wide even though this one is enabled and active.
        config(['settings.external_redirect_global' => '0']);

        $response = $this->get(route('publicView', $store->slug));

        $this->assertNotSame(302, $response->status());
        $this->assertLessThan(400, $response->status());
    }

    public function test_log_change_writes_set_and_clear_activity_rows(): void
    {
        [$owner, $store] = $this->makeStore();

        $this->actingAs($owner);
        ExternalRedirect::logChange($store, 'external_redirect_url', '', 'https://legacy.example.com/x');
        ExternalRedirect::logChange($store, 'external_redirect_enabled', '1', '0');

        $set = ActivityLog::where('action', 'external_redirect.set')->first();
        $this->assertNotNull($set);
        $this->assertSame($owner->id, (int) $set->actor_user_id);
        $this->assertSame($store->id, (int) $set->store_id);
        $this->assertSame('external_redirect_url', $set->meta['key']);
        $this->assertSame('', $set->meta['old']);
        $this->assertSame('https://legacy.example.com/x', $set->meta['new']);

        $clear = ActivityLog::where('action', 'external_redirect.clear')->first();
        $this->assertNotNull($clear);
        $this->assertSame('external_redirect_enabled', $clear->meta['key']);
        $this->assertSame('1', $clear->meta['old']);
        $this->assertSame('0', $clear->meta['new']);
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

        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return [$owner, $store];
    }

    private function enableRedirect(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/{slug}');
    }
}
