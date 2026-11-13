<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\Entitlements;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-036 / U1b: the owner Branches screen.
 *
 * - one-tap "Active" / "Accepting orders" switches on every branch card
 *   (branches.toggle), including the plan's branch cap: switching a branch ON
 *   over the cap is refused exactly like the branch dialog's save;
 * - the storefront branch picker switch (branches.switcher), owner only;
 * - a new store starts with the picker ON.
 */
class BranchTogglesTest extends TestCase
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

        // Match the neighbouring Branch tests: keep the real pipeline but skip
        // the install-gate, CSRF and staff-permission middlewares.
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

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(): array
    {
        $owner = $this->owner();

        return [$owner, $this->storeOf($owner)];
    }

    private function addBranch(Store $store, string $name, bool $active = false): Branch
    {
        return Branch::create([
            'store_id' => $store->id,
            'name' => $name,
            'active' => $active,
            'accepting_orders' => false,
            'position' => (int) Branch::where('store_id', $store->id)->max('position') + 1,
        ]);
    }

    /**
     * The plan's branch cap in Community: the edition sells no plans, so the
     * entitlement source answers every count key with "unlimited". Swap it for
     * one that bounds `branch_limit` to 1 — the same cap an edition plan with
     * `branch_limit => 1` would give (the edition package owns the plan chain;
     * Community only owns the source behind it).
     */
    private function capBranchesAtOne(): void
    {
        $source = new class implements EntitlementSource
        {
            public function trace(User $user, string $key): ?array
            {
                if ($key === 'branch_limit') {
                    return ['lane' => 'test', 'value' => '1'];
                }

                return str_ends_with($key, '_limit') ? null : ['lane' => 'test', 'value' => '1'];
            }

            public function addonTotal(int $userId, string $key): int
            {
                return 0;
            }

            public function booleanAddonKeys(User $user): ?array
            {
                return [];
            }

            public function bundleGrants(User $user): array
            {
                return [];
            }

            public function purchases(User $user, string $key): array
            {
                return [];
            }

            public function flush(): void {}
        };

        $this->app->instance(EntitlementSource::class, $source);
        Entitlements::flushBundleMemo();
    }

    /** @return array<string, mixed> */
    private function storePayload(): array
    {
        return [
            'color' => '#123456',
            'title' => 'Olive Table',
            'sub_title' => 'Fresh food',
            'description' => 'A restaurant',
            'address' => '1 Main St',
            'timing' => '9-5',
            'phone' => '555',
            'store_template' => app(StorefrontThemeService::class)->defaultSlug(),
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
        ];
    }

    // ── the branches page ──────────────────────────────────────────────────────

    public function test_the_branches_page_shows_the_card_switches_and_the_picker_card(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $branch = $this->addBranch($store, 'Downtown');

        $html = $this->actingAs($owner)
            ->get(route('branches.index', ['store_id' => $store->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('branches.toggle', $branch->id), $html, 'the card posts to the toggle route');
        $this->assertStringContainsString('Customers choose a branch', $html, 'the storefront picker switch is on the page');
        $this->assertStringContainsString(route('branches.switcher'), $html, 'the picker switch posts to the switcher route');
    }

    // ── the card switches ──────────────────────────────────────────────────────

    public function test_the_card_switches_flip_active_and_accepting_orders(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $branch = $this->addBranch($store, 'Downtown');

        $this->actingAs($owner)
            ->put(route('branches.toggle', $branch->id), ['field' => 'active', 'value' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success', ___('Branch updated.'));
        $this->assertTrue((bool) $branch->fresh()->active);

        $this->actingAs($owner)
            ->put(route('branches.toggle', $branch->id), ['field' => 'accepting_orders', 'value' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success', ___('Branch updated.'));
        $this->assertTrue((bool) $branch->fresh()->accepting_orders);

        // And back off again.
        $this->actingAs($owner)
            ->put(route('branches.toggle', $branch->id), ['field' => 'active', 'value' => '0'])
            ->assertRedirect();
        $this->assertFalse((bool) $branch->fresh()->active);
    }

    public function test_the_card_switch_rejects_a_field_it_does_not_own(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $branch = $this->addBranch($store, 'Downtown');

        $this->actingAs($owner)
            ->put(route('branches.toggle', $branch->id), ['field' => 'name', 'value' => 'Hijacked'])
            ->assertSessionHasErrors('field');

        $this->assertSame('Downtown', $branch->fresh()->name);
    }

    public function test_another_owner_cannot_toggle_a_branch(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $branch = $this->addBranch($store, 'Downtown');
        $other = $this->owner();

        $this->actingAs($other)
            ->put(route('branches.toggle', $branch->id), ['field' => 'active', 'value' => '0'])
            ->assertNotFound();

        $this->assertFalse((bool) $branch->fresh()->active);
    }

    public function test_activating_over_the_plan_branch_limit_is_refused(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->capBranchesAtOne();
        $branch = $this->addBranch($store, 'Downtown');

        // The store's own "Main" branch is the one active branch the plan allows.
        $this->assertSame(1, Branch::where('store_id', $store->id)->where('active', true)->count());

        $this->actingAs($owner)
            ->from(route('branches.index', ['store_id' => $store->id]))
            ->put(route('branches.toggle', $branch->id), ['field' => 'active', 'value' => '1'])
            ->assertSessionHas('error');

        $this->assertFalse((bool) $branch->fresh()->active, 'the branch stays off at the plan limit');
    }

    // ── the storefront branch picker ──────────────────────────────────────────

    public function test_the_owner_can_turn_the_storefront_branch_picker_off_and_on(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->actingAs($owner)
            ->put(route('branches.switcher'), ['store_id' => $store->id, 'allow_multi_branch_switch' => '0'])
            ->assertRedirect()
            ->assertSessionHas('success', ___('Saved.'));
        StoreOption::flushOptionMemo();
        $this->assertFalse(StoreSettings::bool((int) $store->id, 'allow_multi_branch_switch', true));

        $this->actingAs($owner)
            ->put(route('branches.switcher'), ['store_id' => $store->id, 'allow_multi_branch_switch' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success', ___('Saved.'));
        StoreOption::flushOptionMemo();
        $this->assertTrue(StoreSettings::bool((int) $store->id, 'allow_multi_branch_switch', true));
    }

    public function test_another_owner_cannot_change_the_storefront_branch_picker(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $other = $this->owner();

        $this->actingAs($other)
            ->put(route('branches.switcher'), ['store_id' => $store->id, 'allow_multi_branch_switch' => '0'])
            ->assertNotFound();

        StoreOption::flushOptionMemo();
        $this->assertTrue(StoreSettings::bool((int) $store->id, 'allow_multi_branch_switch', true), 'another owner must not change it');
    }

    public function test_a_new_store_starts_with_the_storefront_branch_picker_on(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post(route('stores.store'), $this->storePayload())
            ->assertRedirect(route('stores.index'));

        $store = Store::where('user_id', $owner->id)->sole();
        StoreOption::flushOptionMemo();
        $this->assertTrue(StoreSettings::bool((int) $store->id, 'allow_multi_branch_switch', false), 'a new store shows the branch picker');
    }
}
