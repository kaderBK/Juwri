<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

class DashboardContextDefaultTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dashboard_session_requests_are_serialized(): void
    {
        $this->assertTrue(config('session.block'));
    }

    public function test_saved_default_store_and_branch_apply_on_fresh_session(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $branch = Branch::create(['store_id' => $r2->id, 'name' => 'Downtown', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);

        UserOption::updateUserOption($owner->id, 'default_store_id', $r2->id);
        UserOption::updateUserOption($owner->id, 'default_branch_id', $branch->id);

        $this->actingAs($owner);

        $this->assertEquals($r2->id, dashboard_current_store()?->id);
        $this->assertEquals($branch->id, dashboard_current_branch()?->id);
    }

    public function test_switcher_saves_default_when_checkbox_ticked(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $branch = Branch::create(['store_id' => $r2->id, 'name' => 'Downtown', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);

        $this->actingAs($owner)->post(route('dashboard.context'), [
            'store_id' => $r2->id, 'branch_id' => $branch->id, 'save_default' => 1,
        ])->assertRedirect();

        $this->assertEquals((string) $r2->id, UserOption::getUserOption($owner->id, 'default_store_id'));
        $this->assertEquals((string) $branch->id, UserOption::getUserOption($owner->id, 'default_branch_id'));
    }

    public function test_default_store_is_newest_id_when_created_at_ties(): void
    {
        $owner = $this->makeUser();
        $stamp = now()->startOfSecond();
        $ids = [];
        foreach (range(1, 4) as $_) {
            $r = $this->makeRestaurant($owner);
            Store::whereKey($r->id)->update(['created_at' => $stamp]);
            $ids[] = $r->id;
        }

        $this->actingAs($owner);

        $this->assertSame(max($ids), dashboard_current_store()?->id);
        $this->assertSame(array_reverse($ids), dashboard_stores()->pluck('id')->all());
    }

    public function test_invalid_default_store_falls_back_to_first(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);

        UserOption::updateUserOption($owner->id, 'default_store_id', 999999); // deleted / not owned

        $this->actingAs($owner);

        $this->assertEquals($r1->id, dashboard_current_store()?->id);
    }

    public function test_default_branch_of_other_store_falls_back_to_all(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $otherBranch = Branch::create(['store_id' => $r2->id, 'name' => 'Elsewhere', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);

        UserOption::updateUserOption($owner->id, 'default_store_id', $r1->id);
        UserOption::updateUserOption($owner->id, 'default_branch_id', $otherBranch->id);

        $this->actingAs($owner);

        $this->assertEquals($r1->id, dashboard_current_store()?->id);
        $this->assertNull(dashboard_current_branch());
    }

    public function test_switch_context_honors_local_redirect_to(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);

        // Write-through selector posts the page path as redirect_to.
        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $r1->id])
            ->post(route('dashboard.context'), ['store_id' => $r2->id, 'redirect_to' => '/kds'])
            ->assertRedirect('/kds');

        // Session context updated → page + sidebar agree.
        $this->assertEquals($r2->id, dashboard_current_store()?->id);
    }

    public function test_switch_context_remaps_store_scoped_redirect_to_new_store(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $branch = Branch::create([
            'store_id' => $r2->id,
            'name' => 'Downtown',
            'active' => 1,
            'accepting_orders' => 1,
            'position' => 2,
        ]);

        $oldStorePath = route('stores.orders', $r1->id, false).'?status=new';
        $newStoreUrl = route('stores.orders', $r2->id).'?status=new';

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $r1->id])
            ->post(route('dashboard.context'), [
                'store_id' => $r2->id,
                'branch_id' => $branch->id,
                'redirect_to' => $oldStorePath,
            ])
            ->assertRedirect($newStoreUrl);

        $this->assertEquals($r2->id, dashboard_current_store()?->id);
        $this->assertEquals($branch->id, dashboard_current_branch()?->id);
    }

    public function test_switch_context_remaps_theme_customizer_post_query(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $r1->id])
            ->post(route('dashboard.context'), [
                'store_id' => $r2->id,
                'context_action' => 'store',
                'redirect_to' => route('theme.customizer.page', ['store' => $r1->id], false),
            ])
            ->assertRedirect(route('theme.customizer.page', ['store' => $r2->id]));

        $this->assertEquals($r2->id, dashboard_current_store()?->id);
    }

    public function test_switch_context_rewrites_stale_query_context(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $branch = Branch::create([
            'store_id' => $r2->id,
            'name' => 'Downtown',
            'active' => 1,
            'accepting_orders' => 1,
            'position' => 2,
        ]);

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $r1->id])
            ->post(route('dashboard.context'), [
                'store_id' => $r2->id,
                'branch_id' => $branch->id,
                'redirect_to' => '/kds?store_id='.$r1->id.'&branch_id=&status=new',
            ])
            ->assertRedirect('/kds?store_id='.$r2->id.'&status=new');

        $this->assertEquals($r2->id, dashboard_current_store()?->id);
        $this->assertEquals($branch->id, dashboard_current_branch()?->id);
    }

    public function test_switch_context_rejects_offsite_redirect_to(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);

        // Open-redirect guard: an absolute/protocol-relative target is ignored.
        $resp = $this->actingAs($owner)
            ->post(route('dashboard.context'), ['store_id' => $r1->id, 'redirect_to' => '//evil.example/x']);
        $resp->assertRedirect();
        $this->assertStringNotContainsString('evil.example', $resp->headers->get('Location') ?? '');
    }

    public function test_explicit_all_branches_beats_saved_default(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $branch = Branch::create(['store_id' => $r1->id, 'name' => 'Downtown', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);

        UserOption::updateUserOption($owner->id, 'default_store_id', $r1->id);
        UserOption::updateUserOption($owner->id, 'default_branch_id', $branch->id);

        // User explicitly switches to "all branches" for this session
        // (store already in session, as after any page load).
        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $r1->id])
            ->post(route('dashboard.context'), ['store_id' => $r1->id, 'branch_id' => ''])
            ->assertRedirect();

        $this->assertNull(dashboard_current_branch());
    }

    public function test_explicit_all_branches_beats_saved_default_before_store_is_in_session(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $branch = Branch::create([
            'store_id' => $store->id,
            'name' => 'Main Branch',
            'active' => 1,
            'accepting_orders' => 1,
            'position' => 1,
        ]);

        UserOption::updateUserOption($owner->id, 'default_store_id', $store->id);
        UserOption::updateUserOption($owner->id, 'default_branch_id', $branch->id);

        $this->actingAs($owner)
            ->post(route('dashboard.context'), [
                'store_id' => $store->id,
                'branch_id' => '',
                'context_action' => 'branch',
            ])
            ->assertRedirect();

        $this->assertSame($store->id, session('dashboard_store_id'));
        $this->assertTrue(session()->has('dashboard_branch_id'));
        $this->assertSame(0, session('dashboard_branch_id'));
        $this->assertNull(dashboard_current_branch());
    }
}
