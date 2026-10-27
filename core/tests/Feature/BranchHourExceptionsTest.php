<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\BranchHourException;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-291: `branch_hour_exceptions` — closed dates and special-hours dates.
 *
 *  - A closed exception today makes `isOpenNow()` false and `store_open_now()` false.
 *  - A special-hours exception overrides the weekly entry (weekly closed +
 *    exception 10:00-14:00 → open at 12:00, closed at 15:00).
 *  - Overnight exception windows are honoured.
 *  - A slot on a closed-exception date is refused by SchedulingService::validate.
 *  - The branch-local date is evaluated in the branch's own timezone (Asia/Riyadh
 *    at 22:30 UTC is the next local date).
 */
class BranchHourExceptionsTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-06-29 is Monday; 2026-06-30 is Tuesday. */
    private const MON = '2026-06-29';

    private const TUE = '2026-06-30';

    private const RIYADH = 'Asia/Riyadh';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6),
            'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $user, string $tz = 'UTC', ?array $hours = null): Store
    {
        $store = Store::create([
            'user_id' => $user->id,
            'slug' => 's-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'T', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
        $branch = Branch::where('store_id', $store->id)->first();
        if ($branch) {
            $branch->update([
                'timezone' => $tz,
                'hours' => $hours ?? array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '22:00', 'closed' => false]),
            ]);
        }

        return $store->fresh();
    }

    private function makeItem(Store $store): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item',
            'price' => 10.00, 'active' => 1,
        ]);
    }

    // --------------------------------------------------------- closed exception

    public function test_closed_exception_today_makes_is_open_now_false(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user);
        $branch = $store->branches()->first();

        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::MON, 'closed' => true,
        ]);

        Carbon::setTestNow(Carbon::parse(self::MON.' 12:00:00', 'UTC'));

        $this->assertFalse($branch->fresh()->isOpenNow());
        $this->assertFalse(store_open_now($store->fresh()));
    }

    public function test_closed_exception_today_refuses_the_storefront_order(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user);
        $branch = $store->branches()->first();
        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::MON, 'closed' => true,
        ]);
        $item = $this->makeItem($store);

        Carbon::setTestNow(Carbon::parse(self::MON.' 12:00:00', 'UTC'));

        $res = $this->post(route('store.submitOrder', $store), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '555-2000',
            'pay_via' => 'cash',
            'total' => '10.00',
        ]);
        $json = $res->json();
        $this->assertFalse(($json['success'] ?? null), 'a closed-exception day must refuse the order');
        $this->assertNotEmpty(($json['message'] ?? ''));
    }

    // ------------------------------------------ special hours override weekly

    public function test_special_hours_override_weekly_closed(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user, 'UTC', array_merge(
            array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '22:00', 'closed' => false]),
            ['mon' => ['open' => '09:00', 'close' => '22:00', 'closed' => true]]
        ));
        $branch = $store->branches()->first();

        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::MON, 'closed' => false, 'open' => '10:00', 'close' => '14:00',
        ]);

        // 12:00 is inside the special window.
        Carbon::setTestNow(Carbon::parse(self::MON.' 12:00:00', 'UTC'));
        $this->assertTrue($branch->fresh()->isOpenNow(), 'open inside special hours');

        // 15:00 is outside.
        Carbon::setTestNow(Carbon::parse(self::MON.' 15:00:00', 'UTC'));
        $this->assertFalse($branch->fresh()->isOpenNow(), 'closed outside special hours');
    }

    // ------------------------------------------------------- overnight exception

    public function test_overnight_exception_window(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user);
        $branch = $store->branches()->first();

        // Monday's special window crosses midnight (18:00 → 02:00 Tuesday).
        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::MON, 'closed' => false, 'open' => '18:00', 'close' => '02:00',
        ]);

        // 23:00 Mon → inside.
        Carbon::setTestNow(Carbon::parse(self::MON.' 23:00:00', 'UTC'));
        $this->assertTrue($branch->fresh()->isOpenNow());

        // 01:00 Tue → inside (overnight spillover from Mon's special hours).
        Carbon::setTestNow(Carbon::parse(self::TUE.' 01:00:00', 'UTC'));
        $this->assertTrue($branch->fresh()->isOpenNow());

        // 03:00 Tue → outside.
        Carbon::setTestNow(Carbon::parse(self::TUE.' 03:00:00', 'UTC'));
        $this->assertFalse($branch->fresh()->isOpenNow());
    }

    // ----------------------------------- closed-exception suppression of bleed

    public function test_closed_exception_yesterday_suppresses_the_weekly_bleed(): void
    {
        $user = $this->makeUser();
        // Sunday normal weekly 18:00-02:00, Tuesday normal weekly closed → bleed would
        // otherwise keep Mon 01:00 "open". We use Mon as today and Sun as yesterday
        // with a closed exception on Sun.
        $store = $this->makeStore($user, 'UTC', array_merge(
            array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '22:00', 'closed' => false]),
            ['sun' => ['open' => '18:00', 'close' => '02:00', 'closed' => false]],
            ['mon' => ['open' => '00:00', 'close' => '00:00', 'closed' => true]]
        ));
        $branch = $store->branches()->first();

        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => '2026-06-28', 'closed' => true,
        ]);

        // 01:00 Mon → without the exception the bleed would say open; the
        // closed-exception on yesterday must suppress it.
        Carbon::setTestNow(Carbon::parse('2026-06-29 01:00:00', 'UTC'));
        $this->assertFalse($branch->fresh()->isOpenNow(), 'closed-exception-yesterday must suppress the weekly bleed');
    }

    public function test_special_hours_yesterday_replace_the_weekly_bleed(): void
    {
        $user = $this->makeUser();
        // Sun's weekly 09:00-22:00 is a normal day; a special exception on Sun
        // opens 18:00-02:00. Mon 01:00 should be open via the Sun special hours
        // (not via the Sun weekly which would have closed by 22:00).
        $store = $this->makeStore($user, 'UTC');
        $branch = $store->branches()->first();

        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => '2026-06-28', 'closed' => false, 'open' => '18:00', 'close' => '02:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-29 01:00:00', 'UTC'));
        $this->assertTrue($branch->fresh()->isOpenNow(), 'yesterday special-hours bleed covers 01:00 today');
    }

    // ----------------------------------------------------- no-hours + exception

    public function test_exception_applies_when_branch_has_no_weekly_hours(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user, 'UTC', []); // no weekly hours
        $branch = $store->branches()->first();
        $branch->update(['hours' => null]);

        // Closed exception → closed (even though no weekly hours).
        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::MON, 'closed' => true,
        ]);
        Carbon::setTestNow(Carbon::parse(self::MON.' 12:00:00', 'UTC'));
        $this->assertFalse($branch->fresh()->isOpenNow(), 'a closed exception on a no-hours branch is closed');

        // Special hours → opens only within them.
        BranchHourException::query()->where('branch_id', $branch->id)->delete();
        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::TUE, 'closed' => false, 'open' => '10:00', 'close' => '14:00',
        ]);
        Carbon::setTestNow(Carbon::parse(self::TUE.' 12:00:00', 'UTC'));
        $this->assertTrue($branch->fresh()->isOpenNow(), 'special hours open the no-hours branch');
        Carbon::setTestNow(Carbon::parse(self::TUE.' 09:00:00', 'UTC'));
        $this->assertFalse($branch->fresh()->isOpenNow(), 'outside the special window the no-hours branch is closed');
    }

    // ------------------------------------------------------- SchedulingService

    public function test_scheduling_refuses_a_preorder_on_a_closed_exception_date(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user);
        $branch = $store->branches()->first();
        StoreSettings::set($store->id, 'allow_order_scheduling', '1');

        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::TUE, 'closed' => true,
        ]);

        $svc = new SchedulingService;
        $requested = Carbon::parse(self::TUE.' 12:00:00', 'UTC')->format('Y-m-d H:i');

        $this->expectException(\InvalidArgumentException::class);
        $svc->validate($store->id, $branch, 'takeaway', $requested);
    }

    public function test_scheduling_honours_a_special_hours_exception_for_a_branch_with_no_weekly_hours(): void
    {
        $user = $this->makeUser();
        $store = $this->makeStore($user, 'UTC', []);
        $branch = $store->branches()->first();
        $branch->update(['hours' => null]);
        StoreSettings::set($store->id, 'allow_order_scheduling', '1');

        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::TUE, 'closed' => false, 'open' => '10:00', 'close' => '14:00',
        ]);

        $svc = new SchedulingService;
        Carbon::setTestNow(Carbon::parse(self::MON.' 09:00:00', 'UTC'));

        // 12:00 Tue inside the special hours.
        $requested = Carbon::parse(self::TUE.' 12:00:00', 'UTC')->format('Y-m-d H:i');
        $this->assertNotNull($svc->validate($store->id, $branch, 'takeaway', $requested));

        // 09:00 Tue outside.
        $this->expectException(\InvalidArgumentException::class);
        $svc->validate($store->id, $branch, 'takeaway', Carbon::parse(self::TUE.' 09:00:00', 'UTC')->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------- timezone

    public function test_exception_evaluated_in_branch_timezone(): void
    {
        $user = $this->makeUser();
        // Branch tz = Riyadh (UTC+3). 22:30 UTC Mon = 01:30 Tue Riyadh.
        $store = $this->makeStore($user, self::RIYADH);
        $branch = $store->branches()->first();

        // Close Tuesday (the Riyadh date for 22:30 UTC Mon).
        BranchHourException::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branch->id,
            'date' => self::TUE, 'closed' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-29 22:30:00', 'UTC'));
        $this->assertFalse($branch->fresh()->isOpenNow(), 'Riyadh-Tue at 01:30 must be closed by the Tue exception');
    }
}
