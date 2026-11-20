<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Modules\Menu\Models\Menu;

/**
 * SEC-1119: an order's total derives from its lines, and an owner refund is capped against it — an order with no
 * priced lines has nothing to refund. Refund fixtures that only set the grand_total column get one priced line here.
 */
final class PricedOrderLine
{
    public static function add(Order $order, float $price): Order
    {
        $menu = Menu::create(['store_id' => $order->store_id, 'name' => 'Line', 'price' => $price, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);

        return $order->fresh();
    }
}
