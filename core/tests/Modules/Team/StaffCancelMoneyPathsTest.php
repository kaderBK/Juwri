<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\TableCheck;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-205/206/207: staff PWA cancel runs the shared cancel routine (stock, gift card,
 * loyalty — once, even on retry/offline replay), a PAID cancel needs pos_refund, the
 * offline dedupe key is per store, and a cancelled order can never be revived.
 */
class StaffCancelMoneyPathsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{owner: User, r: Store, menu: Menu} */
    private function make(int $stock = 10): array
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
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
            'track_inventory' => 1, 'stock_qty' => $stock,
        ]);

        return compact('owner', 'r', 'menu');
    }

    private function card(Store $r, float $value): GiftCard
    {
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, $value, 'stripe');
        GiftCardService::activate($card, $txn);

        return $card->fresh();
    }

    private function order(User $owner, Store $r, Menu $menu, int $qty, ?string $code = null): Order
    {
        return OrderApiService::create($owner->id, $r->id, [
            'type' => 'takeaway', 'source' => 'api', 'gift_card_code' => $code,
            'items' => [['item_id' => $menu->id, 'quantity' => $qty]],
        ])->fresh();
    }

    private function staff(User $owner, Store $r, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'S', 'role' => $role,
            'email' => 's'.Str::random(6).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
    }

    /** @param array<string, string> $body */
    private function staffStatus(StaffMember $staff, Order $order, array $body): TestResponse
    {
        // Switching staff mid-test: start from a clean session/guard like a new device.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($staff, 'staff')->postJson("/staff-app/orders/{$order->id}/status", $body);
    }

    public function test_staff_cancel_restores_stock_and_gift_card_once_even_on_retry_and_replay(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $card = $this->card($r, 50);
        $order = $this->order($o, $r, $m, 2, $card->code);
        $this->assertSame(8, (int) $m->fresh()->stock_qty);
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);
        $staff = $this->staff($o, $r, 'manager');

        $this->staffStatus($staff, $order, ['status' => 'cancelled', 'client_op_id' => 'op-1'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $m->fresh()->stock_qty, 'stock restored');
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'gift card re-credited');

        // Offline replay (same op id) and a fresh retry (new op id) change nothing.
        $this->staffStatus($staff, $order, ['status' => 'cancelled', 'client_op_id' => 'op-1'])->assertOk()->assertJsonPath('duplicate', true);
        $this->staffStatus($staff, $order, ['status' => 'cancelled', 'client_op_id' => 'op-2'])->assertOk();
        $this->staffStatus($staff, $order, ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, (int) $m->fresh()->stock_qty, 'stock not restored twice');
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'card not double-credited');
    }

    public function test_staff_cancel_refuses_an_order_with_paid_split_shares(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $order = $this->order($o, $r, $m, 1);
        $check = TableCheck::create(['store_id' => $r->id, 'order_id' => $order->id, 'total' => 10, 'parts' => 2, 'status' => 'open']);
        CheckPayment::create(['table_check_id' => $check->id, 'share_no' => 1, 'amount' => 5, 'method' => 'cash', 'status' => 'paid', 'token' => Str::random(40)]);

        $this->staffStatus($this->staff($o, $r), $order, ['status' => 'cancelled'])->assertStatus(422);
        $this->assertNotSame('cancelled', $order->fresh()->status);
        $this->assertSame(9, (int) $m->fresh()->stock_qty, 'stock untouched');
    }

    public function test_cancelling_a_paid_order_needs_pos_refund(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $order = $this->order($o, $r, $m, 1);
        $order->forceFill(['is_paid' => 1])->save();

        $this->staffStatus($this->staff($o, $r, 'cashier'), $order, ['status' => 'cancelled', 'client_op_id' => 'op-paid'])->assertStatus(403);
        $this->assertNotSame('cancelled', $order->fresh()->status);
        $this->assertSame(9, (int) $m->fresh()->stock_qty);

        // The refused op id was not burned: a manager's later write still applies.
        $this->staffStatus($this->staff($o, $r, 'manager'), $order, ['status' => 'cancelled', 'client_op_id' => 'op-paid'])
            ->assertOk()->assertJsonMissingPath('duplicate');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $m->fresh()->stock_qty);
    }

    public function test_cashier_may_still_cancel_an_unpaid_order(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $order = $this->order($o, $r, $m, 1);

        $this->staffStatus($this->staff($o, $r, 'cashier'), $order, ['status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_same_offline_op_id_in_two_stores_both_apply(): void
    {
        ['owner' => $o1, 'r' => $r1, 'menu' => $m1] = $this->make(10);
        ['owner' => $o2, 'r' => $r2, 'menu' => $m2] = $this->make(10);
        $a = $this->order($o1, $r1, $m1, 1);
        $b = $this->order($o2, $r2, $m2, 1);

        $this->staffStatus($this->staff($o1, $r1), $a, ['status' => 'preparing', 'client_op_id' => 'shared-op'])->assertOk();
        $this->staffStatus($this->staff($o2, $r2), $b, ['status' => 'preparing', 'client_op_id' => 'shared-op'])
            ->assertOk()->assertJsonMissingPath('duplicate');

        $this->assertSame('preparing', $a->fresh()->status);
        $this->assertSame('preparing', $b->fresh()->status);
    }

    public function test_cancelled_order_cannot_be_revived_on_the_staff_pwa(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $order = $this->order($o, $r, $m, 1);
        $staff = $this->staff($o, $r);
        $this->staffStatus($staff, $order, ['status' => 'cancelled'])->assertOk();

        foreach (['completed', 'preparing', 'new'] as $to) {
            $this->staffStatus($staff, $order, ['status' => $to])->assertStatus(422);
            $this->assertSame('cancelled', $order->fresh()->status, "cancelled → {$to} refused");
        }
        $this->assertSame(10, (int) $m->fresh()->stock_qty);
    }

    public function test_cancelled_order_cannot_be_revived_on_the_dashboard(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $order = $this->order($o, $r, $m, 1);
        $this->actingAs($o)->postJson(route('stores.updateOrderStatus', $order), ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, (int) $m->fresh()->stock_qty);

        foreach (['completed', 'preparing', 'pending'] as $to) {
            $this->actingAs($o)->postJson(route('stores.updateOrderStatus', $order), ['status' => $to])->assertStatus(422);
            $this->assertSame('cancelled', $order->fresh()->status, "cancelled → {$to} refused");
        }

        $this->actingAs($o)->postJson(route('stores.completeOrder', $order))->assertStatus(422);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_complete_still_works_for_a_live_order(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make(10);
        $order = $this->order($o, $r, $m, 1);

        $this->actingAs($o)->postJson(route('stores.completeOrder', $order))->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }
}
