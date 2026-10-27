<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-05 (e2e journey p4-preorders): a pre-order is for a LATER time, so the branch being closed right now must not block it. The store-level
 * gate always let a scheduled order through while closed; the branch-level gate (#76) refused it, so a diner could only pre-order while the
 * branch was open. A paused / switched-off branch still refuses, and an order for now is still refused while the branch is closed.
 */
class ScheduledOrderClosedBranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 22:00:00', 'UTC')); // late evening: a 10:00-18:00 branch is closed
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Store, 1: Branch, 2: Menu} */
    private function fixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        foreach (['allow_order_scheduling' => '1', 'scheduling_lead_minutes' => '15', 'scheduling_slot_interval' => '15', 'scheduling_max_days' => '3', 'scheduling_prep_minutes' => '20', 'timezone' => 'UTC'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $day = ['open' => '10:00', 'close' => '18:00', 'closed' => false];
        $branch = Branch::firstOrCreate(['store_id' => $store->id], ['name' => 'Main']);
        $branch->forceFill(['hours' => array_fill_keys(Branch::DAYS, $day), 'active' => true, 'accepting_orders' => true, 'timezone' => 'UTC'])->save();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wrap', 'price' => 7, 'active' => 1]);

        return [$store, $branch, $item];
    }

    private function order(Store $store, Branch $branch, Menu $item, array $extra = []): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Guest', 'ordering-type' => 'takeaway', 'phone-number' => '555-2000', 'pay_via' => 'cash', 'total' => '7.00',
            'branch_id' => $branch->id,
        ], $extra));
    }

    public function test_a_pre_order_is_accepted_while_the_branch_is_closed_now(): void
    {
        [$store, $branch, $item] = $this->fixture();

        $res = $this->order($store, $branch, $item, ['scheduled_at' => '2026-10-08 12:00'])->assertOk();

        $this->assertTrue($res->json('success'), (string) $res->getContent());
        $this->assertSame(1, Order::where('store_id', $store->id)->whereNotNull('scheduled_at')->count());
    }

    public function test_an_order_for_now_is_still_refused_while_the_branch_is_closed(): void
    {
        [$store, $branch, $item] = $this->fixture();

        $res = $this->order($store, $branch, $item)->assertOk();

        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('closed', (string) $res->json('message'));
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_a_pre_order_outside_the_branch_hours_is_refused(): void
    {
        [$store, $branch, $item] = $this->fixture();

        $res = $this->order($store, $branch, $item, ['scheduled_at' => '2026-10-08 03:00'])->assertOk();

        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('closed at that time', (string) $res->json('message'));
    }

    public function test_a_paused_or_switched_off_branch_refuses_pre_orders_too(): void
    {
        [$store, $branch, $item] = $this->fixture();

        $branch->forceFill(['accepting_orders' => false])->save();
        $this->assertFalse($this->order($store, $branch, $item, ['scheduled_at' => '2026-10-08 12:00'])->assertOk()->json('success'));

        $branch->forceFill(['accepting_orders' => true, 'active' => false])->save();
        $this->assertFalse($this->order($store, $branch, $item, ['scheduled_at' => '2026-10-08 12:00'])->assertOk()->json('success'));
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }
}
