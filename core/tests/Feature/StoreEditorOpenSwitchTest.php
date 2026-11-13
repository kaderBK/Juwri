<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-01 (found by the fiscal journey): a store with no stored `open_close_store` is open (store_open_now), but the store
 * editor showed "Store open: No" for it — so saving the editor for anything else (the fiscal identity) closed the store
 * and the storefront refused every order. The select now shows the stored value, or "Yes" when nothing is stored.
 */
class StoreEditorOpenSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
    }

    private function store(): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'T', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');

        return [$owner, $store];
    }

    private function openSelect(string $html): string
    {
        $this->assertSame(1, preg_match('/<select[^>]*name="open_close_store"[^>]*>(.*?)<\/select>/s', $html, $m), 'open switch select missing');

        return $m[1];
    }

    public function test_a_store_with_no_stored_open_switch_shows_open(): void
    {
        [$owner, $store] = $this->store();
        $this->assertTrue(store_open_now($store), 'no stored switch = open');

        $select = $this->openSelect($this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="1"\s+selected/', $select, 'before: "No" was selected');
        $this->assertDoesNotMatchRegularExpression('/<option value="0"\s+selected/', $select);
    }

    public function test_a_closed_store_still_shows_closed(): void
    {
        [$owner, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'open_close_store', '0');

        $select = $this->openSelect($this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="0"\s+selected/', $select);
    }
}
