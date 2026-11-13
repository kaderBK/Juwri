<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchStaffTest extends TestCase
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
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'staff_limit' => 99]), 'status' => 1,
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
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_staff_can_be_assigned_to_a_branch(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();

        $this->actingAs($owner)->post(route('staff.store'), [
            'name' => 'Sam', 'email' => 'sam'.Str::random(4).'@x.com', 'password' => 'secret1',
            'role' => 'waiter', 'store_id' => $r->id, 'branch_id' => $branch->id,
        ])->assertRedirect();

        $staff = StaffMember::where('email', 'like', 'sam%')->first();
        $this->assertEquals($branch->id, $staff->branch_id);
        $this->assertEquals($branch->id, $staff->branch->id);
    }

    public function test_cannot_assign_foreign_branch(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $foreignBranch = Branch::where('store_id', $r2->id)->first();

        $this->actingAs($owner)->post(route('staff.store'), [
            'name' => 'Sam', 'email' => 'sam'.Str::random(4).'@x.com', 'password' => 'secret1',
            'role' => 'waiter', 'store_id' => $r1->id, 'branch_id' => $foreignBranch->id,
        ])->assertNotFound();
    }

    public function test_empty_branch_means_all_branches(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('staff.store'), [
            'name' => 'Sam', 'email' => 'sam'.Str::random(4).'@x.com', 'password' => 'secret1',
            'role' => 'manager', 'store_id' => $r->id, 'branch_id' => '',
        ])->assertRedirect();

        $staff = StaffMember::where('email', 'like', 'sam%')->first();
        $this->assertNull($staff->branch_id);
    }
}
