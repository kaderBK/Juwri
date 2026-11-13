<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchHoursTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branchOf(Store $r): Branch
    {
        return Branch::where('store_id', $r->id)->first();
    }

    /** No hours defined → open. */
    public function test_open_when_no_hours(): void
    {
        $b = $this->branchOf($this->makeRestaurant($this->makeUser()));
        $this->assertTrue($b->isOpenNow());
        $this->assertEquals('open', $b->statusLabel());
    }

    /** Within the day window → open; outside → closed. */
    public function test_open_window_evaluation(): void
    {
        $b = $this->branchOf($this->makeRestaurant($this->makeUser()));
        $b->update([
            'timezone' => 'UTC',
            'hours' => array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '17:00', 'closed' => false]),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC')); // Monday noon
        $this->assertTrue($b->fresh()->isOpenNow());

        Carbon::setTestNow(Carbon::parse('2026-06-29 20:00:00', 'UTC')); // Monday 8pm
        $this->assertFalse($b->fresh()->isOpenNow());
    }

    /** Day flagged closed → closed regardless of time. */
    public function test_closed_day(): void
    {
        $b = $this->branchOf($this->makeRestaurant($this->makeUser()));
        $hours = array_fill_keys(Branch::DAYS, ['open' => '00:00', 'close' => '23:59', 'closed' => false]);
        $hours['mon'] = ['open' => '09:00', 'close' => '17:00', 'closed' => true];
        $b->update(['timezone' => 'UTC', 'hours' => $hours]);

        Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC')); // Monday
        $this->assertFalse($b->fresh()->isOpenNow());
    }

    /** accepting_orders=false → cannot accept even if open. */
    public function test_paused_blocks_orders(): void
    {
        $b = $this->branchOf($this->makeRestaurant($this->makeUser()));
        $b->update(['accepting_orders' => false]);
        $this->assertFalse($b->fresh()->canAcceptOrders());
        $this->assertEquals('paused', $b->fresh()->statusLabel());
    }

    /** Hours form persists the weekly grid. */
    public function test_update_hours_endpoint(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $b = $this->branchOf($r);

        $hours = [];
        foreach (Branch::DAYS as $d) {
            $hours[$d] = ['open' => '08:00', 'close' => '20:00'];
        }
        $hours['sun']['closed'] = '1';

        $this->actingAs($owner)->put(route('branches.hours', $b->id), [
            'timezone' => 'UTC', 'hours' => $hours,
        ])->assertRedirect();

        $fresh = $b->fresh();
        $this->assertEquals('08:00', $fresh->hours['mon']['open']);
        $this->assertTrue($fresh->hours['sun']['closed']);
        $this->assertEquals('UTC', $fresh->timezone);
    }
}
