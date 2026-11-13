<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\StaffMember;
use App\Models\WebhookEndpoint;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-330: the drawer counts only the cash share of a bill part-paid with store credit / gift card / platform wallet.
 * P6-332: a second close or handoff of the same drawer never overwrites the first count or opens a second drawer.
 * P6-342: a second open of the same (store, branch) drawer is refused, two branches of one store
 * both open, and a close frees the drawer for a fresh open.
 */
class PosDrawerTenderAndCloseRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    /** A 40.00 POS order with $tenders (wallet_applied / gift_card_applied / mk_wallet_applied) already applied. */
    private function order(Store $r, StaffMember $staff, array $tenders = []): Order
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 40, 'active' => 1]);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $this->assertEquals(40, (float) $order->fresh()->total);
        if ($tenders) {
            $order->forceFill($tenders)->save();
        }

        return $order->fresh();
    }

    public function test_cash_change_is_on_what_is_due_after_store_credit(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        PosService::openShift($r, $staff, null, 0);

        $exact = PosService::settle($this->order($r, $staff, ['wallet_applied' => 15]), 'cash', 25.0);
        $this->assertEquals(0, (float) $exact->change_due, '25 cash on a 40 bill with 15 credit is exact');

        $over = PosService::settle($this->order($r, $staff, ['wallet_applied' => 15]), 'cash', 30.0);
        $this->assertEquals(5, (float) $over->change_due);
    }

    public function test_store_credit_bill_closes_balanced_with_its_own_z_line(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 100);

        PosService::settle($this->order($r, $staff, ['wallet_applied' => 15]), 'cash', 25.0);

        $z = PosService::closeShift($shift->fresh(), 125.0); // float 100 + 25 cash actually taken
        $this->assertEquals(125, $z['expected_cash']);
        $this->assertEquals(0, $z['over_short']);
        $this->assertEquals(25, $z['sales_by_method']['cash']);
        $this->assertEquals(15, $z['sales_by_method']['store_credit']);
        $this->assertSame('Store credit', $z['method_labels']['store_credit']);
        $this->assertEquals(40, array_sum($z['sales_by_method']), 'the lines still add up to gross sales');
    }

    public function test_gift_card_and_platform_wallet_shares_stay_out_of_the_drawer(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 0);

        PosService::settle($this->order($r, $staff, ['gift_card_applied' => 10, 'mk_wallet_applied' => 5]), 'cash', 25.0);
        PosService::settle($this->order($r, $staff, ['gift_card_applied' => 30]), 'card');

        $z = PosService::zReport($shift->fresh());
        $this->assertEquals(25, $z['expected_cash']);
        $this->assertEquals(25, $z['sales_by_method']['cash']);
        $this->assertEquals(10, $z['sales_by_method']['card']);
        $this->assertEquals(40, $z['sales_by_method']['gift_card']);
        $this->assertEquals(5, $z['sales_by_method']['platform_wallet']);
    }

    public function test_a_bill_fully_covered_by_store_credit_adds_no_cash(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 50);

        $o = PosService::settle($this->order($r, $staff, ['wallet_applied' => 40]), 'cash', 0.0);
        $this->assertEquals(0, (float) $o->change_due);

        $z = PosService::closeShift($shift->fresh(), 50.0);
        $this->assertEquals(0, $z['over_short']);
    }

    /** Two closes of the same shift: the second (holding a stale "open" copy) must not overwrite the first count. */
    public function test_second_close_keeps_the_first_count_and_fires_once(): void
    {
        Bus::fake();
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        WebhookEndpoint::create(['user_id' => $r->user_id, 'url' => 'https://x.test/hook', 'secret' => 's3cr3t', 'events' => ['shift.closed'], 'active' => true]);
        $shift = PosService::openShift($r, $staff, null, 100);

        $managerA = $shift->fresh();
        $managerB = $shift->fresh(); // both loaded while the shift was still open

        $first = PosService::closeShift($managerA, 100.0);
        $this->travel(10)->seconds();
        $second = PosService::closeShift($managerB, 90.0);

        $row = $shift->fresh();
        $this->assertEquals(100, (float) $row->counted_cash);
        $this->assertEquals(0, (float) $row->over_short);
        $this->assertEquals($first['closed_at']?->toDateTimeString(), $row->closed_at?->toDateTimeString());
        $this->assertTrue($second['already_closed']);
        $this->assertEquals(100, $second['counted_cash']);
        $this->assertSame('closed', $managerB->status, 'the stale copy is refreshed to the stored close');
        Bus::assertDispatchedTimes(DeliverWebhookJob::class, 1);
    }

    public function test_close_endpoint_refuses_a_closed_shift_and_returns_the_first_report(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 20);
        PosService::closeShift($shift->fresh(), 20.0);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.shift.close', ['store' => $r->id, 'id' => $shift->id]), ['counted_cash' => 5])
            ->assertStatus(422)
            ->assertJsonPath('zreport.counted_cash', 20);
        $this->assertEquals(20, (float) $shift->fresh()->counted_cash);
    }

    /** Two handoff posts (double tap, no key): one handoff, one open drawer, the first count stands. */
    public function test_double_handoff_without_key_opens_one_drawer(): void
    {
        $r = $this->storeFixture();
        $from = $this->staff($r);
        $to = $this->staff($r, 'cashier');
        $shift = PosService::openShift($r, $from, null, 100);

        $a = PosService::handoffShift($r, $to, 100.0, null);
        $b = PosService::handoffShift($r, $to, 80.0, null);

        $this->assertSame($a['shift']->id, $b['shift']->id);
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertEquals(100, (float) $shift->fresh()->counted_cash);
        $this->assertEquals(100, (float) $a['shift']->fresh()->opening_float);
    }

    public function test_double_handoff_with_same_key_is_one_handoff(): void
    {
        $r = $this->storeFixture();
        $from = $this->staff($r);
        $to = $this->staff($r, 'cashier');
        PosService::openShift($r, $from, null, 100);

        $a = PosService::handoffShift($r, $to, 100.0, null, 'k-1');
        $b = PosService::handoffShift($r, $to, 100.0, null, 'k-1');

        $this->assertSame($a['shift']->id, $b['shift']->id);
        $this->assertSame(2, PosShift::where('store_id', $r->id)->count());
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
    }

    /** A close racing a handoff: whichever lands second sees the closed row and leaves it alone. */
    public function test_close_after_handoff_does_not_touch_the_handed_over_shift(): void
    {
        $r = $this->storeFixture();
        $from = $this->staff($r);
        $to = $this->staff($r, 'cashier');
        $shift = PosService::openShift($r, $from, null, 100);
        $stale = $shift->fresh();

        $h = PosService::handoffShift($r, $to, 100.0, null);
        $z = PosService::closeShift($stale, 70.0);

        $this->assertTrue($z['already_closed']);
        $this->assertEquals(100, (float) $shift->fresh()->counted_cash);
        $this->assertSame('open', $h['shift']->fresh()->status);
    }

    /** A real second handoff (a different cashier) chains on the new shift — still one open drawer. */
    public function test_chained_handoff_to_another_cashier_still_leaves_one_open_shift(): void
    {
        $r = $this->storeFixture();
        $a = $this->staff($r);
        $b = $this->staff($r, 'cashier');
        $c = $this->staff($r, 'cashier');
        PosService::openShift($r, $a, null, 100);

        $first = PosService::handoffShift($r, $b, 100.0, null);
        $second = PosService::handoffShift($r, $c, 100.0, null);

        $this->assertSame($first['shift']->id, $second['shift']->handoff_from_shift_id);
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
    }

    /** P6-342: a second open of the same (store, branch=null) drawer throws and never opens a second shift. */
    public function test_second_open_of_the_same_drawer_is_refused(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        PosService::openShift($r, $staff, null, 0);

        try {
            PosService::openShift($r, $staff, null, 0);
            $this->fail('A second open should throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('A shift is already open for this drawer.', $e->getMessage());
        }

        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(1, PosShift::where('store_id', $r->id)->count());
    }

    /** P6-342: the HTTP open endpoint answers 201 then 422 for a double post, one open shift remains. */
    public function test_http_open_twice_returns_201_then_422_with_one_open_shift(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.shift.open', ['store' => $r->id]), ['opening_float' => 100])
            ->assertStatus(201);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.shift.open', ['store' => $r->id]), ['opening_float' => 100])
            ->assertStatus(422);

        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(1, PosShift::where('store_id', $r->id)->count());
    }

    /** P6-342: two branches of one store are separate drawers and both open at once. */
    public function test_two_branches_of_one_store_both_open(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'active' => 1, 'position' => 1]);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B', 'active' => 1, 'position' => 2]);

        $first = PosService::openShift($r, $staff, $a->id, 50);
        $second = PosService::openShift($r, $staff, $b->id, 50);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('open', $first->fresh()->status);
        $this->assertSame('open', $second->fresh()->status);
        $this->assertSame(2, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
    }

    /** P6-342: closing the drawer frees it, so a fresh open succeeds. */
    public function test_reopen_after_close_succeeds(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 50);
        PosService::closeShift($shift->fresh(), 50.0);

        $new = PosService::openShift($r, $staff, null, 20);

        $this->assertNotSame($shift->id, $new->id);
        $this->assertSame('open', $new->status);
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'closed')->count());
    }
}
