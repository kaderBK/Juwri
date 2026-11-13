<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;

/**
 * P6-255: the staff + owner "new order" alert for a storefront order, in the store's currency.
 * Cash/COD orders fire it at placement; pay-online orders fire it once, when the payment is confirmed.
 */
class NewOrderAlert
{
    public static function send(Order $order): void
    {
        if ($order->isPendingSchedule()) {
            return; // the release tick sends it at fire time
        }
        $label = $order->table_number ? ('Table '.$order->table_number) : ucfirst((string) $order->type);
        $body = trim($label.' · '.store_price_symbol((float) $order->total, (int) $order->store_id));
        try {
            app(MessageQueue::class)->staffPush((int) $order->store_id, 'New order #'.$order->id, $body, '/staff-app');
        } catch (\Throwable $e) {
        }
        try {
            app(MobilePush::class)->newOrder($order, $body);
        } catch (\Throwable $e) {
        }
    }
}
