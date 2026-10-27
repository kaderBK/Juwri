<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use Juwri\Modules\Ordering\Contracts\OrderCancellations;

/** P6-205: binds the OrderCancellations contract to the shared cancel routine. */
final class SharedOrderCancellations implements OrderCancellations
{
    public function reverse(Order $order): void
    {
        OrderCancellation::reverse($order);
    }
}
