<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-30: per-branch Google place ID in the owner branch editor — save, per-store
 * uniqueness, access refusal through the real routes, and the server-side Google
 * merchant id / eligibility shown to the owner.
 *
 * The refusal tests exercise the real middleware stack (no withoutMiddleware) exactly
 * like the route's middleware list runs it; the app is declared installed so the
 * setup gate (`installed`) passes and the refusal comes from the auth/ownership rules.
 */
class BranchGoogleSettingsTest extends TestCase
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

        // Let the real `installed` middleware run (it would otherwise divert every
        // request to the setup page) without disabling any middleware.
        $this->app['config']->set('app.installed', true);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function firstBranch(Store $store): Branch
    {
        return Branch::where('store_id', $store->id)->first();
    }

    private function addBranch(Store $store, string $name): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => $name, 'active' => 1, 'timezone' => 'UTC',
            'position' => (int) Branch::where('store_id', $store->id)->max('position') + 1,
        ]);
    }

    private function editUrl(Store $store): string
    {
        return route('branches.index', ['store_id' => $store->id]);
    }

    /** (a) the owner saves a place id; a wiped field clears it back to null. */
    public function test_owner_can_set_and_clear_a_google_place_id(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $branch = $this->firstBranch($store);
        $place = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

        $this->actingAs($owner)
            ->from($this->editUrl($store))
            ->put(route('branches.update', $branch->id), ['name' => $branch->name, 'active' => '1', 'google_place_id' => $place])
            ->assertRedirect();
        $this->assertSame($place, $branch->fresh()->google_place_id);

        $this->from($this->editUrl($store))
            ->put(route('branches.update', $branch->id), ['name' => $branch->name, 'active' => '1', 'google_place_id' => ''])
            ->assertRedirect();
        $this->assertNull($branch->fresh()->google_place_id);
    }

    /** (b) another owner gets refused through the real routes. */
    public function test_another_owner_cannot_update_the_branch(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $branch = $this->firstBranch($store);
        $intruder = $this->makeOwner();

        $this->actingAs($intruder)
            ->put(route('branches.update', $branch->id), ['name' => 'x'])
            ->assertNotFound();
        $this->assertSame($branch->name, $branch->fresh()->name);
    }

    /** (b) a staff member gets refused (route unmapped for staff → 403) through a real login. */
    public function test_staff_cannot_update_the_branch(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $branch = $this->firstBranch($store);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Kitchen Staff',
            'email' => 'kitchen.google@example.test', 'password' => Hash::make('secret123'),
            'role' => 'kitchen', 'active' => true,
        ]);

        $this->from('/staff/login')
            ->post(route('staff.login.attempt'), ['email' => $staff->email, 'password' => 'secret123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($staff, 'staff');

        $this->put(route('branches.update', $branch->id), ['name' => 'x'])
            ->assertForbidden();
        $this->assertSame($branch->name, $branch->fresh()->name);
    }

    /** (c) a duplicate place id in the same store is a session error, DB unchanged. */
    public function test_duplicate_place_id_in_same_store_is_rejected(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $first = $this->firstBranch($store);
        $second = $this->addBranch($store, 'Second');
        $place = 'ChIJ-a1aaaaaaaaaa';
        $first->update(['google_place_id' => $place]);

        $this->actingAs($owner)
            ->from($this->editUrl($store))
            ->put(route('branches.update', $second->id), ['name' => 'Second', 'active' => '1', 'google_place_id' => $place])
            ->assertSessionHasErrors('google_place_id');
        $this->assertNull($second->fresh()->google_place_id);
        $this->assertSame($place, $first->fresh()->google_place_id);
    }

    /** (d) the same place id in another store is a different row and allowed. */
    public function test_same_place_id_in_another_store_is_allowed(): void
    {
        $ownerA = $this->makeOwner();
        $storeA = $this->makeStore($ownerA);
        $branchA = $this->firstBranch($storeA);
        $ownerB = $this->makeOwner();
        $storeB = $this->makeStore($ownerB);
        $branchB = $this->firstBranch($storeB);
        $place = 'ChIJ-b1bbbbbbbbbb';
        $branchA->update(['google_place_id' => $place]);

        $this->actingAs($ownerB)
            ->from($this->editUrl($storeB))
            ->put(route('branches.update', $branchB->id), ['name' => $branchB->name, 'active' => '1', 'google_place_id' => $place])
            ->assertRedirect();
        $this->assertSame($place, $branchB->fresh()->google_place_id);
    }

    /** (e) anything that is not a place ID is rejected. */
    public function test_invalid_place_id_format_is_rejected(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $branch = $this->firstBranch($store);

        $this->actingAs($owner)
            ->from($this->editUrl($store))
            ->put(route('branches.update', $branch->id), ['name' => $branch->name, 'active' => '1', 'google_place_id' => 'x-not-valid!'])
            ->assertSessionHasErrors('google_place_id');
        $this->assertNull($branch->fresh()->google_place_id);
    }

    /** (f) the merchant id is shown: single eligible branch `store-<id>`, several `store-<id>-b<branch>`. */
    public function test_branch_merchant_id_and_eligibility_are_shown(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $branch = $this->firstBranch($store);
        $this->shareLayoutGlobals();

        // No coordinates → not eligible, no merchant ("Not published").
        $this->actingAs($owner)
            ->get($this->editUrl($store))
            ->assertOk()
            ->assertSee('Not published', false)
            ->assertSee('Place ID missing', false);

        // One eligible branch keeps the historic per-store merchant id.
        $branch->update(['latitude' => 31.5, 'longitude' => 34.7]);
        $this->get($this->editUrl($store))
            ->assertOk()
            ->assertSee('store-'.$store->id, false)
            ->assertSee('Eligible for Google', false);

        // Several eligible branches each publish their own merchant id.
        $second = $this->addBranch($store, 'Second');
        $second->update(['latitude' => 31.6, 'longitude' => 34.8]);
        $this->get($this->editUrl($store))
            ->assertOk()
            ->assertSee('store-'.$store->id.'-b'.$branch->id, false)
            ->assertSee('store-'.$store->id.'-b'.$second->id, false);
    }
}
