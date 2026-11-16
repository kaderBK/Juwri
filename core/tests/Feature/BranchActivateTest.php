<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Language;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Ported from the old-platform BranchActivateTest (owner report 10-19):
 *
 * - switching an inactive branch back on did not save;
 * - the card switches (branches.toggle) flip active / accepting_orders and
 *   refuse another owner (cross-store 404);
 * - the storefront branch picker (branches.switcher) turns on and off and
 *   another owner cannot change it.
 */
class BranchActivateTest extends TestCase
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
        $this->shareLayoutGlobals();
        View::share('errors', new ViewErrorBag);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'branch_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function storeOf(User $owner): Store
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

    private function branch(Store $store, string $name, bool $active = false): Branch
    {
        return Branch::create([
            'store_id' => $store->id,
            'name' => $name,
            'active' => $active,
            'accepting_orders' => false,
            'position' => (int) Branch::where('store_id', $store->id)->max('position') + 1,
        ]);
    }

    public function test_owner_can_reactivate_an_inactive_branch(): void
    {
        $owner = $this->owner();
        $store = $this->storeOf($owner);
        $b = $this->branch($store, 'Downtown', active: false);

        $this->actingAs($owner)
            ->from(route('branches.index'))
            ->put(route('branches.update', $b->id), [
                'name' => $b->name, 'address' => $b->address, 'phone' => $b->phone, 'whatsapp' => $b->whatsapp,
                'timezone' => '', 'closed_message' => '', 'active' => '1', 'accepting_orders' => '1',
                'order_types' => ['on-table' => '', 'takeaway' => '', 'delivery' => ''],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertTrue((bool) $b->fresh()->active);
    }

    public function test_card_switches_toggle_active_and_accepting_orders(): void
    {
        $owner = $this->owner();
        $store = $this->storeOf($owner);
        $b = $this->branch($store, 'Downtown');

        $html = $this->actingAs($owner)
            ->get(route('branches.index', ['store_id' => $store->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(route('branches.toggle', $b->id), $html);

        $this->actingAs($owner)
            ->put(route('branches.toggle', $b->id), ['field' => 'active', 'value' => '1'])
            ->assertRedirect();
        $this->actingAs($owner)
            ->put(route('branches.toggle', $b->id), ['field' => 'accepting_orders', 'value' => '1'])
            ->assertRedirect();
        $this->assertTrue((bool) $b->fresh()->active);
        $this->assertTrue((bool) $b->fresh()->accepting_orders);

        // A field the switch does not own is rejected, and another owner's
        // toggle is a 404 (the branch is looked up scoped to the acting owner).
        $this->actingAs($owner)
            ->put(route('branches.toggle', $b->id), ['field' => 'name', 'value' => '1'])
            ->assertSessionHasErrors('field');
        $other = $this->owner();
        $this->actingAs($other)
            ->put(route('branches.toggle', $b->id), ['field' => 'active', 'value' => '0'])
            ->assertNotFound();
        $this->assertTrue((bool) $b->fresh()->active, 'another owner must not toggle it');
    }

    public function test_owner_can_turn_the_storefront_branch_picker_on_and_off(): void
    {
        $owner = $this->owner();
        $store = $this->storeOf($owner);
        StoreOption::updateStoreOption($store->id, 'allow_multi_branch_switch', 0);

        $html = $this->actingAs($owner)
            ->get(route('branches.index', ['store_id' => $store->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(route('branches.switcher'), $html);

        $this->actingAs($owner)
            ->put(route('branches.switcher'), ['store_id' => $store->id, 'allow_multi_branch_switch' => '1'])
            ->assertRedirect();
        StoreOption::flushOptionMemo();
        $this->assertSame(1, (int) store_options($store->id, 'allow_multi_branch_switch'));

        // Another owner's write is a 404 and leaves the flag alone.
        $other = $this->owner();
        $this->actingAs($other)
            ->put(route('branches.switcher'), ['store_id' => $store->id, 'allow_multi_branch_switch' => '0'])
            ->assertNotFound();
        StoreOption::flushOptionMemo();
        $this->assertSame(1, (int) store_options($store->id, 'allow_multi_branch_switch'), 'another owner must not change it');
    }
}
