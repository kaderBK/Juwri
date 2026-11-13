<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coordinator review of lane A: the refund cap and the refund row must be read and written under the order's row
 * lock, so two concurrent refunds of one order cannot both pass the cap. Behavioural check (second refund sees the
 * first's committed row) on every driver; the lock-before-cap order is asserted from the query log on MySQL / PostgreSQL
 * (SQLite has no row locks and drops FOR UPDATE).
 */
class OwnerRefundLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: Order, 1: User} an order with 100.00 amount due */
    private function order(bool $customer = false): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111', 'title' => 'R', 'sub_title' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'X', 'price' => 100, 'active' => '1']);
        $c = $customer ? Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]) : null;
        $order = Order::create(['store_id' => $store->id, 'customer_id' => $c?->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1, 'payment_method' => 'card']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);

        return [$order->fresh(), $owner];
    }

    private function refundRows(Order $order): int
    {
        return OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->count();
    }

    public function test_the_second_refund_sees_the_first_ones_committed_row_and_is_refused_above_the_cap(): void
    {
        [$order, $owner] = $this->order();
        $url = route('stores.refundOrderToOriginal', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => 70])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 70])->assertStatus(422);

        $this->assertSame(1, $this->refundRows($order), 'the refused refund must leave no row');
        $this->actingAs($owner)->postJson($url, ['amount' => 30])->assertOk();
        $this->assertSame(2, $this->refundRows($order));
    }

    public function test_the_same_for_store_credit(): void
    {
        [$order, $owner] = $this->order(true);
        $url = route('stores.refundOrderToCredit', $order->id);

        $this->actingAs($owner)->postJson($url, ['amount' => 70])->assertOk();
        $this->actingAs($owner)->postJson($url, ['amount' => 70])->assertStatus(422);
        $this->assertSame(1, $this->refundRows($order));
    }

    #[DataProvider('paths')]
    public function test_the_order_row_is_locked_before_the_cap_is_read_and_the_refund_row_written(string $route, bool $customer): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('SQLite has no row locks; run on MySQL / PostgreSQL (dbstep).');
        }
        [$order, $owner] = $this->order($customer);

        DB::enableQueryLog();
        $this->actingAs($owner)->postJson(route($route, $order->id), ['amount' => 10])->assertOk();
        $sql = array_map(fn ($q) => strtolower($q['query']), DB::getQueryLog());

        $first = fn (string $re) => collect($sql)->search(fn ($q) => (bool) preg_match($re, $q));
        $lock = $first('/from [`"]?orders[`"]? .*for update/s');
        $cap = $first('/sum\(.*order_adjustments|from [`"]?order_adjustments[`"]? .*sum|order_adjustments.*sum\(/s');
        $insert = $first('/insert into [`"]?order_adjustments[`"]?/');

        $this->assertNotFalse($lock, 'the order row must be read FOR UPDATE');
        $this->assertNotFalse($cap, 'the cap query (sum of earlier refunds) must run');
        $this->assertNotFalse($insert, 'the refund row must be inserted');
        $this->assertLessThan($cap, $lock, 'the lock must be taken before the cap is read');
        $this->assertLessThan($insert, $cap, 'the cap must be read before the refund row is inserted');
    }

    public static function paths(): array
    {
        return ['original payment' => ['stores.refundOrderToOriginal', false], 'store credit' => ['stores.refundOrderToCredit', true]];
    }
}
