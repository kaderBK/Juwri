<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\BranchHourException;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-291: owner routes for holidays / special hours. The grid must be rejected when
 * a day is non-closed and either time is missing/equal (no silent defaulting);
 * the timezone must be a valid IANA identifier (Laravel's `timezone:all` rule).
 *
 * The earlier controller fell through to `09:00/22:00` for any missing value,
 * so the merchant could not tell if their save was a typo or an overnight
 * window that just lost a digit.
 */
class BranchHourExceptionsOwnerTest extends TestCase
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

        // Match the neighbouring Branch tests: keep the real pipeline but skip
        // the install-gate, CSRF, and staff-permission middlewares that would
        // 4xx unrelated to the validation rule under test.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /**
     * @return array{0: User, 1: Store, 2: Branch}
     */
    private function makeOwnerWithBranch(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = Branch::where('store_id', $store->id)->firstOrFail();

        return [$owner, $store, $branch];
    }

    private function d(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }

    public function test_closed_date_saved_and_upserted(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $this->actingAs($owner)->post(route('branches.exceptions.store', $branch->id), ['date' => $this->d(3), 'closed' => 1, 'note' => 'Eid'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('branches.exceptions.store', $branch->id), ['date' => $this->d(3), 'open' => '10:00', 'close' => '14:00'])->assertSessionHasNoErrors();
        $this->assertSame(1, BranchHourException::where('branch_id', $branch->id)->count());
        $row = BranchHourException::where('branch_id', $branch->id)->first();
        $this->assertFalse((bool) $row->closed);
        $this->assertSame('10:00', $row->open);
    }

    public function test_past_date_and_bad_times_rejected(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $url = route('branches.exceptions.store', $branch->id);
        $this->actingAs($owner)->post($url, ['date' => now()->subDays(5)->toDateString(), 'closed' => 1])->assertSessionHasErrors('date');
        $this->actingAs($owner)->post($url, ['date' => $this->d(2), 'open' => '9pm', 'close' => '14:00'])->assertSessionHasErrors('open');
        $this->actingAs($owner)->post($url, ['date' => $this->d(2), 'open' => '10:00', 'close' => '10:00'])->assertSessionHasErrors('close');
        $this->actingAs($owner)->post($url, ['date' => $this->d(2)])->assertSessionHasErrors('open');
        $this->assertSame(0, BranchHourException::count());
    }

    public function test_overnight_special_hours_accepted(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $this->actingAs($owner)->post(route('branches.exceptions.store', $branch->id), ['date' => $this->d(4), 'open' => '20:00', 'close' => '02:00'])->assertSessionHasNoErrors();
        $this->assertSame(1, BranchHourException::count());
    }

    public function test_other_owner_gets_404_and_delete_works(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        [$other] = $this->makeOwnerWithBranch();
        $this->actingAs($owner)->post(route('branches.exceptions.store', $branch->id), ['date' => $this->d(3), 'closed' => 1]);
        $ex = BranchHourException::first();

        $this->actingAs($other)->post(route('branches.exceptions.store', $branch->id), ['date' => $this->d(5), 'closed' => 1])->assertNotFound();
        $this->actingAs($other)->delete(route('branches.exceptions.destroy', [$branch->id, $ex->id]))->assertNotFound();
        $this->assertSame(1, BranchHourException::count());

        $this->actingAs($owner)->delete(route('branches.exceptions.destroy', [$branch->id, $ex->id]))->assertSessionHasNoErrors();
        $this->assertSame(0, BranchHourException::count());
    }

    public function test_prune_keeps_recent_and_future_removes_old(): void
    {
        [, $store, $branch] = $this->makeOwnerWithBranch();
        foreach ([-10 => 'old', 0 => 'today', 5 => 'future'] as $off => $note) {
            BranchHourException::create(['store_id' => $store->id, 'branch_id' => $branch->id, 'date' => now('UTC')->addDays($off)->toDateString(), 'closed' => true, 'note' => $note]);
        }
        $this->artisan('branches:prune-hour-exceptions')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['today', 'future'], BranchHourException::pluck('note')->all());
    }
}
