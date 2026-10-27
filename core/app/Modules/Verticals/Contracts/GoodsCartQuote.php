<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Contracts;

use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Data\StorefrontQuote;

/**
 * P3-13: the server quote of this browser's goods cart at a store — what the goods checkout (`$booking` false) or
 * the laundry booking (`$booking` true) would charge for it now. Read only: no order, no cart row, no coupon claim.
 * Null when the cart is empty. Implemented by Verticals' GoodsQuoteService.
 */
interface GoodsCartQuote
{
    public function quote(Store $store, Request $request, ?string $mode, ?string $couponCode, bool $booking = false): ?StorefrontQuote;
}
