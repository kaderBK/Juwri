<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User\Concerns;

use App\Exceptions\StoreCreditCurrencyMismatchException;
use App\Exceptions\StoreCreditDisabledException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Backup\DatasetStreamWriter;
use App\Services\CustomerWalletService;
use App\Services\OrderStatusNotifier;
use App\Services\PosService;
use App\Services\StorefrontThemeService;
use App\Services\Theme\VerticalThemeSets;
use App\Services\Verticals\VerticalRegistry;
use App\Services\WebhookFanout;
use App\Support\SearchTerm;
use App\Support\StoreClock;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Juwri\Modules\Ordering\Services\Orders\OrderCancellation;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\WaiterCall;

/**
 * The owner's orders: the orders screen and its poll, status changes,
 * refunds and wallet-transfer confirmations, the public tracking page, the
 * dashboard heartbeat and the CSV export.
 */
trait ManagesOrders
{
    /**
     * The owner's orders screen, and its AJAX poll (only the not-yet-seen rows,
     * rendered). Without a store in the URL it opens the dashboard's current
     * store; an owner with no store is sent to create one.
     *
     * Filters (D2, applied in the query so they survive pagination): `q`
     * (customer name / phone, or an order number), `status`, `refunds`
     * (requested|approved|rejected), `from` / `to` (dates). `order` focuses one card.
     */
    public function orders(?Store $store = null): View|JsonResponse|RedirectResponse
    {
        $store = $this->ordersScreenStore($store);
        if ($store === null) {
            $current = dashboard_first_store();
            if ($current) {
                return to_route('stores.orders', $current->id);
            }
            app_alert_error(___('No stores available').' '.___('Add new store'));

            return redirect()->route('stores.create');
        }
        abort_unless($this->ownsStore(request(), $store), 404);

        $postOptions = store_options($store->id);
        // Money on this screen is shown in the store's currency.
        remember_platform_currency(); // bug batch 10 · 1
        config([
            'settings.currency_sign' => $postOptions->currency_sign ?? null,
            'settings.currency_pos' => $postOptions->currency_pos ?? null,
            'settings.currency_code' => $postOptions->currency_code ?? null,
        ]);

        $branchId = dashboard_current_branch_id();
        $refundFilter = in_array(request('refunds'), self::ORDER_REFUND_FILTERS, true) ? (string) request('refunds') : null;
        $listing = $this->ownerOrderListing($store, $branchId, $refundFilter);
        $polling = request()->ajax();
        $orders = $polling ? $listing->where('seen', 0)->get() : $listing->paginate(50)->withQueryString();

        $this->prepareOrderCards($orders instanceof LengthAwarePaginator ? $orders->getCollection() : $orders);
        Order::query()->whereKey($orders->pluck('id')->all())->update(['seen' => 1]);

        if ($polling) {
            return response()->json([
                'success' => true,
                'orders' => $orders->map(fn (Order $order) => view('owner.orders.order-row', ['postOptions' => $postOptions, 'order' => $order])->render())->values()->all(),
            ]);
        }

        $this->purgeAbandonedUnpaidOrders($store);
        [$languages, $editing] = $this->editorLanguages($store);

        return view('owner.orders.index', [
            'store' => $store,
            'postOptions' => $postOptions,
            'menu_languages' => $languages,
            'default_menu_language' => $editing,
            'orders' => $orders,
            'focusOrderId' => (int) request('order', 0) ?: null,
            'walletTransferClaims' => $this->walletTransferClaims($store),
            // P1-23 part 6: gift cards / top-ups / deposits paid by wallet or bank transfer — only this owner confirms them.
            'storeTransfers' => app(StoreTransfers::class)->pendingForStore((int) $store->id),
            'activeRefundFilter' => $refundFilter,
            'pendingRefundCount' => Order::query()
                ->where('store_id', $store->id)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('refund_request_status', 'requested')
                ->count(),
        ]);
    }

    /** Refund-request views of the orders screen (`?refunds=`). */
    private const ORDER_REFUND_FILTERS = ['requested', 'approved', 'rejected'];

    /** Statuses the orders screen can filter on (`?status=`); anything else is ignored. */
    private const ORDER_STATUS_FILTERS = ['pending', 'accepted', 'preparing', 'ready', 'completed', 'cancelled', 'on_delivery', 'delivered'];

    /** The route's store (bound, or the raw id/slug when binding did not run), or null. */
    private function ordersScreenStore(?Store $bound): ?Store
    {
        if ($bound?->exists) {
            return $bound;
        }
        $key = request()->route('store');
        if ($key instanceof Store) {
            return $key->exists ? $key : null;
        }

        return $key ? Store::query()->idOrSlug($key)->first() : null;
    }

    /**
     * The store's placed orders (never the unpaid pay-online ones), newest
     * first, narrowed to the dashboard branch and the request's filters.
     *
     * @return Builder<Order>
     */
    private function ownerOrderListing(Store $store, ?int $branchId, ?string $refundFilter): Builder
    {
        $search = trim((string) request('q', ''));
        $status = (string) request('status', '');
        $from = (string) request('from', '');
        $to = (string) request('to', '');

        return Order::query()
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'voiceNote', 'branch'])
            ->where('store_id', $store->id)
            ->whereNot('status', 'unpaid')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where(function ($match) use ($search) {
                SearchTerm::apply($match, ['customer_name'], $search, ['phone_number']);
                if (ctype_digit($search)) {
                    $match->orWhere('id', (int) $search);
                }
            }))
            // BL-051: a dashboard chip covers every stored value of its group (Pending = pending + new, Completed = completed + delivered).
            ->when(isset(OrderStatus::DASHBOARD_GROUPS[$status]), fn ($q) => $q->whereIn('status', OrderStatus::DASHBOARD_GROUPS[$status]))
            ->when(! isset(OrderStatus::DASHBOARD_GROUPS[$status]) && in_array($status, self::ORDER_STATUS_FILTERS, true), fn ($q) => $q->where('status', $status))
            ->when($refundFilter !== null, fn ($q) => $q->where('refund_request_status', $refundFilter))
            ->tap(fn ($q) => StoreClock::whereLocalDateBetween($q, 'created_at', $store->id, $from, $to))
            ->latest();
    }

    /**
     * Get each listed order ready for its card: every dish line gets its
     * variant label (" (L, Thin)"), and `price` is the order's canonical total
     * (rev/theme-rescue TOTALS-CONTRACT: never a second hand-rolled sum).
     *
     * An order whose lines are worth nothing at today's prices (typically: its
     * dishes were deleted since) is dropped from the list. Combo lines (T6) have no dish row on purpose
     * and count through their options snapshot.
     *
     * @param  Collection<int, Order>  $orders  the listed rows (removed in place)
     */
    private function prepareOrderCards(Collection $orders): void
    {
        // One query for every option group the listed variants point at (N+1 fix).
        $groupIds = [];
        foreach ($orders as $order) {
            foreach ($order->items as $line) {
                array_push($groupIds, ...array_keys((array) $line->variant?->options));
            }
        }
        $groups = $groupIds ? MenuVariantOption::query()->whereIn('id', array_unique($groupIds))->get()->keyBy('id') : collect();

        foreach ($orders as $position => $order) {
            $worth = 0.0;
            foreach ($order->items as $line) {
                if ($line->menu) {
                    $line->variant_title = $this->orderLineVariantLabel($line->variant?->options, $groups);
                    $worth += $this->orderLineWorth($line);
                } elseif ($line->isCombo()) {
                    $line->variant_title = '';
                    $worth += $line->snapshotUnitPrice() * $line->quantity;
                }
            }

            if ($worth != 0) {
                $order->price = $order->total;
            } else {
                $orders->forget($position);
            }
        }
    }

    /**
     * A dish line at today's prices (variant price when it has one, plus its
     * extras) × quantity — only the orders screen's liveness test uses it.
     */
    private function orderLineWorth(OrderItem $line): float
    {
        if ($line->variant) {
            $line->menu->price = $line->variant->price; // the card shows the variant's price
        }
        $unit = (float) ($line->menu->price ?? 0);
        foreach ($line->itemExtras as $picked) {
            $unit += (float) ($picked->extra->price ?? 0);
        }

        return $unit * $line->quantity;
    }

    /**
     * " (L, Thin)" for a line's variant snapshot, or ''. A pick whose group or
     * value no longer exists is skipped (a deleted group must not break the card).
     *
     * @param  Collection<int|string, MenuVariantOption>  $groups
     */
    private function orderLineVariantLabel(mixed $picks, Collection $groups): string
    {
        $labels = [];
        foreach ((array) $picks as $groupId => $valueIndex) {
            $values = $groups->get($groupId)['options'] ?? null;
            if (isset($values[$valueIndex])) {
                $labels[] = $values[$valueIndex];
            }
        }

        return $labels ? ' ('.implode(', ', $labels).')' : '';
    }

    /**
     * Pay-online orders never paid within 3 hours are deleted. They took stock
     * when created, so each gives its units back first (a bulk delete fires no
     * model events and would leak the stock for good).
     */
    private function purgeAbandonedUnpaidOrders(Store $store): void
    {
        $abandoned = Order::query()
            ->where(['store_id' => $store->id, 'status' => 'unpaid'])
            ->where('created_at', '<=', now()->subHours(3))
            ->get();
        foreach ($abandoned as $unpaid) {
            PosService::restoreOrderStock($unpaid);
            $unpaid->delete();
        }
    }

    /**
     * Syria A4: wallet-transfer claims waiting for the owner to check the
     * transaction id against their wallet SMS. Such an order stays `unpaid`
     * until confirmed, so it is not in the list — the claims are shown apart,
     * limited to the dashboard branch.
     *
     * @return Collection<int, Transaction>
     */
    private function walletTransferClaims(Store $store): Collection
    {
        return Transaction::query()
            ->where('store_id', $store->id)
            ->where('transaction_method', 'order')
            ->where('transaction_gatway', 'wallet_transfer')
            ->where('status', Transaction::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->filter(fn ($t) => ! empty(optional($t->details)->claimed_at) && empty(optional($t->details)->rejected))
            ->map(function ($t) {
                $t->setRelation('claimOrder', Order::find($t->product_id));

                return $t;
            })
            ->filter(fn ($t) => $t->claimOrder && (! ($bid = dashboard_current_branch_id()) || (int) $t->claimOrder->branch_id === (int) $bid))
            ->values();
    }

    /**
     * Syria A4 — the pending, claimed, not-rejected wallet_transfer transaction for
     * an order (the settlement target), or null.
     */
    private function pendingWalletTransfer(Order $order): ?Transaction
    {
        return Transaction::where('product_id', $order->id)
            ->where('transaction_method', 'order')
            ->where('transaction_gatway', 'wallet_transfer')
            ->where('status', Transaction::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Syria A4 — owner confirms a wallet_transfer claim. Store-scoped (403 for any
     * other user). Settlement goes through PaymentController::paySuccess — the ONE
     * canonical money path (atomic claim, is_paid, wallet/loyalty/GL/webhook). NOT
     * Admin/TransactionController::update, which calls updateUserPlan and would
     * silently never mark the order paid.
     *
     * Replay-safe: paySuccess claims the row atomically (status != consumed), and a
     * second confirm no longer finds a PENDING claim, so it is a no-op either way.
     *
     * @return JsonResponse|RedirectResponse
     */
    public function confirmWalletTransfer(Request $request, Order $order)
    {
        $store = Store::find($order->store_id);
        if (! $store || $store->user_id != auth()->id()) {
            return response()->json(['success' => false, 'message' => ___('Unauthorized')], 403);
        }

        $tx = $this->pendingWalletTransfer($order);
        if (! $tx) {
            return response()->json(['success' => false, 'message' => ___('No pending wallet-transfer payment to confirm.')], 422);
        }

        /* paySuccess() echoes a storefront redirect <script> (it is written for the
           gateway return page). Buffer + discard that output here so it never
           corrupts the dashboard JSON/redirect; all DB side effects still run. */
        ob_start();
        try {
            PaymentController::paySuccess($tx);
        } finally {
            ob_end_clean();
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => ___('Payment confirmed.')]);
        }

        app_alert_success(___('Payment confirmed.'));

        return back();
    }

    /**
     * Syria A4 — owner rejects a wallet_transfer claim. Store-scoped (403 others).
     * Marks the claim rejected (raw-array write, 'object' cast) and leaves the order
     * UNPAID — no money moves.
     *
     * @return JsonResponse|RedirectResponse
     */
    public function rejectWalletTransfer(Request $request, Order $order)
    {
        $store = Store::find($order->store_id);
        if (! $store || $store->user_id != auth()->id()) {
            return response()->json(['success' => false, 'message' => ___('Unauthorized')], 403);
        }

        $tx = $this->pendingWalletTransfer($order);
        if (! $tx) {
            return response()->json(['success' => false, 'message' => ___('No pending wallet-transfer payment to reject.')], 422);
        }

        // Trap #1: details is 'object'-cast — assign a RAW ARRAY, merged.
        $details = (array) ($tx->details ?? []);
        $details['rejected'] = true;
        $details['rejected_at'] = now()->toIso8601String();
        $tx->update(['details' => $details]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => ___('Payment rejected.')]);
        }

        app_alert_success(___('Payment rejected.'));

        return back();
    }

    /**
     * Mark an order completed (stamps `completed_at`). Anyone but the store's
     * owner gets the same success answer and changes nothing.
     */
    public function completeOrder(Request $request, Order $order): JsonResponse
    {
        if (! $order->exists) {
            $order = Order::findOrFail((int) $request->route('order'));
        }
        $store = $order->store ?: Store::find($order->store_id);
        if ($store && $this->ownsStore($request, $store)) {
            // P6-207: a cancelled order already gave back stock and tender — never revive it.
            if (OrderCancellation::blocksTransition($order, OrderStatus::Completed->value)) {
                return response()->json(['success' => false, 'message' => ___('A cancelled order cannot be changed.')], 422);
            }
            $order->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }

        return response()->json(['success' => true, 'message' => ___('Updated Successfully')]);
    }

    /**
     * Update order status with stage timestamps
     *
     * @return JsonResponse
     */
    /**
     * Coupons & Wallets P3-5: refund an order to the customer's store credit.
     * Owner-scoped (same tenant check as status changes). Idempotent per order
     * (the refund ref keys on the order id), so a double-fired refund credits
     * exactly once — the reversal-safe alternative to a cash refund.
     */
    public function refundOrderToCredit(Request $request, Order $order)
    {
        if (! $order->exists) {
            $order = Order::findOrFail((int) $request->route('order'));
        }
        $store = $order->store ?: Store::find($order->store_id);
        if (! $store || $store->user_id != auth()->id()) {
            return response()->json(['success' => false, 'message' => ___('Unauthorized')], 403);
        }
        if (! $order->customer_id) {
            return response()->json(['success' => false, 'message' => ___('This order has no customer account to credit.')], 422);
        }
        $customer = Customer::find($order->customer_id);
        if (! $customer) {
            return response()->json(['success' => false, 'message' => ___('Customer not found.')], 422);
        }

        // A3: a charge-to-room order was never collected — its money sits as a folio
        // receivable, not a payment. Crediting the wallet while the folio charge
        // stands double-compensates the guest (wallet credit AND the debt is written
        // off). Refuse, and direct the owner to cancel the order (which reverses the
        // folio charge) instead. Never silently issue credit on top of the folio.
        if ($order->payment_method === 'charge_to_room'
            && app(RoomCharge::class)->orderChargeReversalState($order) === 'charged') {
            return response()->json(['success' => false, 'message' => ___('This order is charged to a room folio. Cancel the order to reverse the folio charge instead of refunding to store credit.')], 422);
        }

        // The order's OWN currency (stamped at checkout; the store's when unstamped) — not the
        // store's current currency, which may have changed since the order was paid.
        $currency = strtoupper((string) ($order->currencyCode() ?: app(Ledger::class)->defaultCurrency()));
        $amount = money_round((float) ($request->input('amount') ?: $order->total), money_decimals($currency)); // P6-208: Money::round, not PHP round
        if ($amount <= 0) {
            return response()->json(['success' => false, 'message' => ___('Nothing to refund.')], 422);
        }

        /* MONETIZATION B10: refund-to-credit ISSUES store credit, so it needs the
           customer_wallet entitlement. Dark today (no change); once V0 flips, a
           non-entitled store gets a clear message and refunds via the gateway
           instead — money is never trapped, and no 500. */
        // An order with no known amount due (no priced lines) has nothing to cap a refund against: it keeps the old
        // rule of one credit per order. A priced order records one refund row per refund (its id keys the credit and the
        // journal), so a second PARTIAL refund is credited and booked too, capped by what is left to refund; a replay
        // (same idempotency key) is neither.
        $capped = (float) $order->amount_due > 0;

        try {
            // ONE transaction: lock the order row, then the replay lookup, the cap check (sum of refunds so far) and the
            // refund row insert, so two refunds of one order cannot both pass the cap. The credit and the journal are keyed
            // by the row's id; a failed credit rolls the row back with it.
            $outcome = $capped
                ? DB::transaction(function () use ($order, $amount, $customer, $currency, $request) {
                    $locked = Order::whereKey($order->id)->lockForUpdate()->first() ?? $order;
                    if ($this->replayedRefund($locked, $request)) {
                        return response()->json(['success' => true, 'replayed' => true]);
                    }
                    if ($refusal = $this->refundCapRefusal($locked, $amount)) {
                        return $refusal;
                    }
                    $adjustment = $this->recordOwnerRefund($locked, $amount, 'other', $request);

                    return CustomerWalletService::refundToCredit($customer, $amount, (int) $order->id, auth()->id(), $currency, 'adj:'.$adjustment->id);
                })
                : CustomerWalletService::refundToCredit($customer, $amount, (int) $order->id, auth()->id(), $currency);
            if ($outcome instanceof JsonResponse) {
                return $outcome;
            }
            $balance = $outcome;
        } catch (StoreCreditCurrencyMismatchException $e) {
            Log::warning('Refund to store credit refused: order currency differs from the store credit currency', [
                'order_id' => $order->id, 'order_currency' => $currency,
                'wallet_currency' => CustomerWalletService::walletCurrency($customer), 'amount' => $amount,
            ]);

            return response()->json([
                'success' => false,
                'message' => ___('This order was paid in :currency and the customer store credit is in another currency. Refund through your payment provider instead.', ['currency' => $currency]),
            ], 422);
        } catch (StoreCreditDisabledException $e) {
            return response()->json([
                'success' => false,
                'message' => ___('Store credit is not enabled on your plan. Refund through your payment provider instead.'),
            ], 422);
        }

        // If this refund answers a customer request, mark it approved so the
        // request drops off the pending list and the diner sees it resolved.
        // Stamp the destination so the diner badge reads "Refunded to wallet".
        $order->forceFill(array_filter([
            'refund_request_status' => $order->refund_request_status === 'requested' ? 'approved' : $order->refund_request_status,
            'refund_method' => 'store_credit',
        ], fn ($v) => $v !== null))->save();

        log_activity('order.refund_credit', $order, ['order_id' => $order->id, 'amount' => $amount], $order->store_id);

        WebhookFanout::forRestaurant((int) $order->store_id, 'refund.completed', ['order_id' => $order->id, 'amount' => $amount, 'method' => 'store_credit']); // HPMS E10

        return response()->json(['success' => true, 'store_credit' => $balance]);
    }

    /**
     * Owner rejects a pending customer refund request (no money moves). Same
     * tenant guard as refundOrderToCredit.
     */
    public function rejectRefundRequest(Request $request, Order $order): JsonResponse
    {
        if (! $order->exists) {
            $order = Order::findOrFail((int) $request->route('order'));
        }
        $store = $order->store ?: Store::find($order->store_id);
        if (! $store || $store->user_id != auth()->id()) {
            return response()->json(['success' => false, 'message' => ___('Unauthorized')], 403);
        }
        if ($order->refund_request_status !== 'requested') {
            return response()->json(['success' => false, 'message' => ___('No pending refund request for this order.')], 422);
        }

        $order->forceFill(['refund_request_status' => 'rejected'])->save();
        log_activity('order.refund_rejected', $order, ['order_id' => $order->id], $order->store_id);

        return response()->json(['success' => true]);
    }

    /**
     * Refund an order back to the diner's ORIGINAL payment (card/gateway) instead
     * of store credit. Delegates the money movement to a per-gateway driver via
     * GatewayRefundService; a provider without a live driver is recorded as a
     * tracked manual refund (owner completes it in the provider dashboard). Same
     * tenant guard as refundOrderToCredit. Idempotent: a settled refund refuses.
     */
    public function refundOrderToOriginal(Request $request, Order $order): JsonResponse
    {
        if (! $order->exists) {
            $order = Order::findOrFail((int) $request->route('order'));
        }
        $store = $order->store ?: Store::find($order->store_id);
        if (! $store || $store->user_id != auth()->id()) {
            return response()->json(['success' => false, 'message' => ___('Unauthorized')], 403);
        }

        // Only paid money can be pushed back to a card. An unpaid order has
        // nothing to refund at the gateway.
        if (! $order->is_paid) {
            return response()->json(['success' => false, 'message' => ___('This order is not paid, so there is nothing to refund to the original payment.')], 422);
        }

        // A charge-to-room order was never actually charged to a gateway — its
        // money sits as a folio receivable. Reverse it by cancelling, not here.
        if ($order->payment_method === 'charge_to_room'
            && app(RoomCharge::class)->orderChargeReversalState($order) === 'charged') {
            return response()->json(['success' => false, 'message' => ___('This order is charged to a room folio. Cancel the order to reverse the folio charge instead of refunding.')], 422);
        }

        // Bug batch 6: the amount is in the ORDER's own currency (its stamp, not the store's current one) and is
        // taken exactly — an amount with more decimals than that currency has is refused, never rounded.
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $exponent = Currencies::exponent($currency);
        $input = $request->input('amount');
        if ($input !== null && $input !== '' && (! is_numeric($input) || ! self::fitsExponent((string) $input, $exponent))) {
            return response()->json(['success' => false, 'message' => ___('The refund amount :amount cannot be refunded exactly in :currency on this payment method. Enter an amount the payment method supports.', [
                'amount' => (string) $input, 'currency' => $currency,
            ])], 422);
        }
        $amount = Money::round((float) ($input ?: $order->total), $exponent);
        if ($amount <= 0) {
            return response()->json(['success' => false, 'message' => ___('Nothing to refund.')], 422);
        }

        // ONE transaction under the order's row lock: replay lookup, cap check (sum of refunds so far), the gateway call,
        // then the refund row. The gateway is called INSIDE the lock, after the cap check, so two concurrent refunds can
        // neither both pass the cap nor both reach the provider; a refusal before any provider call (`refused`) records
        // nothing, exactly as before. A provider that cannot refund automatically is a tracked manual refund and is recorded
        // (the code's existing way of handling "no live driver"); an exception from the provider rolls the whole thing back.
        $capped = (float) $order->amount_due > 0;
        if (! $capped && $order->refund_request_status === 'approved' && $order->refund_method) {
            // no known amount due: the old rule, one settled refund per order
            return response()->json(['success' => false, 'message' => ___('This order has already been refunded.')], 422);
        }
        $outcome = DB::transaction(function () use ($order, $amount, $request, $capped) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first() ?? $order;
            if ($this->replayedRefund($locked, $request)) {
                return response()->json(['success' => true, 'replayed' => true]);
            }
            if ($capped && ($refusal = $this->refundCapRefusal($locked, $amount))) {
                return $refusal;
            }
            if (! $capped && $locked->refund_request_status === 'approved' && $locked->refund_method) {
                return response()->json(['success' => false, 'message' => ___('This order has already been refunded.')], 422);
            }

            $result = app(Gateways::class)->refundOrder($locked, $amount);
            if (! empty($result['refused'])) {
                return response()->json(['success' => false, 'message' => $result['message']], 422);
            }

            // One refund row per refund: its id keys the journal, so a second partial refund is booked too.
            return ['result' => $result, 'adjustment' => $this->recordOwnerRefund($locked, $amount, 'online', $request)];
        });
        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }
        $result = $outcome['result'];
        $adjustment = $outcome['adjustment'];

        $order->forceFill([
            'refund_request_status' => 'approved',
            'refund_method' => 'original_payment',
        ])->save();

        // The money going back out is a journal too: DR refunds, CR cash/clearing (no-op unless accounting_gl is on,
        // idempotent per refund). Reporting only — it never fails the refund.
        try {
            app(GeneralLedger::class)->postRefund($order, $amount, 'adj:'.$adjustment->id);
        } catch (\Throwable $e) {
            report($e);
        }

        log_activity('order.refund_original', $order, [
            'order_id' => $order->id,
            'amount' => $amount,
            'gateway' => $result['gateway'],
            'automatic' => $result['automatic'],
            'reference' => $result['reference'],
        ], $order->store_id);

        WebhookFanout::forRestaurant((int) $order->store_id, 'refund.completed', [
            'order_id' => $order->id,
            'amount' => $amount,
            'method' => 'original_payment',
            'gateway' => $result['gateway'],
            'automatic' => $result['automatic'],
        ]);

        return response()->json([
            'success' => true,
            'automatic' => $result['automatic'],
            'message' => $result['automatic']
                ? ___('Refund sent to the original payment method.')
                : $result['message'],
        ]);
    }

    /**
     * Refuse a refund above what is left to refund: what was collected (amount due) minus every refund already recorded
     * on the order (owner refunds and register refunds share the one ledger of OrderAdjustment rows). An order refunded
     * before refunds were recorded as rows (refund method set, no rows) counts as fully refunded.
     */
    private function refundCapRefusal(Order $order, float $amount): ?JsonResponse
    {
        $dec = money_decimals(strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id))));
        $rows = OrderAdjustment::where('order_id', $order->id)->where('type', 'refund');
        $already = (float) (clone $rows)->sum(DB::raw(OrderAdjustment::minorMoneySql('amount')));
        if ($order->refund_method && ! (clone $rows)->exists()) {
            return response()->json(['success' => false, 'message' => ___('This order has already been refunded.')], 422);
        }
        $refundable = Money::round((float) $order->amount_due - $already, $dec);
        if ($amount > $refundable) {
            return response()->json(['success' => false, 'message' => $refundable <= 0
                ? ___('This order has already been refunded.')
                : ___('Refund exceeds the remaining refundable amount').' ('.$refundable.').'], 422);
        }

        return null;
    }

    /** The refund row of an earlier request with the same `idempotency_key`, if the caller sent one. */
    private function replayedRefund(Order $order, Request $request): ?OrderAdjustment
    {
        $key = (string) $request->input('idempotency_key', '');

        return $key === '' ? null : OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->where('idempotency_key', $key)->first();
    }

    private function recordOwnerRefund(Order $order, float $amount, string $method, Request $request): OrderAdjustment
    {
        return OrderAdjustment::create([
            'order_id' => $order->id,
            'staff_id' => null,
            'pos_shift_id' => null,
            'type' => 'refund',
            'amount' => $amount,
            'method' => $method,
            'reason' => $method === 'online' ? 'Refund to the original payment' : 'Refund to store credit',
            'idempotency_key' => ($k = (string) $request->input('idempotency_key', '')) !== '' ? mb_substr($k, 0, 64) : null,
        ]);
    }

    /** Whether a numeric amount has no more decimals than $exponent (bug batch 6: refunds are exact). */
    private static function fitsExponent(string $amount, int $exponent): bool
    {
        try {
            Money::decimal($amount)->toScale(max(0, $exponent));

            return true;
        } catch (RoundingNecessaryException) {
            return false;
        }
    }

    public function updateOrderStatus(Request $request, Order $order)
    {
        if (! $order->exists) {
            $order = Order::findOrFail((int) $request->route('order'));
        }
        $store = $order->store ?: Store::find($order->store_id);
        // H7: tenant isolation — this check must ALWAYS run (it was previously skipped
        // under tests, leaving it uncovered and free to regress). An owner may only
        // change the status of orders belonging to a restaurant they own.
        if (! $store || $store->user_id != auth()->id()) {
            return response()->json(['success' => false, 'message' => ___('Unauthorized')], 403);
        }

        $status = $request->input('status');
        $validStatuses = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];
        if (! in_array($status, $validStatuses)) {
            return response()->json(['success' => false, 'message' => ___('Invalid status')], 400);
        }

        // Cancelling takes the order off the settlement path (restocks, cancelled_at). A
        // split bill can hold PAID cash shares while is_paid=0 ('partial'); a cancelled
        // order is is_paid=0 with no 'split' stamp, so its collected cash is invisible to
        // expectedCash / the Z-report — the same skim the void + delete paths refuse (and
        // would be defeated here otherwise). Refuse: refund the paid shares or finish
        // settling before cancelling. Non-cancel transitions keep the order settleable.
        // P6-207: cancelled is terminal — stock and tender already went back.
        if (OrderCancellation::blocksTransition($order, $status)) {
            return response()->json(['success' => false, 'message' => ___('A cancelled order cannot be changed.')], 422);
        }
        // A split bill can hold PAID cash shares while is_paid=0 ('partial'); cancelling
        // would hide that cash from expectedCash / the Z-report. Refuse (same as void/delete).
        if ($status === 'cancelled' && OrderCancellation::hasPaidSplitShares($order)) {
            return response()->json(['success' => false, 'message' => ___('This bill already has paid split shares — refund those or finish settling it before cancelling.')], 422);
        }

        $order->status = $status;

        // R1a: stamp the matching timestamp off the typed status (tolerant of any
        // out-of-enum legacy string, which simply stamps nothing — as before).
        match (OrderStatus::tryFrom($status)) {
            OrderStatus::Preparing => $order->preparing_at = now(),
            OrderStatus::Ready => $order->ready_at = now(),
            OrderStatus::Completed => $order->completed_at = now(),
            OrderStatus::Cancelled => $order->cancelled_at = now(),
            default => null,
        };

        $order->save();

        // Cancelling returns the order's stock — it was deducted at creation.
        // restoreOrderStock is idempotent and skips held/already-restored orders.
        // Cancelling reverses stock, folio, gift card, wallet and loyalty (idempotent).
        if ($status === 'cancelled') {
            OrderCancellation::reverse($order);
        }

        log_activity('order.status', $order, ['status' => $status, 'order_id' => $order->id], $order->store_id);

        /* #104 / P6-292: customer-facing status SMS + WhatsApp (owner opt-in via
           'customer_status_sms' / 'customer_status_whatsapp'; transactional).
           Routed through OrderStatusNotifier so the same code — locale handling,
           template resolution, and per (order, status) idempotency — is shared
           with the staff PWA (StaffAppController::updateStatus) and the KDS
           (KdsController::bump / KdsService::syncOrderStatus). The locale wrap
           + status-word translation still happen INSIDE the notifier, exactly
           as P6-213 required. */
        OrderStatusNotifier::notify($order, $status);

        return response()->json(['success' => true, 'message' => ___('Status updated successfully')], 200);
    }

    public function trackOrder(Request $request, string $token): mixed
    {
        $order = Order::where('track_token', $token)->firstOrFail();

        if ($request->wantsJson() || $request->ajax()) {
            // ZERO-TOUCH Track C: nullable prep-time estimate; null once the
            // order reaches ready (the page then shows actuals, never an ETA).
            $prepEta = EtaEstimator::forOrder($order);

            return response()->json([
                'status' => $order->status,
                'preparing_at' => $order->preparing_at ? $order->preparing_at->toIso8601String() : null,
                'ready_at' => $order->ready_at ? $order->ready_at->toIso8601String() : null,
                'completed_at' => $order->completed_at ? $order->completed_at->toIso8601String() : null,
                'cancelled_at' => $order->cancelled_at ? $order->cancelled_at->toIso8601String() : null,
                'prep_eta' => $prepEta?->toArray(),
                // PARITY-RUN T2: the customer's board number (null when the store
                // has no board, or this order type was not opted in).
                'token_number' => $order->token_number ? (int) $order->token_number : null,
                // T3: coarse delivery progress for the diner — a STRING ONLY. No
                // driver name/phone/coordinates ever reach this public token-auth
                // endpoint (contrast the marketplace TrackController, which is a
                // different, richer privacy model). Delivery orders only; pickup
                // and table orders never carry it.
                'delivery_status' => $order->type === 'delivery' && $order->delivery_status
                    ? (string) $order->delivery_status
                    : null,
            ]);
        }

        /* Track-page language, in priority order:
             1. the visitor's CURRENT language — the `qc_lang_choice` cookie the
                shared switcher writes (store_templates/_partials/language-switch;
                kept plain in EncryptCookies $except). This is the language the diner
                is actively viewing the whole storefront in, so the track page must
                match it: browse in English, the track page is English; browse in
                Arabic, it is Arabic. It has to WIN, because the alternative
                (below) is the order's language, which is frequently different — a
                diner reading in English does not want an Arabic receipt just because
                the order happened to be placed in Arabic.
             2. otherwise the language the order was PLACED in (orders.locale). This
                is the COLD-LINK case: the page is reached from an SMS/WhatsApp/email/
                push link in a fresh browser with no expressed preference, so the
                language the order was made in is the best guess and stops an Arabic
                order opening an English track page on an English-default store.
           Older orders with neither keep the request-resolved language. Only a
           supported locale is ever trusted. */
        $supportedLocales = config('laravellocalization.supportedLocales', []);
        $explicitChoice = strtolower(trim((string) $request->cookie('qc_lang_choice', '')));
        $orderLocale = strtolower(trim((string) ($order->locale ?? '')));
        if ($explicitChoice !== '' && array_key_exists($explicitChoice, $supportedLocales)) {
            app()->setLocale($explicitChoice);
        } elseif ($orderLocale !== '' && array_key_exists($orderLocale, $supportedLocales)) {
            app()->setLocale($orderLocale);
        }

        $store = $order->store ?: Store::find($order->store_id);
        $plan = $store->user->plan();

        // Load options
        $postOptions = new \stdClass;
        foreach (StoreSettings::legacyMap((int) $store->id) as $name => $value) { // P1-07: stores.settings
            $postOptions->{$name} = $value;
        }

        /* The tracking page shows the customer what they were charged, so it takes the
           one canonical total. This used to be a second hand-rolled re-derivation that
           omitted choice-group option prices, the room-service tray charge, donations
           and the POS service charge -- an order using any of them displayed less than
           it billed. See rev/theme-rescue/TOTALS-CONTRACT.md. */
        $order->price = $order->total;

        // ZERO-TOUCH Track C: initial server-rendered prep-time estimate for the
        // shared prep-eta partial (kept fresh client-side from the JSON above).
        $prepEta = EtaEstimator::forOrder($order);

        // Render the restaurant's own template track page when it ships one.
        // rev/multi-vertical: aurora is a FOOD theme, so a shelf/bookla (retail/
        // service) store with no own track view must not fall back to it — use
        // that store's vertical-shared track view instead, when it exists.
        // Aurora stays the fallback for food-vertical templates (and for any
        // vertical whose shared track view is missing, so this never 404s).
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $themeSet = app(VerticalRegistry::class)->themeSet($store->store_type ?? null);
        $verticalFallback = $themeSet === 'service' ? 'bookla' : ($themeSet !== 'food' ? 'shelf' : null);
        /* The resolved template can belong to ANOTHER vertical. The usual case
           is a store running a MARKETPLACE theme ('mp:blooms@1.2.8'): no
           StorefrontThemeService knows that value, so the ADMIN's fallback
           template — a food theme — is resolved instead, and its track page
           would track a clothing order with the kitchen's words ("Preparing
           your Food", "The chef is preparing your meal"). So when the
           template's own theme set is not the store's set, the vertical's own
           track view is used instead: the goods vocabulary for a goods store.
           A store that runs its OWN goods template (zevoa, shelf, torque…)
           keeps that template's track page — its set already matches. */
        if ($verticalFallback !== null
            && app(VerticalThemeSets::class)->setFor($theme) !== $themeSet
            && view()->exists('store_templates.'.$verticalFallback.'.track')) {
            $theme = $verticalFallback;
        }
        if (! view()->exists('store_templates.'.$theme.'.track')) {
            $theme = ($verticalFallback && view()->exists('store_templates.'.$verticalFallback.'.track'))
                ? $verticalFallback
                : 'aurora';
        }

        /* Item names / extras on the track page follow the page language, exactly like the storefront: the models read the
           menu-content language from the Quick_user_lang_code cookie, which the 17 modern templates do not use (they switch
           by URL locale prefix), so a track page in Arabic printed the English item names (found by the P1-12 Arabic
           journey). Same pin as StoreController::publicView, for the same templates. */
        StorefrontThemeService::pinMenuContentLanguage($theme);

        // Syria A5: the latest wallet_transfer transaction for this order (any
        // status), so the shared payment-pending partial can show the right state
        // (awaiting-claim / claimed / confirmed) on the track page. Null for every
        // non-wallet_transfer order → the partial renders nothing.
        $walletTx = Transaction::where('product_id', $order->id)
            ->where('transaction_method', 'order')
            ->where('transaction_gatway', 'wallet_transfer')
            ->latest('id')
            ->first();

        return view('store_templates.'.$theme.'.track', compact('order', 'store', 'postOptions', 'theme', 'plan', 'prepEta', 'walletTx'));
    }

    /**
     * Delete an order: stock goes back first and the deletion is audited.
     * Anyone but the store's owner gets the same success answer and deletes
     * nothing.
     */
    public function deleteOrder(Request $request, Order $order): JsonResponse
    {
        if ($this->ownsStore($request, $order->store)) {
            // Deleting a PAID order removes a settled sale from the drawer / Z-report
            // (which filter is_paid=1) — the same money-reversal power that void() and
            // refund() reserve for pos_refund (manager). This dashboard sibling was
            // gated only by 'orders', so a cashier could settle a cash sale, delete the
            // order, and the till would reconcile as if it never happened. Owners bypass
            // (dashboard_allows returns true with no staff session); a staff without
            // pos_refund is refused on a paid order.
            if ($order->is_paid && ! dashboard_allows('pos_refund')) {
                abort(403, ___('Deleting a paid order requires refund permission.'));
            }

            // A split bill can hold PAID cash shares while the order is still is_paid=0
            // ('partial') — real money already collected but only reconciled once the
            // whole order settles (payment_method='split'). Deleting it removes the order
            // (and its check rows) and orphans that cash off the drawer/Z-report, the same
            // skim the void path refuses. Bypasses the is_paid gate above, so guard it
            // explicitly: refund the paid shares or finish settling before deleting.
            if (CheckPayment::whereHas('tableCheck', fn ($q) => $q->where('order_id', $order->id))->where('status', 'paid')->exists()) {
                abort(422, ___('This bill already has paid split shares — refund those or finish settling it before deleting.'));
            }

            $wasPaid = (bool) $order->is_paid;

            // Give stock back before the row (and its items) are gone. Idempotent —
            // a no-op if this order was already restocked (e.g. cancelled first).
            PosService::restoreOrderStock($order);
            $order->delete();

            // Audit the deletion (the method wrote none). actor_user_id is the owner
            // during a staff session, so record the real staff actor in meta.
            log_activity('order.deleted', null, [
                'order_id' => $order->id,
                'was_paid' => $wasPaid,
                'staff_id' => staff_session()?->id,
            ], (int) $order->store_id);
        }

        return response()->json(['success' => true, 'message' => ___('Deleted Successfully')]);
    }

    /**
     * Inline kit icon (`<x-ui.icon>`: kit line icon, else vendored Tabler SVG)
     * for the waiter-call lines the heartbeat returns as HTML.
     */
    private function waiterCallIcon(string $name): string
    {
        return trim(Blade::render('<x-ui.icon :name="$name" size="14" />', ['name' => $name]));
    }

    /**
     * The dashboard poll: how many new orders arrived across the owner's stores
     * (they are marked seen by the same statement — M11: one UPDATE whose
     * affected-row count is the answer, so no order can slip between a count
     * and an update), plus the pending waiter calls as HTML lines, which are
     * then removed.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $storeIds = $request->user()->stores->pluck('id')->all();

        $newOrders = Order::query()
            ->whereIn('store_id', $storeIds)
            ->where('seen', 0)
            ->whereNot('status', 'unpaid')
            ->update(['seen' => 1]);

        $calls = WaiterCall::query()->whereIn('store_id', $storeIds)->where('seen', 0)->get();
        $lines = $calls->map(fn (WaiterCall $call) => $this->waiterCallLine($call))->all();
        WaiterCall::query()->whereKey($calls->modelKeys())->delete();

        return response()->json(['success' => true, 'orders' => $newOrders, 'waiterCalls' => $lines]);
    }

    /** "<store> <br> <bell> Customer is waiting at table number 4" (bill requests get the receipt icon). */
    private function waiterCallLine(WaiterCall $call): string
    {
        $wantsBill = ($call->type ?? 'call_waiter') === 'request_bill';
        $text = $wantsBill ? ___('Customer requests the bill at table number') : ___('Customer is waiting at table number');

        return '<small>'.$this->waiterCallIcon('dine-in').' '.e($call->store->title).'</small><br>'
            .$this->waiterCallIcon($wantsBill ? 'receipt' : 'bell').' '.e($text.' '.$call->table_no);
    }

    /**
     * D9: stream the restaurant's orders as CSV, honouring the same
     * ?q/?status/?from/?to scope filters as the orders list. Ownership-guarded
     * like menuExportCsv; behind ratelimit:export.
     */
    public function ordersExportCsv(Request $request, $store)
    {
        $store = Store::where('user_id', auth()->id())
            ->where(function ($q) use ($store) {
                $q->idOrSlug($store);
            })->firstOrFail();

        // Money follows the store's currency (3-decimal Gulf currencies).
        $decimals = currency_decimals(store_options($store->id, 'currency_code') ?: null);

        $allowedStatus = ['pending', 'accepted', 'preparing', 'ready', 'completed', 'delivered', 'cancelled', 'refunded'];
        $q = (string) $request->query('q', '');
        $status = (string) $request->query('status', '');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $query = Order::query()
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra'])
            ->where('store_id', $store->id)
            ->whereNot('status', 'unpaid')
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                SearchTerm::apply($w, ['customer_name'], $q, ['phone_number']);
                if (ctype_digit(trim($q))) {
                    $w->orWhere('id', (int) $q);
                }
            }))
            // BL-051: the export filters exactly like the Orders screen's chips.
            ->when(isset(OrderStatus::DASHBOARD_GROUPS[$status]), fn ($qq) => $qq->whereIn('status', OrderStatus::DASHBOARD_GROUPS[$status]))
            ->when(! isset(OrderStatus::DASHBOARD_GROUPS[$status]) && in_array($status, $allowedStatus, true), fn ($qq) => $qq->where('status', $status))
            ->tap(fn ($qq) => StoreClock::whereLocalDateBetween($qq, 'created_at', $store->id, $from, $to))
            ->latest();

        $filename = 'orders-'.$store->slug.'-'.date('Y-m-d').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($query, $decimals) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, ['id', 'created_at', 'customer_name', 'phone_number', 'type', 'table_number', 'status', 'is_paid', 'total'], escape: '\\');
            $query->chunk(200, function ($orders) use ($handle, $decimals) {
                foreach ($orders as $order) {
                    // customer_name / phone_number / table_number are storefront free
                    // text — a customer ordering as `=HYPERLINK(...)` would run in the
                    // owner's spreadsheet. Route every cell through the same neutralizer
                    // CustomerController::export uses, not a raw fputcsv.
                    fputcsv($handle, array_map([DatasetStreamWriter::class, 'csvCell'], [
                        $order->id,
                        (string) $order->created_at,
                        $order->customer_name,
                        $order->phone_number,
                        $order->type,
                        $order->table_number,
                        $order->status,
                        $order->is_paid ? '1' : '0',
                        number_format((float) $order->total, $decimals, '.', ''),
                    ]),
                        escape: '\\');
                }
            });
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
