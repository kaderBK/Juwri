<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use App\Models\Order;

/**
 * P6-205: the shared cancel routine as other modules (POS reject) may call it, without reaching into Ordering's
 * services. Gives back stock, folio charge, gift-card, wallet and loyalty tender; each step is idempotent.
 */
interface OrderCancellations
{
    public function reverse(Order $order): void;
}
