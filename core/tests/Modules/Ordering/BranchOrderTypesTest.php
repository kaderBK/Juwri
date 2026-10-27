<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D6 — per-branch order-type overrides: the inherit-vs-override resolver, the
 * settings round-trip, and that an all-inherit map stores null (pre-override
 * behaviour preserved). Storefront wiring is a one-line StoreController touch-point
 * (lane C fence) documented in the ledger; the resolver here is its authority.
 */
class BranchOrderTypesTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function storeFixture(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── resolver matrix ────────────────────────────────────────────────────────

    public function test_null_column_inherits_store_flags(): void
    {
        $branch = new Branch(['order_types' => null]);

        $this->assertNull($branch->orderTypeOverride('takeaway'));
        $this->assertTrue($branch->orderTypeAllowed('takeaway', true));   // inherits ON
        $this->assertFalse($branch->orderTypeAllowed('takeaway', false)); // inherits OFF
    }

    public function test_override_wins_over_store_flag(): void
    {
        $branch = new Branch(['order_types' => ['takeaway' => false, 'delivery' => true]]);

        // Store has takeaway ON, but the branch forces it OFF.
        $this->assertFalse($branch->orderTypeAllowed('takeaway', true));
        // Store has delivery OFF, but the branch forces it ON.
        $this->assertTrue($branch->orderTypeAllowed('delivery', false));
        // on-table absent → inherit.
        $this->assertNull($branch->orderTypeOverride('on-table'));
        $this->assertTrue($branch->orderTypeAllowed('on-table', true));
    }

    public function test_effective_order_types_merges_the_whole_set(): void
    {
        $branch = new Branch(['order_types' => ['on-table' => false]]);
        $store = ['on-table' => true, 'takeaway' => true, 'delivery' => false];

        $this->assertSame(
            ['on-table' => false, 'takeaway' => true, 'delivery' => false],
            $branch->effectiveOrderTypes($store)
        );
    }

    // ── settings round-trip ────────────────────────────────────────────────────

    public function test_settings_save_persists_overrides_and_drops_inherit(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $branch = Branch::where('store_id', $r->id)->firstOrFail();

        $this->actingAs($owner)->put(route('branches.update', $branch->id), [
            'name' => $branch->name,
            'order_types' => ['on-table' => '', 'takeaway' => '0', 'delivery' => '1'],
        ])->assertRedirect();

        $stored = $branch->fresh()->order_types;
        // on-table inherited → dropped; takeaway off; delivery on.
        $this->assertArrayNotHasKey('on-table', $stored);
        $this->assertFalse($stored['takeaway']);
        $this->assertTrue($stored['delivery']);
    }

    public function test_all_inherit_stores_null(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $branch = Branch::where('store_id', $r->id)->firstOrFail();
        $branch->update(['order_types' => ['takeaway' => false]]); // start with an override

        $this->actingAs($owner)->put(route('branches.update', $branch->id), [
            'name' => $branch->name,
            'order_types' => ['on-table' => '', 'takeaway' => '', 'delivery' => ''],
        ])->assertRedirect();

        $this->assertNull($branch->fresh()->order_types); // fully inherited again
    }

    public function test_settings_screen_renders_the_toggles(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)
            ->get(route('branches.index', ['store_id' => $r->id]))
            ->assertOk()
            ->assertSee('Order types at this branch');
    }
}
