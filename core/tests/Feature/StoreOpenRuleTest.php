<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreStatus;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-290: one "is open" rule.
 *
 * - `Branch::isOpenAt()` evaluates the same rule as the legacy `isOpenNow()` but
 *   at an arbitrary Carbon, then `isOpenNow() = isOpenAt(now)`.
 * - `Branch::isOpenNow()` honours `branch_hour_exceptions` (closed or special).
 * - `StoreStatus::for()`: no active branch → open=false; otherwise open when ANY
 *   active branch is open AND accepting_orders. One resolver, same shape.
 * - `store_open_now()`: manual switch wins first; then a single resolver that
 *   routes through StoreStatus when structured hours OR an exception applies;
 *   falls back to the legacy `posts.timing` text model when the store has no
 *   structured hours and no exception.
 * - The storefront order POST (`restaurant.submitOrder`) refuses an order when the
 *   store is closed.
 *
 * Tests freeze time with `Carbon::setTestNow()` so the resolver sees a
 * deterministic wall-clock per scenario.
 */
class StoreOpenRuleTest extends TestCase
{
    use RefreshDatabase;

    /** Asia/Riyadh is UTC+3 — a foreign tz so the resolver must convert. */
    private const RIYADH = 'Asia/Riyadh';

    /** 2026-06-29 is a Monday. */
    private const MON_ISO = '2026-06-29';

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

    private function makeRestaurant(User $user, ?string $timezone = 'UTC', ?array $hours = null): Store
    {
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $user->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        // The boot() handler auto-creates a "Main" branch; configure its hours + tz.
        $branch = Branch::where('store_id', $store->id)->first();
        if ($branch) {
            $branch->update([
                'timezone' => $timezone,
                'hours' => $hours ?? array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '22:00', 'closed' => false]),
            ]);
        }

        return $store;
    }

    private function makeItem(Store $store): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item',
            'price' => 10.00, 'active' => 1,
        ]);
    }

    private function addBranch(Store $store, string $name = 'B', string $open = '09:00', string $close = '22:00', bool $closed = false): Branch
    {
        $hours = [];
        foreach (Branch::DAYS as $d) {
            $hours[$d] = ['open' => $open, 'close' => $close, 'closed' => $closed];
        }

        return Branch::create([
            'store_id' => $store->id,
            'name' => $name,
            'active' => true,
            'accepting_orders' => true,
            'position' => 99,
            'timezone' => 'UTC',
            'hours' => $hours,
        ]);
    }

    private function postOrder(Store $store, Menu $item, array $extra = [])
    {
        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '555-2000',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    // --------------------------------------------------------------- no-branch

    public function test_a_store_with_no_active_branches_is_closed(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);

        // Deactivate the auto-created Main branch.
        Branch::where('store_id', $store->id)->update(['active' => false]);

        $this->assertFalse(StoreStatus::for($store->fresh())['open']);
        $this->assertFalse(store_open_now($store->fresh()));
    }

    public function test_web_checkout_refuses_an_order_for_a_store_with_no_active_branches(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);
        Branch::where('store_id', $store->id)->update(['active' => false]);
        $item = $this->makeItem($store);

        $res = $this->postOrder($store, $item);
        $json = $res->json();
        $this->assertFalse(($json['success'] ?? null), 'checkout must refuse the order');
        $this->assertNotEmpty(($json['message'] ?? ''), 'checkout must return a closed-message');
    }

    // ---------------------------------------------------------- one open branch

    public function test_a_store_with_one_open_branch_is_open(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);

        Carbon::setTestNow(Carbon::parse(self::MON_ISO.' 12:00:00', 'UTC'));

        $status = StoreStatus::for($store->fresh());
        $this->assertTrue($status['open']);
        $this->assertTrue(store_open_now($store->fresh()));
    }

    // ------------------------------------------------ one branch open + closed

    public function test_one_branch_open_keeps_the_store_open_even_when_another_is_closed(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);
        // Add a second branch with closed-today hours so the resolver must pick.
        Branch::create([
            'store_id' => $store->id,
            'name' => 'Closed Branch',
            'active' => true,
            'accepting_orders' => true,
            'position' => 1,
            'timezone' => 'UTC',
            'hours' => array_merge(
                array_fill_keys(Branch::DAYS, ['open' => '00:00', 'close' => '23:59', 'closed' => false]),
                ['mon' => ['open' => '00:00', 'close' => '23:59', 'closed' => true]]
            ),
        ]);

        Carbon::setTestNow(Carbon::parse(self::MON_ISO.' 12:00:00', 'UTC'));

        $status = StoreStatus::for($store->fresh());
        $this->assertTrue($status['open'], 'the open branch must keep the store open');
        $this->assertTrue(store_open_now($store->fresh()));
    }

    public function test_all_branches_closed_today_means_store_closed(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);
        Branch::create([
            'store_id' => $store->id,
            'name' => 'Also Closed',
            'active' => true,
            'accepting_orders' => true,
            'position' => 1,
            'timezone' => 'UTC',
            'hours' => array_merge(
                array_fill_keys(Branch::DAYS, ['open' => '00:00', 'close' => '23:59', 'closed' => false]),
                ['mon' => ['open' => '00:00', 'close' => '23:59', 'closed' => true]]
            ),
        ]);
        // Close the Main branch today too.
        Branch::where('store_id', $store->id)->where('position', 0)->update([
            'hours' => array_merge(
                array_fill_keys(Branch::DAYS, ['open' => '00:00', 'close' => '23:59', 'closed' => false]),
                ['mon' => ['open' => '00:00', 'close' => '23:59', 'closed' => true]]
            ),
        ]);

        Carbon::setTestNow(Carbon::parse(self::MON_ISO.' 12:00:00', 'UTC'));

        $this->assertFalse(StoreStatus::for($store->fresh())['open']);
        $this->assertFalse(store_open_now($store->fresh()));
    }

    public function test_web_checkout_refuses_an_order_when_all_branches_are_closed(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);
        Branch::where('store_id', $store->id)->update([
            'hours' => array_merge(
                array_fill_keys(Branch::DAYS, ['open' => '00:00', 'close' => '23:59', 'closed' => false]),
                ['mon' => ['open' => '00:00', 'close' => '23:59', 'closed' => true]]
            ),
        ]);
        $item = $this->makeItem($store);

        Carbon::setTestNow(Carbon::parse(self::MON_ISO.' 12:00:00', 'UTC'));

        $res = $this->postOrder($store, $item);
        $json = $res->json();
        $this->assertFalse(($json['success'] ?? null), 'all-closed must refuse the order');
        $this->assertNotEmpty(($json['message'] ?? ''));
    }

    // ---------------------------------------------------- store_open_now: legacy

    public function test_store_with_no_hours_and_a_friendly_timing_text_remains_open(): void
    {
        $user = $this->makeUser();
        // Legacy path: no structured hours, but the store's `timing` text says 24/7.
        $store = $this->makeRestaurant($user, 'UTC', []);
        $store->update(['timing' => '24 hours']);
        Branch::where('store_id', $store->id)->update(['hours' => null]);

        Carbon::setTestNow(Carbon::parse(self::MON_ISO.' 03:00:00', 'UTC'));

        // Branch now has no hours, no exception → legacy `posts.timing` text path.
        $this->assertTrue(store_open_now($store->fresh()), '24/7 timing text → open');
    }

    /** A Store whose first branches() call throws, later calls hit the database. */
    private function storeThatThrowsOnce(Store $real): Store
    {
        $calls = 0;
        $mock = \Mockery::mock($real)->makePartial();
        $mock->shouldReceive('branches')->andReturnUsing(function () use (&$calls, $real) {
            if ($calls++ === 0) {
                throw new \RuntimeException('boom');
            }

            return $real->branches();
        });

        return $mock;
    }

    // -------------------------------------------------- store_open_now: failures

    public function test_store_open_now_fails_closed_when_a_branch_has_hours_and_an_error_occurs(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);

        Log::shouldReceive('warning')->once()->withArgs(function ($msg, $ctx) use ($store) {
            return $msg === 'store_open_now failed' && (int) $ctx['store_id'] === (int) $store->id;
        });

        // The first branches() lookup throws; the fail-closed check then sees real data
        // (a branch still has hours), so the resolver must answer CLOSED.
        $this->assertFalse(store_open_now($this->storeThatThrowsOnce($store->fresh())));
    }

    public function test_store_open_now_fails_open_when_no_hours_and_no_exceptions(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user, 'UTC', []);
        Branch::where('store_id', $store->id)->update(['hours' => null]);

        Log::shouldReceive('warning')->once();

        $this->assertTrue(store_open_now($this->storeThatThrowsOnce($store->fresh())));
    }

    // ------------------------------------------------------------- timezone

    public function test_timezone_respected_for_store_open_now(): void
    {
        $user = $this->makeUser();
        // Riyadh (UTC+3). At 22:30 UTC on Monday, Riyadh is already 01:30 Tuesday.
        $store = $this->makeRestaurant($user, self::RIYADH, array_merge(
            array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '22:00', 'closed' => false]),
            ['tue' => ['open' => '00:00', 'close' => '00:00', 'closed' => true]]
        ));

        Carbon::setTestNow(Carbon::parse('2026-06-29 22:30:00', 'UTC'));

        // Tuesday in Riyadh is closed weekly → store closed.
        $this->assertFalse(store_open_now($store->fresh()));
        $this->assertFalse(StoreStatus::for($store->fresh())['open']);
    }
}
