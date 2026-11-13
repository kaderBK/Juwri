<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Services\Payments\LatePayments;

/**
 * P3-06b (kit/api README §8, API-21 / API-27 / API-28 / API-32): the owner's dashboard entry for late payments of
 * headless hosted orders. A payment that arrives after the 15-minute window leaves the order `cancelled` with the
 * money `received_late`; the owner accepts it (the stock and slot are taken again when free, else the order waits as
 * `pending_approval`) or refunds it (bypassing the D7 refund switches). An accepted card hold that waits shows when
 * its authorisation lapses; the sweep voids it then.
 *
 * Tenant scoping: the list reads the current store of the dashboard actor; an action resolves the order through
 * that actor's stores (a foreign order id is a 404, never a peek).
 */
class LatePaymentsController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request): View
    {
        $rid = $this->currentRestaurantId($request);

        $orders = Order::query()
            ->where('store_id', $rid)
            ->whereNotNull('late_payment_status')
            ->orderByRaw("case when late_payment_status = 'received_late' then 0 when late_payment_status = 'accepted' and status = ? then 1 else 2 end", [Order::STATUS_PENDING_APPROVAL])
            ->orderByDesc('late_payment_at')
            ->limit(100)
            ->get();

        return view('owner.orders.late-payments', [
            'rid' => $rid,
            'orders' => $orders,
            'stores' => Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function accept(Order $order, LatePayments $late): RedirectResponse
    {
        $order = $this->authorizeOrder($order);
        $outcome = $late->accept($order);

        return match ($outcome) {
            'placed' => back()->with('success', ___('Late payment accepted. The order is back in the queue.')),
            'pending_approval' => back()->with('success', ___('Late payment accepted. The time slot or stock is no longer free: the order waits for you to pick a new time or refund.')),
            default => back()->with('error', ___('This late payment is no longer waiting for a decision.')),
        };
    }

    public function refund(Order $order, LatePayments $late): RedirectResponse
    {
        $order = $this->authorizeOrder($order);
        $result = $late->refund($order);
        if ($result === null) {
            return back()->with('error', ___('This late payment is no longer waiting for a decision.'));
        }
        if (! empty($result['refused'])) {
            return back()->with('error', ___('The payment provider refused the refund: :message', ['message' => (string) ($result['message'] ?? '')]));
        }
        if (! empty($result['voided'])) {
            return back()->with('success', ___('Card hold released. Nothing was charged.'));
        }
        if (empty($result['automatic'])) {
            return back()->with('success', ___('Late payment marked as refunded. Send the money back in your payment provider\'s dashboard.'));
        }

        return back()->with('success', ___('Late payment refunded.'));
    }

    private function authorizeOrder(Order $order): Order
    {
        // Same as ManagesOrders: without route binding (a bare dispatch) the order is read by its id.
        if (! $order->exists) {
            $order = Order::query()->findOrFail((int) request()->route('order'));
        }
        abort_unless(
            function_exists('dashboard_can_access_store') && dashboard_can_access_store((int) $order->store_id),
            404
        );

        return $order;
    }
}
