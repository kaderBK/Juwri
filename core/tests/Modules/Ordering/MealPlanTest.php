<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\MealPlan;
use Juwri\Modules\Ordering\Services\MealPlanService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * theme-pack-blade F1: recurring meal-plan generation + lifecycle.
 */
class MealPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();
    }

    /** @return array{owner:User,r:Store,menu:Menu,customer:Customer} */
    private function make(bool $enabled = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
        StoreOption::updateStoreOption($r->id, 'meal_plans_enabled', $enabled ? '1' : '0');

        $customer = Customer::create([
            'store_id' => $r->id, 'name' => 'Sam', 'phone' => UniquePhone::next('900'),
        ]);

        return compact('owner', 'r', 'menu', 'customer');
    }

    private function makePlan(array $ctx, array $overrides = []): MealPlan
    {
        return MealPlan::create(array_merge([
            'customer_id' => $ctx['customer']->id,
            'store_id' => $ctx['r']->id,
            'name' => 'Weekly lunch',
            'cadence' => 'weekly',
            'start_date' => '2026-08-07', // Friday
            'next_run' => '2026-08-07',
            'status' => 'active',
            'items' => [['item_id' => $ctx['menu']->id, 'quantity' => 2]],
        ], $overrides));
    }

    private function service(): MealPlanService
    {
        return app(MealPlanService::class);
    }

    public function test_generation_on_due_date_creates_order_and_advances_next_run(): void
    {
        $ctx = $this->make();
        $plan = $this->makePlan($ctx);

        $created = $this->service()->generateDue('2026-08-07');

        $this->assertSame(1, $created);
        $order = Order::where('store_id', $ctx['r']->id)->first();
        $this->assertNotNull($order);
        $this->assertSame($ctx['customer']->id, (int) $order->customer_id);
        $this->assertSame('meal_plan', $order->source);
        $this->assertEquals(0, (int) $order->is_paid); // UNPAID — money movement gated
        $this->assertSame(1, $order->items()->count());

        // Weekly cadence advances next_run by 7 days (Fri → next Fri).
        $this->assertSame('2026-08-14', $plan->fresh()->next_run->toDateString());
    }

    public function test_not_yet_due_plan_does_not_generate(): void
    {
        $ctx = $this->make();
        $this->makePlan($ctx, ['next_run' => '2026-08-14']);

        $created = $this->service()->generateDue('2026-08-07');

        $this->assertSame(0, $created);
        $this->assertSame(0, Order::where('store_id', $ctx['r']->id)->count());
    }

    public function test_paused_plan_does_not_generate(): void
    {
        $ctx = $this->make();
        $plan = $this->makePlan($ctx, ['status' => 'paused']);

        $created = $this->service()->generateDue('2026-08-07');

        $this->assertSame(0, $created);
        $this->assertSame(0, Order::where('store_id', $ctx['r']->id)->count());
        // Paused plan keeps its next_run untouched.
        $this->assertSame('2026-08-07', $plan->fresh()->next_run->toDateString());
    }

    public function test_skip_advances_next_run_without_generating(): void
    {
        $ctx = $this->make();
        $plan = $this->makePlan($ctx);

        // Skip = advance one cadence period, no order.
        $plan->advanceNextRun();

        $this->assertSame('2026-08-14', $plan->fresh()->next_run->toDateString());
        $this->assertSame(0, Order::where('store_id', $ctx['r']->id)->count());
    }

    public function test_cancel_removes_future_runs(): void
    {
        $ctx = $this->make();
        $plan = $this->makePlan($ctx);

        $plan->status = 'cancelled';
        $plan->next_run = null;
        $plan->save();

        $created = $this->service()->generateDue('2026-08-07');

        $this->assertSame(0, $created);
        $this->assertNull($plan->fresh()->next_run);
        $this->assertSame(0, Order::where('store_id', $ctx['r']->id)->count());
    }

    public function test_disabled_restaurant_is_a_no_op(): void
    {
        $ctx = $this->make(enabled: false);
        $plan = $this->makePlan($ctx);

        $created = $this->service()->generateDue('2026-08-07');

        $this->assertSame(0, $created);
        $this->assertSame(0, Order::where('store_id', $ctx['r']->id)->count());
        // Disabled → no generation AND no advance.
        $this->assertSame('2026-08-07', $plan->fresh()->next_run->toDateString());
    }

    public function test_command_runs_and_generates(): void
    {
        $ctx = $this->make();
        $this->makePlan($ctx);

        $this->artisan('meals:generate-due', ['--date' => '2026-08-07'])
            ->assertExitCode(0);

        $this->assertSame(1, Order::where('store_id', $ctx['r']->id)->count());
    }
}
