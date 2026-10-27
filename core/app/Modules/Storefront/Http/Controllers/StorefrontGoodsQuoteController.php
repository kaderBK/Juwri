<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\OrderPresenter;
use Juwri\Modules\Verticals\Contracts\GoodsCartQuote;

/**
 * P3-13 (THEME-CONTRACT §5.2): POST {store}/cart-quote — the server quote of the shopper's goods cart (the cart the
 * goods checkout or the laundry booking will place): rows, total and coupon, as the legacy quote answers
 * (`{quote: {rows, total, total_label, coupon?}}`). Input: `type` (fulfilment mode), `coupon_code`, `flow`
 * (`goods` = storefront.checkout.place, `booking` = laundry.book.place). Read-only: no order, no coupon claim.
 */
class StorefrontGoodsQuoteController extends Controller
{
    public function __invoke(Request $request, string $store, GoodsCartQuote $quotes): JsonResponse
    {
        $store = Store::query()->idOrSlug($store)->first();
        if ($store === null || ! StorefrontAvailability::servable($store)) {
            return ApiError::status(404, 'Store not found.');
        }

        $data = validator($request->all(), [
            'type' => ['nullable', 'string', 'max:32'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'flow' => ['nullable', 'string', 'in:goods,booking'],
        ]);
        if ($data->fails()) {
            return ApiError::status(422, 'The cart is not valid.', $data->errors()->toArray());
        }

        $quote = $quotes->quote(
            $store,
            $request,
            $request->filled('type') ? (string) $request->input('type') : null,
            $request->filled('coupon_code') ? (string) $request->input('coupon_code') : null,
            $request->input('flow') === 'booking',
        );
        if ($quote === null) {
            return ApiError::status(422, 'The cart is empty.');
        }

        $presenter = new OrderPresenter($store, new MoneyFormat($store), store_tz((int) $store->id));

        return response()->json(['quote' => $presenter->totals($quote, ___('Total'))], 200, ['Cache-Control' => 'no-store, private']);
    }
}
