<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-01 (found by the fiscal journey's receipt check): the POS paper receipt priced every line at TODAY's menu price and
 * modifier price, and the thermal receipt the modifiers — while the order's total, its tax and its e-invoice use the
 * prices it was sold at (the happy-hour `unit_price` snapshot, the modifier `price` snapshot). After a price change an
 * old receipt's lines and subtotal no longer added up to its own total. Sold 2 × 6.00 + 2 × 1.00 (menu now 10.00,
 * modifier now 3.00) = 14.00.
 */
class ReceiptSnapshotPricesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function order(): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['restaurant_taxx_charge' => 0, 'currency_code' => 'SAR', 'currency_sign' => 'SAR'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mojito', 'price' => 10, 'active' => '1']);
        $extra = MenuExtra::create(['store_id' => $store->id, 'title' => 'Mint', 'price' => 3]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 6]);
        OrderItemExtra::create(['order_item_id' => $line->id, 'extra_id' => $extra->id, 'price' => 1]);

        return [$store, $order->fresh()->load('items.menu', 'items.itemExtras.extra', 'items.variant')];
    }

    public function test_the_paper_receipt_prints_the_prices_the_order_charged(): void
    {
        [$store, $order] = $this->order();
        $this->assertEqualsWithDelta(14.00, $order->computeMoney()['grand'], 1e-9);

        $html = view('staff.pos.receipt', ['order' => $order, 'store' => $store, 'options' => store_options($store->id), 'station' => ''])->render();
        $this->assertStringContainsString('12.00', $html, 'line: 2 × 6.00 (before: 20.00 at today\'s menu price)');
        $this->assertStringNotContainsString('20.00', $html);
        $this->assertStringContainsString('2.00', $html, 'modifier: 2 × 1.00 (before: 6.00)');
        $this->assertStringNotContainsString('6.00SAR', $html);
        $this->assertMatchesRegularExpression('/Subtotal<\/td>\s*<td[^>]*>[^<]*14\.00/', $html);
    }

    public function test_the_thermal_receipt_prints_the_modifier_snapshot(): void
    {
        [, $order] = $this->order();
        $escpos = (new EscPosRenderer(48))->renderReceipt($order);
        $this->assertMatchesRegularExpression('/\+ Mint\s+[^\n]*2\.00/', $escpos, 'before: 6.00 at today\'s modifier price');
        $this->assertMatchesRegularExpression('/Subtotal\s+[^\n]*14\.00/', $escpos);
    }
}
