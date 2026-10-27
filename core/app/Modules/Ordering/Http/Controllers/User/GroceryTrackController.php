<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPickingException;

/**
 * P3-09 (basket §10 "Changes to review"): the shopper keeps or refuses a replacement the picker proposed, from the
 * tracking page. Anonymous and scoped by the order's track token; the line must belong to that order (another order's
 * line id is refused like a missing one). Only while the order is being picked — after that the order preference has
 * already decided.
 */
class GroceryTrackController extends Controller
{
    public function answer(Request $request, string $token, GroceryPicking $picking): JsonResponse
    {
        $data = $request->validate([
            'item' => ['required_without:line', 'nullable', 'integer', 'min:1'],
            'line' => ['required_without:item', 'nullable', 'string', 'regex:/^ln_[1-9][0-9]{0,3}$/'],
            'answer' => ['required', 'in:kept,refused'],
        ]);
        $order = Order::query()->where('track_token', $token)->first();
        if ($order === null || $token === '') {
            return response()->json(['success' => false, 'message' => ___('Order not found.')], 404);
        }
        $itemId = (int) ($data['item'] ?? 0);
        if (! empty($data['line'])) {
            // the View Model's line id (`ln_N` = the Nth line of THIS order); a row id never leaves the server
            $itemId = (int) ($order->items()->orderBy('id')->pluck('id')->values()->get(((int) substr((string) $data['line'], 3)) - 1) ?? 0);
            if ($itemId === 0) {
                return response()->json(['success' => false, 'message' => ___('Order not found.')], 404);
            }
        }
        try {
            $picking->answer($order, $itemId, (string) $data['answer']);
        } catch (GroceryPickingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }
        $order->refresh()->unsetRelation('items');
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));

        return response()->json([
            'success' => true,
            'answer' => $data['answer'],
            'estimated_total' => $currency.' '.number_format((float) $order->computeMoney()['grand'], Currencies::exponent($currency)),
        ]);
    }
}
