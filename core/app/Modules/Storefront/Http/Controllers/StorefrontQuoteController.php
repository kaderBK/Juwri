<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\OrderPresenter;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-02: POST {store}/quote — the server quote of a storefront cart (THEME-CONTRACT §5.2: the server is the only
 * source of totals), as the view model's `Quote`: lines, rows, total, coupon. The view model names this endpoint
 * (`endpoints.quote`); the storefront JS (P3-03) calls it on every cart change and just before placing.
 *
 * Input: `lines[]` {item, qty, variant?, options[]?, note?} in public ids, `order_type` (a kit order-type key),
 * `coupon`, `tip_minor`, `branch` (public id). Store-scoped: a line naming another store's item, variant or option
 * makes the cart unquotable (422), never a price. Errors are ApiError JSON.
 *
 * P3-13: a legacy (D11 premium) theme posts the cart it sends to the checkout instead — `items` (JSON, numeric ids),
 * `combos`, `ordering-type`, `coupon_code`, `tip_amount`, `branch_id` — and gets the same Quote rows and total
 * (no `lines`): read exactly as `send-order` reads them, so the total the theme shows is the order's.
 */
class StorefrontQuoteController extends Controller
{
    public function __invoke(Request $request, string $store, ViewModelBuilder $builder): JsonResponse
    {
        // The view model names the store by its slug (a public id); an old numeric link still resolves.
        $store = Store::query()->idOrSlug($store)->first();
        if ($store === null || ! StorefrontAvailability::servable($store)) {
            return ApiError::status(404, 'Store not found.');
        }

        if (! $request->has('lines') && $request->has('items')) {
            return $this->legacy($request, $store);
        }

        $data = validator($request->all(), [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item' => ['required', 'string', 'uuid'],
            'lines.*.qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'lines.*.variant' => ['nullable', 'string', 'max:64'],
            'lines.*.options' => ['nullable', 'array', 'max:50'],
            'lines.*.options.*' => ['string', 'uuid'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'in:'.implode(',', OrderTypeKeys::TO_KIT)],
            'coupon' => ['nullable', 'string', 'max:64'],
            'tip_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'branch' => ['nullable', 'string', 'uuid'],
        ]);
        if ($data->fails()) {
            return ApiError::status(422, 'The cart is not valid.', $data->errors()->toArray());
        }
        $input = $data->validated();

        $branch = isset($input['branch'])
            ? Branch::query()->where('store_id', $store->id)->where('public_id', $input['branch'])->where('active', true)->first()
            : null;

        $quote = $builder->cartQuote(new ViewModelRequest(
            store: $store,
            locale: app()->getLocale(),
            page: 'cart',
            branch: $branch,
            orderType: isset($input['order_type']) ? OrderTypeKeys::toCore((string) $input['order_type']) : null,
            cart: array_map(fn (array $line) => [
                'item' => (string) $line['item'],
                'qty' => (int) ($line['qty'] ?? 1),
                'variant' => $line['variant'] ?? null,
                'options' => array_values(array_map('strval', $line['options'] ?? [])),
                'note' => $line['note'] ?? null,
            ], $input['lines']),
            coupon: $input['coupon'] ?? null,
            tipMinor: (int) ($input['tip_minor'] ?? 0),
        ));

        if ($quote === null) {
            return ApiError::status(422, 'Some items in the cart are no longer available.');
        }

        return response()->json(['quote' => json_decode(ViewModelJson::encodeDef($quote, 'Quote'))], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** P3-13: the quote of a legacy theme cart (the `send-order` fields). */
    private function legacy(Request $request, Store $store): JsonResponse
    {
        $data = validator($request->all(), [
            'items' => ['required'],
            'combos' => ['nullable'],
            'ordering-type' => ['nullable', 'string', 'in:on-table,takeaway,delivery,room_service'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($data->fails()) {
            return ApiError::status(422, 'The cart is not valid.', $data->errors()->toArray());
        }

        $items = $this->decoded($request->input('items'), false);
        $combos = $this->decoded($request->input('combos'), true);
        if ($items === null || $combos === null || count($items) > 100 || count($combos) > 100) {
            return ApiError::status(422, 'The cart is not valid.');
        }

        $branch = $request->filled('branch_id')
            ? Branch::query()->where('store_id', $store->id)->whereKey((int) $request->input('branch_id'))->first()
            : null;
        $type = $request->filled('ordering-type') ? (string) $request->input('ordering-type') : null;

        $quote = app(StorefrontOrdering::class)->quoteLegacy(
            $store, $items, $combos, $type, $branch,
            $request->filled('coupon_code') ? (string) $request->input('coupon_code') : null,
            (float) $request->input('tip_amount', 0),
        );
        if ($quote === null) {
            return ApiError::status(422, 'Some items in the cart are no longer available.');
        }

        $money = new MoneyFormat($store);
        $presenter = new OrderPresenter($store, $money, (string) ($branch?->timezone ?: store_tz((int) $store->id)));

        return response()->json(['quote' => $presenter->totals($quote, ___('Total'))], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * A posted JSON field (string or already-decoded array) as a list; null when it is not one. `items` decode to
     * objects (the checkout's own shape), `combos` to arrays (ComboService's). Empty / absent = [].
     *
     * @return array<int|string, mixed>|null
     */
    private function decoded(mixed $raw, bool $assoc): ?array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $value = is_string($raw) ? json_decode($raw, $assoc) : json_decode((string) json_encode($raw), $assoc);

        return is_array($value) ? $value : null;
    }
}
