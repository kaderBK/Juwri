<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-272: a POS receipt reprint is clearly marked as a copy, a refunded order
 * lists its refunds with the net actually paid, and a single refund prints its
 * own refund receipt numbered from the adjustment. A foreign adjustment id
 * (another order / store) must never leak onto this order's receipt.
 */
class PosReceiptCopyTest extends TestCase
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

        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
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

    private function staff(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
    }

    private function order(Store $r, float $price = 100): Order
    {
        $staff = $this->staff($r);
        $item = $this->item($r, $price);

        return PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
    }

    private function paidOrder(Store $r, float $price = 100): Order
    {
        $order = $this->order($r, $price);

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $r->id]), ['method' => 'cash', 'tendered' => $price])
            ->assertOk();

        return $order->refresh();
    }

    private function refund(Store $r, Order $order, float $amount): int
    {
        return (int) $this->actingAs($r->user, 'web')
            ->postJson(route('pos.orders.refund', ['id' => $order->id, 'store' => $r->id]), [
                'amount' => $amount, 'method' => 'cash', 'reason' => 'customer request',
            ])
            ->assertStatus(201)
            ->json('adjustment_id');
    }

    private function receiptUrl(Store $r, Order $order, array $query = []): string
    {
        return route('pos.orders.receipt', array_merge(['id' => $order->id, 'store' => $r->id], $query));
    }

    public function test_second_print_is_marked_as_copy(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 100);
        $url = $this->receiptUrl($r, $order, ['lang' => 'en']);

        $first = $this->actingAs($r->user, 'web')->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('COPY', $first, 'The original print must not be marked as a copy.');

        $second = $this->actingAs($r->user, 'web')->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('COPY', $second, 'A reprint must be marked as a copy.');
    }

    public function test_second_print_copy_is_localised_in_arabic(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 100);

        // First print (default language) consumes the original.
        $this->actingAs($r->user, 'web')
            ->get($this->receiptUrl($r, $order, ['lang' => 'en']))->assertOk();

        $ar = $this->actingAs($r->user, 'web')
            ->get($this->receiptUrl($r, $order, ['lang' => 'ar']))->assertOk()->getContent();

        $this->assertStringContainsString('نسخة', $ar, 'The Arabic reprint must carry the Arabic copy mark.');
    }

    public function test_refunded_order_lists_refund_and_net_paid(): void
    {
        $r = $this->storeFixture();
        $order = $this->paidOrder($r, 100);
        $this->refund($r, $order, 15);

        $html = $this->actingAs($r->user, 'web')
            ->get($this->receiptUrl($r, $order, ['lang' => 'en']))->assertOk()->getContent();

        $this->assertStringContainsString('Net paid', $html);
        $this->assertStringContainsString('15.00', $html, 'The refund amount must appear as its own line.');
        $this->assertStringContainsString('85.00', $html, 'Net paid must be the total minus the refunds (100 - 15).');
    }

    public function test_refund_receipt_numbers_from_the_adjustment(): void
    {
        $r = $this->storeFixture();
        $order = $this->paidOrder($r, 100);
        $adjustmentId = $this->refund($r, $order, 15);

        $html = $this->actingAs($r->user, 'web')
            ->get($this->receiptUrl($r, $order, ['lang' => 'en', 'adjustment' => $adjustmentId]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Refund receipt #'.$adjustmentId, $html);
    }

    public function test_foreign_adjustment_id_is_not_found(): void
    {
        $mine = $this->storeFixture();
        $myOrder = $this->paidOrder($mine, 100);

        // A refund that belongs to a different store must never render on my order.
        $other = $this->storeFixture();
        $otherOrder = $this->paidOrder($other, 100);
        $foreignAdjustment = $this->refund($other, $otherOrder, 10);

        $this->actingAs($mine->user, 'web')
            ->get($this->receiptUrl($mine, $myOrder, ['adjustment' => $foreignAdjustment]))
            ->assertNotFound();
    }
}
