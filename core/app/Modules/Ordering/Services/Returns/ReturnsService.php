<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Returns;

use App\Exceptions\StoreCreditCurrencyMismatchException;
use App\Exceptions\StoreCreditDisabledException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use App\Services\WebhookFanout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\Returns;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\ReturnRequest;
use Juwri\Modules\Payments\Contracts\Gateways;

/**
 * P3-08 (aisle §10, D7) — goods returns.
 *
 *  - The window: `returns_window_days` (0 = no returns offered, the default) counted from the order's handoff —
 *    delivered, collected, shipped or done, whichever came first of what the order has. Only goods orders handed over
 *    (or finished) can be returned; a cancelled or unpaid order cannot.
 *  - Resolutions: exchange and store credit always; refund only with `refunds_returns` on (D7 off by default).
 *  - A line cannot be asked for twice: the returnable quantity is the line's quantity minus what open or completed
 *    requests already hold.
 *  - Money: the return's value is the lines as sold (OrderItem::soldUnitPrice, minor units of the order currency),
 *    capped at completion by what is left to refund on the order (amount due minus every refund row), under the
 *    order's row lock — the same ledger the owner refund screens use, so a return and a manual refund cannot both
 *    pay out the same money. Store credit goes through CustomerWalletService::refundToCredit, a refund through the
 *    store's own gateway (Gateways::refundOrder — never the platform's).
 */
class ReturnsService implements Returns
{
    private const HANDOFF = ['shipped', 'collected', 'done', 'delivered', 'completed'];

    public function eligibility(Order $order): array
    {
        $until = $this->windowEnd($order);
        $offered = $until !== null && $until->isFuture();
        $lines = [];
        if ($offered) {
            $held = $this->heldQuantities($order);
            foreach ($this->items($order) as $item) {
                $left = (int) $item->quantity - ($held[(int) $item->id] ?? 0);
                if ($left <= 0 || $item->item_id === null) {
                    continue;
                }
                $lines[] = [
                    'order_item_id' => (int) $item->id,
                    'name' => $item->displayName(),
                    'qty_returnable' => $left,
                    'unit_minor' => $this->unitMinor($item, $order),
                ];
            }
            $offered = $lines !== [];
        }

        return [
            'offered' => $offered,
            'until' => $until?->toIso8601String(),
            'resolutions' => $offered ? $this->resolutions((int) $order->store_id) : [],
            'lines' => $offered ? $lines : [],
        ];
    }

    public function request(Order $order, array $lines, string $resolution, ?string $reason = null): ReturnRequest
    {
        if (! in_array($resolution, $this->resolutions((int) $order->store_id), true)) {
            throw new \DomainException('resolution');
        }
        if ($lines === []) {
            throw new \DomainException('lines');
        }

        return DB::transaction(function () use ($order, $lines, $resolution, $reason) {
            // the order row lock serialises two requests for one order (the returnable quantity is read under it)
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new \DomainException('returns_closed');
            }
            $until = $this->windowEnd($locked);
            if ($until === null || ! $until->isFuture()) {
                throw new \DomainException('returns_closed');
            }
            $held = $this->heldQuantities($locked);
            $items = $this->items($locked)->keyBy('id');
            $wanted = [];
            foreach ($lines as $line) {
                $id = (int) $line['order_item_id'];
                $qty = (int) $line['qty'];
                $item = $items->get($id);
                if (! $item instanceof OrderItem || $item->item_id === null || $qty < 1) {
                    throw new \DomainException('lines');
                }
                $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
                if ($wanted[$id] > (int) $item->quantity - ($held[$id] ?? 0)) {
                    throw new \DomainException('line_qty');
                }
            }
            $amount = 0;
            $stored = [];
            foreach ($wanted as $id => $qty) {
                $amount += $this->unitMinor($items->get($id), $locked) * $qty;
                $stored[] = ['order_item_id' => $id, 'qty' => $qty];
            }

            $return = ReturnRequest::create([
                'store_id' => (int) $locked->store_id,
                'order_id' => (int) $locked->id,
                'customer_id' => $locked->customer_id ? (int) $locked->customer_id : null,
                'status' => 'requested',
                'resolution' => $resolution,
                'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
                'lines' => $stored,
                'amount_minor' => $amount,
                'currency_code' => $this->currency($locked),
            ]);
            log_activity('order.return_requested', $locked, ['order_id' => $locked->id, 'return' => $return->public_id, 'resolution' => $resolution], (int) $locked->store_id);

            return $return;
        });
    }

    public function decide(ReturnRequest $return, bool $approve, ?string $note = null): ReturnRequest
    {
        return $this->transition($return, ['requested'], $approve ? 'approved' : 'rejected', function (ReturnRequest $locked) use ($note) {
            $locked->decided_at = now();
            if ($note !== null && trim($note) !== '') {
                $locked->owner_note = mb_substr(trim($note), 0, 500);
            }
        });
    }

    public function receive(ReturnRequest $return, bool $restock = true): ReturnRequest
    {
        return $this->transition($return, ['approved'], 'received', function (ReturnRequest $locked) use ($restock) {
            if (! $restock) {
                return;
            }
            $items = OrderItem::query()->where('order_id', $locked->order_id)->get()->keyBy('id');
            foreach ($locked->lines as $line) {
                $item = $items->get((int) $line['order_item_id']);
                if (! $item instanceof OrderItem || $item->item_id === null) {
                    continue;
                }
                if ($item->variation) {
                    MenuVariant::restoreStock((int) $item->variation, (int) $line['qty']);
                } else {
                    Menu::restoreStock((int) $item->item_id, (int) $line['qty']);
                }
            }
        });
    }

    public function complete(ReturnRequest $return): ReturnRequest
    {
        $outcome = DB::transaction(function () use ($return) {
            $locked = ReturnRequest::query()->whereKey($return->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'received') {
                throw new \DomainException('status');
            }
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->first();
            if ($order === null) {
                throw new \DomainException('status');
            }
            if ($locked->resolution === 'exchange') {
                $locked->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

                return ['return' => $locked, 'order' => $order, 'adjustment' => null, 'amount' => 0.0, 'result' => null];
            }

            $currency = $this->currency($order);
            $amountMinor = min((int) $locked->amount_minor, $this->refundableMinor($order, $currency));
            if ($amountMinor <= 0) {
                throw new \DomainException('refund_cap');
            }
            $amount = Money::majorFromMinor($amountMinor, $currency);
            $result = null;

            if ($locked->resolution === 'refund') {
                if (! RefundSwitches::allows((int) $order->store_id, 'returns')) {
                    throw new \DomainException('resolution');
                }
                $result = app(Gateways::class)->refundOrder($order, $amount);
                if (! empty($result['refused'])) {
                    throw new \DomainException('refund_refused');
                }
            }

            $adjustment = OrderAdjustment::create([
                'order_id' => $order->id,
                'staff_id' => null,
                'pos_shift_id' => null,
                'type' => 'refund',
                'amount' => $amount,
                'method' => $locked->resolution === 'refund' ? 'online' : 'other',
                'reason' => $locked->resolution === 'refund' ? 'Return refund' : 'Return to store credit',
                'idempotency_key' => 'return:'.$locked->id,
            ]);

            if ($locked->resolution === 'store_credit') {
                $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
                if (! $customer instanceof Customer) {
                    throw new \DomainException('no_customer');
                }
                try {
                    CustomerWalletService::refundToCredit($customer, $amount, (int) $order->id, auth()->id(), $currency, 'adj:'.$adjustment->id);
                } catch (StoreCreditDisabledException) {
                    throw new \DomainException('store_credit_off');
                } catch (StoreCreditCurrencyMismatchException) {
                    throw new \DomainException('currency');
                }
            }

            $locked->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                $locked->resolution === 'refund' ? 'refunded_minor' : 'credited_minor' => $amountMinor,
            ])->save();
            $order->forceFill(['refund_method' => $locked->resolution === 'refund' ? 'original_payment' : 'store_credit'])->save();

            return ['return' => $locked, 'order' => $order, 'adjustment' => $adjustment, 'amount' => $amount, 'result' => $result];
        });

        /** @var ReturnRequest $done */
        $done = $outcome['return'];
        $order = $outcome['order'];
        if ($outcome['adjustment'] !== null && $done->resolution === 'refund') {
            // store credit books its own journal (refundToCredit); a gateway refund books here — reporting only
            try {
                app(GeneralLedger::class)->postRefund($order, (float) $outcome['amount'], 'adj:'.$outcome['adjustment']->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        log_activity('order.return_completed', $order, [
            'order_id' => $order->id,
            'return' => $done->public_id,
            'resolution' => $done->resolution,
            'amount' => $outcome['amount'],
        ], (int) $order->store_id);
        if ($outcome['adjustment'] !== null) {
            WebhookFanout::forRestaurant((int) $order->store_id, 'refund.completed', [
                'order_id' => $order->id,
                'amount' => $outcome['amount'],
                'method' => $done->resolution === 'refund' ? 'original_payment' : 'store_credit',
                'return' => $done->public_id,
            ]);
        }

        return $done;
    }

    /** @return list<string> */
    private function resolutions(int $storeId): array
    {
        return RefundSwitches::allows($storeId, 'returns')
            ? ['exchange', 'store_credit', 'refund']
            : ['exchange', 'store_credit'];
    }

    /** The end of the order's returns window, or null when none is offered (window 0, not handed over, cancelled). */
    private function windowEnd(Order $order): ?CarbonImmutable
    {
        $days = StoreSettings::int((int) $order->store_id, 'returns_window_days');
        if ($days <= 0 || ! in_array((string) $order->status, self::HANDOFF, true) || $order->cancelled_at !== null) {
            return null;
        }
        $from = null;
        foreach (['delivered_at', 'collected_at', 'shipped_at', 'completed_at'] as $column) {
            $at = $order->getAttribute($column);
            if ($at !== null) {
                $at = CarbonImmutable::parse($at);
                $from = $from === null || $at->lt($from) ? $at : $from;
            }
        }
        if ($from === null) {
            return null;
        }

        return $from->addDays($days);
    }

    /** @return Collection<int, OrderItem> */
    private function items(Order $order): Collection
    {
        return OrderItem::query()->where('order_id', $order->id)->with(['menu', 'variant', 'itemExtras'])->orderBy('id')->get();
    }

    /** @return array<int, int> order item id => quantity held by open or completed requests */
    private function heldQuantities(Order $order): array
    {
        $held = [];
        $rows = ReturnRequest::query()->where('order_id', $order->id)
            ->whereIn('status', [...ReturnRequest::OPEN, 'completed'])->get(['lines']);
        foreach ($rows as $row) {
            foreach ((array) $row->lines as $line) {
                $id = (int) $line['order_item_id'];
                $held[$id] = ($held[$id] ?? 0) + (int) $line['qty'];
            }
        }

        return $held;
    }

    private function unitMinor(OrderItem $item, Order $order): int
    {
        return Money::minorFromMajor($item->soldUnitPrice(), $this->currency($order));
    }

    private function currency(Order $order): string
    {
        return strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
    }

    /** What is left to refund on the order: what was collected minus every refund row so far (minor units). */
    private function refundableMinor(Order $order, string $currency): int
    {
        $due = Money::minorFromMajor((float) $order->amount_due, $currency);
        $already = Money::minorFromMajor((float) OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')
            ->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))), $currency);
        if ($order->refund_method && $already === 0) {
            return 0; // refunded before refunds were rows: counts as fully refunded
        }

        return max(0, $due - $already);
    }

    /**
     * @param  list<string>  $from
     * @param  callable(ReturnRequest): void  $apply
     */
    private function transition(ReturnRequest $return, array $from, string $to, callable $apply): ReturnRequest
    {
        $locked = DB::transaction(function () use ($return, $from, $to, $apply) {
            $locked = ReturnRequest::query()->whereKey($return->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, $from, true)) {
                throw new \DomainException('status');
            }
            $locked->status = $to;
            $apply($locked);
            $locked->save();

            return $locked;
        });
        log_activity('order.return_'.$to, $locked, ['order_id' => $locked->order_id, 'return' => $locked->public_id], (int) $locked->store_id);

        return $locked;
    }
}
