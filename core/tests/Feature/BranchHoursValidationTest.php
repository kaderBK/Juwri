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
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-288: branch hours + timezone input validation through the real
 * `branches.update` and `branches.hours` routes. The grid must be rejected when
 * a day is non-closed and either time is missing/equal (no silent defaulting);
 * the timezone must be a valid IANA identifier (Laravel's `timezone:all` rule).
 *
 * The earlier controller fell through to `09:00/22:00` for any missing value,
 * so the merchant could not tell if their save was a typo or an overnight
 * window that just lost a digit.
 */
class BranchHoursValidationTest extends TestCase
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

    /**
     * Build a valid 7-day grid; override individual cells via $patch (keys are H:i or 1).
     *
     * @param  array<string, string|int>  $patch
     * @return array<string, array{open: string, close: string, closed?: string}>
     */
    private function validGrid(array $patch = []): array
    {
        $grid = [];
        foreach (Branch::DAYS as $day) {
            $grid[$day] = ['open' => '09:00', 'close' => '22:00'];
        }
        foreach ($patch as $key => $value) {
            [$day, $field] = explode('.', $key, 2);
            $grid[$day][$field] = $value;
        }

        return $grid;
    }

    /** Bad time like "9pm" fails `date_format:H:i` on updateHours. */
    public function test_update_hours_rejects_bad_time_string(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $grid = $this->validGrid(['mon.open' => '9pm']);

        $response = $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), ['timezone' => 'UTC', 'hours' => $grid]);

        $response->assertSessionHasErrors('hours.mon.open');
        $this->assertNull($branch->fresh()->hours); // nothing was written
    }

    /** Array injection `hours[mon][open][]=x` is rejected by the `string` rule. */
    public function test_update_hours_rejects_array_injection(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $payload = ['timezone' => 'UTC', 'hours' => ['mon' => ['open' => ['x'], 'close' => '22:00']]];

        $response = $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), $payload);

        $response->assertSessionHasErrors('hours.mon.open');
        $this->assertNull($branch->fresh()->hours);
    }

    /** On an open day, open==close is rejected (close < open = overnight, which is allowed). */
    public function test_update_hours_rejects_open_equal_close(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $grid = $this->validGrid(['mon.close' => '09:00']);

        $response = $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), ['timezone' => 'UTC', 'hours' => $grid]);

        $response->assertSessionHasErrors('hours.mon.close');
        $this->assertNull($branch->fresh()->hours);
    }

    /** On an open day, missing open is rejected (no silent `09:00` fallback). */
    public function test_update_hours_rejects_missing_open_on_open_day(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $grid = $this->validGrid(['mon.open' => '']);

        $response = $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), ['timezone' => 'UTC', 'hours' => $grid]);

        $response->assertSessionHasErrors('hours.mon.open');
        $this->assertNull($branch->fresh()->hours);
    }

    /** On a closed day, missing open/close is fine; the value is still persisted. */
    public function test_update_hours_allows_missing_on_closed_day(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $grid = $this->validGrid();
        $grid['sun'] = ['open' => '', 'close' => '', 'closed' => '1'];

        $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), ['timezone' => 'UTC', 'hours' => $grid])
            ->assertSessionHasNoErrors();

        $fresh = $branch->fresh();
        $this->assertSame('', $fresh->hours['sun']['open']);
        $this->assertSame('', $fresh->hours['sun']['close']);
        $this->assertTrue((bool) $fresh->hours['sun']['closed']);
    }

    /** Overnight windows (close < open) are explicitly allowed. */
    public function test_update_hours_saves_overnight_window(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();
        $grid = $this->validGrid(['fri.open' => '22:00', 'fri.close' => '02:00']);

        $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), ['timezone' => 'UTC', 'hours' => $grid])
            ->assertSessionHasNoErrors();

        $fresh = $branch->fresh();
        $this->assertSame('22:00', $fresh->hours['fri']['open']);
        $this->assertSame('02:00', $fresh->hours['fri']['close']);
        $this->assertFalse((bool) $fresh->hours['fri']['closed']);
    }

    /** Free-text "Riyadh" is not an IANA identifier and is rejected. */
    public function test_update_hours_rejects_free_text_timezone(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();

        $response = $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), [
                'timezone' => 'Riyadh',
                'hours' => $this->validGrid(),
            ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertNull($branch->fresh()->timezone);
    }

    /** IANA identifier `Asia/Riyadh` is accepted and saved. */
    public function test_update_hours_saves_iana_timezone(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();

        $this->actingAs($owner)
            ->put(route('branches.hours', $branch->id), [
                'timezone' => 'Asia/Riyadh',
                'hours' => $this->validGrid(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Riyadh', $branch->fresh()->timezone);
    }

    /** Same IANA guard on the edit route — `branch.update` rejects `"Riyadh"`. */
    public function test_update_rejects_free_text_timezone(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();

        $response = $this->actingAs($owner)
            ->put(route('branches.update', $branch->id), [
                'name' => $branch->name, 'active' => '1', 'timezone' => 'Riyadh',
            ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertNull($branch->fresh()->timezone);
    }

    /** The edit route accepts the matching IANA identifier and stores it. */
    public function test_update_saves_iana_timezone(): void
    {
        [$owner, , $branch] = $this->makeOwnerWithBranch();

        $this->actingAs($owner)
            ->put(route('branches.update', $branch->id), [
                'name' => $branch->name, 'active' => '1', 'timezone' => 'Asia/Riyadh',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Riyadh', $branch->fresh()->timezone);
    }
}
