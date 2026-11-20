<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * AUDIT-authz #1: the staff-token API status write enforces the same rules as the
 * staff PWA — a paid cancel needs pos_refund, cancelled is terminal, a cancel runs
 * the reversal (stock back), and a branch-pinned staff member stays in their branch.
 */
class ApiStaffOrderStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{owner: User, r: Store, menu: Menu} */
    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
            'track_inventory' => 1, 'stock_qty' => 10,
        ]);

        return compact('owner', 'r', 'menu');
    }

    private function order(User $owner, Store $r, Menu $menu): Order
    {
        return OrderApiService::create($owner->id, $r->id, [
            'type' => 'takeaway', 'source' => 'api',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ])->fresh();
    }

    /** @param array<string, mixed> $extra */
    private function token(User $owner, Store $r, string $role, array $extra = []): string
    {
        $s = StaffMember::create(array_merge([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'S', 'role' => $role,
            'email' => 's'.Str::lower(Str::random(6)).'@ex.com', 'password' => Hash::make('secret123'), 'active' => 1,
        ], $extra));

        return (string) $this->postJson(route('api.v1.staff.login', $r->id), ['email' => $s->email, 'password' => 'secret123'])
            ->assertOk()->json('token');
    }

    public function test_cashier_token_cannot_cancel_a_paid_order(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $order = $this->order($o, $r, $m);
        $order->forceFill(['is_paid' => 1])->save();

        $this->withToken($this->token($o, $r, 'cashier'))
            ->putJson(route('api.v1.staff.orders.status', $order->id), ['status' => 'cancelled'])
            ->assertForbidden();
        $this->assertNotSame('cancelled', $order->fresh()->status);
        $this->assertSame(9, (int) $m->fresh()->stock_qty);
    }

    public function test_api_cancel_runs_the_reversal_and_cancelled_is_terminal(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $order = $this->order($o, $r, $m);
        $this->assertSame(9, (int) $m->fresh()->stock_qty);
        $token = $this->token($o, $r, 'manager');

        $this->withToken($token)
            ->putJson(route('api.v1.staff.orders.status', $order->id), ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(10, (int) $m->fresh()->stock_qty, 'stock restored');

        $this->withToken($token)
            ->putJson(route('api.v1.staff.orders.status', $order->id), ['status' => 'completed'])
            ->assertStatus(422);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_branch_pinned_staff_cannot_touch_or_list_another_branch(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A']);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B']);
        $mine = $this->order($o, $r, $m);
        $mine->forceFill(['branch_id' => $b->id])->save();
        $theirs = $this->order($o, $r, $m);
        $theirs->forceFill(['branch_id' => $a->id])->save();
        $token = $this->token($o, $r, 'manager', ['branch_id' => $b->id]);

        $this->withToken($token)
            ->putJson(route('api.v1.staff.orders.status', $theirs->id), ['status' => 'preparing'])
            ->assertNotFound();
        $this->assertNotSame('preparing', $theirs->fresh()->status);

        $ids = collect($this->withToken($token)->getJson(route('api.v1.staff.orders'))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        $this->withToken($token)
            ->putJson(route('api.v1.staff.orders.status', $mine->id), ['status' => 'preparing'])
            ->assertOk();
    }

    /** SEC-1120 review SHOULD 3: KDS bump and recall by item id stay in the pinned staff member's branch too. */
    public function test_branch_pinned_staff_cannot_bump_or_recall_another_branchs_kds_item(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A']);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B']);
        $mine = $this->order($o, $r, $m);
        $mine->forceFill(['branch_id' => $b->id])->save();
        $theirs = $this->order($o, $r, $m);
        $theirs->forceFill(['branch_id' => $a->id])->save();
        $theirItem = $theirs->items()->firstOrFail();
        $before = $theirItem->status;
        $token = $this->token($o, $r, 'manager', ['branch_id' => $b->id]);

        $this->withToken($token)->putJson(route('api.v1.staff.kds.bump', $theirItem->id))->assertNotFound();
        $this->withToken($token)->putJson(route('api.v1.staff.kds.recall', $theirItem->id))->assertNotFound();
        $this->assertSame($before, $theirItem->fresh()->status);

        $this->withToken($token)->putJson(route('api.v1.staff.kds.bump', $mine->items()->firstOrFail()->id))->assertOk();
    }
}
