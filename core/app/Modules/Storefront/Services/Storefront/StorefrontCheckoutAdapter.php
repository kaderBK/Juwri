<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;

/**
 * P3-03: lets the storefront core JS place an order with the SAME cart it quoted — lines in public ids
 * (`lines[]` {item, qty, variant?, options[]?, note?}), the kit order-type key (`order_type`), the branch public
 * id (`branch`) and the tip in minor units (`tip_minor`) — through the one checkout (`send-order`) that has always
 * taken numeric ids and the core fulfilment mode. The request is rewritten in place to the legacy field names
 * (`items`, `ordering-type`, `branch_id`, `tip_amount`) and from there it is the ordinary checkout: the same
 * validation, pricing, idempotency (`client_order_id` UUID, J27-A2) and notifications. A request that names no
 * `lines` is left exactly as it was (every existing theme).
 *
 * REVIEW (Opus): this is the one place the storefront JS cart reaches the order path — idempotency (client UUID kept as
 * sent, fingerprint over the resolved items), tenancy (every id resolved inside `$store`) and money (`tip_minor` -> `tip_amount`).
 *
 * Strictly one store: a line, variant, option or branch that is not this store's makes the whole cart refused
 * (never a partial order, never another store's row).
 */
final class StorefrontCheckoutAdapter
{
    public function __construct(private readonly StorefrontOrdering $ordering) {}

    /** True when the request is a storefront-JS cart (public-id lines, no legacy `items`). */
    public function applies(Request $request): bool
    {
        return $request->has('lines') && ! $request->filled('items');
    }

    /** Rewrite the request, or answer with the refusal (the checkout's own `{success:false,message}` envelope). */
    public function adapt(Request $request, Store $store): ?JsonResponse
    {
        if (! $this->applies($request)) {
            return null;
        }

        $check = validator($request->all(), [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item' => ['required', 'string', 'uuid'],
            'lines.*.qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'lines.*.variant' => ['nullable', 'string', 'max:64'],
            'lines.*.options' => ['nullable', 'array', 'max:50'],
            'lines.*.options.*' => ['string', 'uuid'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'in:'.implode(',', OrderTypeKeys::TO_KIT)],
            'branch' => ['nullable', 'string', 'uuid'],
            'tip_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        if ($check->fails()) {
            return response()->json(['success' => false, 'message' => ___('Invalid order payload.')]);
        }

        $items = $this->ordering->legacyItems($store, array_values((array) $request->input('lines')));
        if ($items === null) {
            return response()->json(['success' => false, 'message' => ___('One or more selected items are not available.')]);
        }

        $merge = ['items' => json_encode($items)];
        if (! $request->filled('ordering-type') && $request->filled('order_type')) {
            $merge['ordering-type'] = OrderTypeKeys::toCore((string) $request->input('order_type'));
        }
        if ($request->filled('branch')) {
            $branch = Branch::query()->where('store_id', $store->id)->where('public_id', (string) $request->input('branch'))->first();
            if ($branch === null) {
                return response()->json(['success' => false, 'message' => ___('One or more selected items are not available for this branch.')]);
            }
            $merge['branch_id'] = (int) $branch->id;
        }
        if ($request->filled('tip_minor') && ! $request->filled('tip_amount')) {
            $merge['tip_amount'] = Money::majorFromMinor((int) $request->input('tip_minor'), strtoupper(store_currency((int) $store->id)));
        }
        $request->merge($merge);

        return null;
    }
}
