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
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-11: POST {store}/booking-quote — the server quote of a booking selection (THEME-CONTRACT §5.2: the server is the
 * only source of totals), as the view model's `Quote`: the chosen services (1-4, public ids), the tax and the total the
 * booking will carry. The `book` theme's view model names it `endpoints.quote`; the storefront core JS calls it on
 * every change of the selection.
 *
 * Input: `lines[]` {item, qty?} (public ids of bookable services; one booking holds each service once), `order_type`
 * (`appointment` | `at_home`). Store-scoped: a service of another store is unquotable (422), never a price.
 */
class StorefrontBookingQuoteController extends Controller
{
    public function __invoke(Request $request, string $store, ViewModelBuilder $builder): JsonResponse
    {
        $store = Store::query()->idOrSlug($store)->first();
        if ($store === null || ! StorefrontAvailability::servable($store)) {
            return ApiError::status(404, 'Store not found.');
        }

        $data = validator($request->all(), [
            'lines' => ['required', 'array', 'min:1', 'max:4'],
            'lines.*.item' => ['required', 'string', 'uuid'],
            'lines.*.qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'order_type' => ['nullable', 'string', 'in:appointment,at_home'],
        ]);
        if ($data->fails()) {
            return ApiError::status(422, 'The booking is not valid.', $data->errors()->toArray());
        }
        $input = $data->validated();

        $quote = $builder->bookingCartQuote(new ViewModelRequest(
            store: $store,
            locale: app()->getLocale(),
            page: 'cart',
            orderType: $input['order_type'] ?? null,
            cart: array_map(fn (array $line) => ['item' => (string) $line['item'], 'qty' => 1], $input['lines']),
        ));
        if ($quote === null) {
            return ApiError::status(422, 'Some services cannot be booked together.');
        }

        return response()->json(['quote' => json_decode(ViewModelJson::encodeDef($quote, 'Quote'))], 200, ['Cache-Control' => 'no-store, private']);
    }
}
