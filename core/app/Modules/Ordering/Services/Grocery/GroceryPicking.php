<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Grocery;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Services\Payments\CardHoldOrders;

/**
 * P3-09 (REVIEW, Opus: money; basket §8–§10, API-22 / API-25 / API-30 / API-31): the picker flow of a grocery order,
 * driven from the owner / staff picking screen.
 *
 *  - {@see start()}: the order goes into `picking` (only an order that is going ahead: not unpaid, cancelled or done).
 *  - Per line, by the line's picker action (snapshotted at order time from the shopper's out-of-stock choice):
 *    {@see weigh()} a weighed line (final = estimate × actual / ordered grams, never above the line's cap),
 *    {@see pickAsOrdered()}, {@see remove()} (`remove_item`), {@see propose()} a replacement (`offer_replacement`; the
 *    shopper answers on the tracking page with {@see answer()}), {@see callOutcome()} for `call_customer` (answered:
 *    replace or remove; no answer: the option's fallback, API-30).
 *  - {@see finish()}: proposals left unanswered follow the order's preference (its option's action, a call_customer
 *    preference its fallback); every weighed line must be weighed or removed; then the money is settled
 *    ({@see CardHoldOrders::settleFinal()}: capture ≤ authorised, or the up-front refund) and the shopper is told (N4).
 *  - {@see removeAfterFinish()}: a line taken off after the money was captured is always refunded (API-31).
 *
 * Every step runs under the order's row lock and re-checks the state, so a double click never applies twice. A
 * replacement is never priced above the line it replaces (the shopper authorised that amount at most).
 *
 * @phpstan-type Outcome array{outcome: string, final_minor: int, released_minor: int, refunded_minor: int}
 */
final class GroceryPicking
{
    public const STATUS_PICKING = 'picking';

    public const STATUS_PICKED = 'picked';

    /** Order statuses that are not (or no longer) going ahead. */
    private const CLOSED = ['unpaid', 'cancelled', 'rejected', 'declined', 'completed', 'delivered', 'pending_approval'];

    public function __construct(
        private readonly CardHoldOrders $holds,
        private readonly GroceryNotices $notices,
    ) {}

    public function start(Order $order): void
    {
        $this->locked($order, function (Order $locked) {
            if ($locked->picking_status === null) {
                $locked->forceFill(['picking_status' => self::STATUS_PICKING])->save();
                log_activity('order.picking_started', $locked, ['order_id' => $locked->id], (int) $locked->store_id);
            }
        }, picking: false);
    }

    /** A weighed line weighed on the scale: `$actualGrams` for the whole line (all units). */
    public function weigh(Order $order, int $itemId, int $actualGrams): int
    {
        if ($actualGrams < 1 || $actualGrams > 1_000_000) {
            throw new GroceryPickingException(___('Enter the weight in grams.'));
        }

        return $this->locked($order, function (Order $locked) use ($itemId, $actualGrams) {
            $line = $this->line($locked, $itemId);
            $orderedGrams = (int) $line->weight_g * max(1, (int) $line->quantity);
            if ((int) $line->weight_g < 1) {
                throw new GroceryPickingException(___('This item is not sold by weight.'));
            }
            $this->assertOpenLine($line);
            $estimate = $this->estimateMinor($locked, $line);
            $final = intdiv($estimate * $actualGrams * 2 + $orderedGrams, $orderedGrams * 2); // half up
            $cap = $line->cap_minor !== null ? (int) $line->cap_minor : $estimate;
            $final = min($final, $cap); // never above what the shopper authorised for this line

            $line->forceFill(['actual_g' => $actualGrams, 'final_minor' => $final, 'pick_status' => 'picked', 'picked_at' => now()])->save();

            return $final;
        });
    }

    public function pickAsOrdered(Order $order, int $itemId): void
    {
        $this->locked($order, function (Order $locked) use ($itemId) {
            $line = $this->line($locked, $itemId);
            if ((int) $line->weight_g > 0) {
                throw new GroceryPickingException(___('Weigh this item first.'));
            }
            $this->assertOpenLine($line);
            $line->forceFill(['pick_status' => 'picked', 'picked_at' => now()])->save();
        });
    }

    /** `remove_item` (or the shopper refused a replacement): the line is worth nothing. */
    public function remove(Order $order, int $itemId): void
    {
        $this->locked($order, function (Order $locked) use ($itemId) {
            $line = $this->line($locked, $itemId);
            $this->assertOpenLine($line, allowProposed: true);
            $line->forceFill(['final_minor' => 0, 'pick_status' => 'removed', 'picked_at' => now()])->save();
        });
    }

    /**
     * `offer_replacement`: the picker proposes `$name` at `$priceMinor` (never above the ordered line). The shopper
     * keeps or refuses it on the tracking page; unanswered at {@see finish()}, the order preference decides.
     */
    public function propose(Order $order, int $itemId, string $name, int $priceMinor, ?int $replacementItemId = null, bool $notify = true): void
    {
        $name = trim(strip_tags($name));
        if ($name === '' || mb_strlen($name) > 190) {
            throw new GroceryPickingException(___('Enter the replacement name.'));
        }
        $this->locked($order, function (Order $locked) use ($itemId, $name, $priceMinor, $replacementItemId) {
            $line = $this->line($locked, $itemId);
            $this->assertOpenLine($line, allowProposed: true);
            $ceiling = $line->cap_minor !== null ? (int) $line->cap_minor : $this->estimateMinor($locked, $line);
            if ($priceMinor < 0 || $priceMinor > $ceiling) {
                throw new GroceryPickingException(___('A replacement cannot cost more than the item it replaces.'));
            }
            $line->forceFill([
                'pick_status' => 'proposed',
                'replacement_name' => $name,
                'replacement_minor' => $priceMinor,
                'replacement_item_id' => $replacementItemId,
                'replacement_answer' => null,
                'picked_at' => now(),
            ])->save();
        });
        if ($notify) {
            $this->notices->replacementNeeded($order->refresh());
        }
    }

    /**
     * `call_customer`: the shopper answered (`replace` with a replacement they accepted on the phone, or `remove`), or
     * did not (`no_answer` → the option's fallback: `offer_replacement` proposes, `remove_item` removes; API-30).
     */
    public function callOutcome(Order $order, int $itemId, string $outcome, ?string $name = null, ?int $priceMinor = null): string
    {
        $line = $this->line($order, $itemId);
        if ($outcome === 'no_answer') {
            $fallback = (string) ($line->picker_fallback ?: 'remove_item');
            if ($fallback === 'offer_replacement' && $name !== null && $priceMinor !== null) {
                $this->propose($order, $itemId, $name, $priceMinor);

                return 'proposed';
            }
            $this->remove($order, $itemId);

            return 'removed';
        }
        if ($outcome === 'remove') {
            $this->remove($order, $itemId);

            return 'removed';
        }
        if ($outcome === 'replace' && $name !== null && $priceMinor !== null) {
            $this->propose($order, $itemId, $name, $priceMinor, null, false); // agreed on the phone: nothing to review
            $this->answer($order, $itemId, 'kept');

            return 'replaced';
        }
        throw new GroceryPickingException(___('Choose what the customer said.'));
    }

    /** The shopper's answer to a proposed replacement (`kept` | `refused`), while the order is still being picked. */
    public function answer(Order $order, int $itemId, string $answer): string
    {
        if (! in_array($answer, ['kept', 'refused'], true)) {
            throw new GroceryPickingException(___('Choose Keep replacement or Refund instead.'));
        }

        return $this->locked($order, function (Order $locked) use ($itemId, $answer) {
            $line = $this->line($locked, $itemId);
            if ($line->pick_status !== 'proposed') {
                throw new GroceryPickingException(___('This change was already settled.'));
            }
            $this->applyAnswer($line, $answer);

            return $answer;
        });
    }

    /**
     * Picking done: unanswered proposals follow the order preference, the money is settled and the shopper told.
     *
     * @return Outcome|null null for a pay-later order (the final amount is collected on delivery / collection)
     */
    public function finish(Order $order): ?array
    {
        $removedMinor = $this->locked($order, function (Order $locked) {
            $removed = 0;
            $preference = $this->preferenceAnswer($locked);
            foreach ($locked->items()->orderBy('id')->get() as $line) {
                if ((int) $line->weight_g > 0 && $line->final_minor === null && $line->pick_status !== 'proposed') {
                    throw new GroceryPickingException(___('Weigh every item sold by weight first.'));
                }
                if ($line->pick_status === 'proposed') {
                    $this->applyAnswer($line, $preference);
                }
                if ($line->pick_status === 'removed') {
                    $removed += $this->orderedMinor($locked, $line);
                }
            }
            $locked->forceFill(['picking_status' => self::STATUS_PICKED, 'picked_at' => now()])->save();
            log_activity('order.picked', $locked, ['order_id' => $locked->id], (int) $locked->store_id);

            return $removed;
        });

        $result = $this->holds->settleFinal($order->refresh(), $removedMinor);
        if ($result !== null) {
            if ($result['outcome'] === 'captured' && $result['released_minor'] > 0) {
                $this->notices->finalAmount($order, $result['final_minor'], $result['released_minor']);
            } elseif ($result['outcome'] === 'refunded') {
                $this->notices->finalAmountRefunded($order, $result['refunded_minor']);
            }
        }

        return $result;
    }

    /** API-31: a line taken off after picking finished (the money was captured) is always refunded. */
    public function removeAfterFinish(Order $order, int $itemId): int
    {
        [$lineMinor, $line] = $this->locked($order, function (Order $locked) use ($itemId) {
            $line = $this->line($locked, $itemId);
            if ($line->pick_status === 'removed') {
                throw new GroceryPickingException(___('This item was already removed.'));
            }
            $minor = $this->estimateMinor($locked, $line);
            $line->forceFill(['final_minor' => 0, 'pick_status' => 'removed', 'picked_at' => now()])->save();

            return [$minor, $line];
        }, picking: false, picked: true);

        $refunded = $this->holds->refundRemovedLine($order, $lineMinor, 'removed-line:'.$line->id);
        if ($refunded > 0) {
            $line->forceFill(['refunded_minor' => $refunded])->save();
            $this->notices->finalAmountRefunded($order->refresh(), $refunded);
        }

        return $refunded;
    }

    private function applyAnswer(OrderItem $line, string $answer): void
    {
        if ($answer === 'kept') {
            $line->forceFill(['replacement_answer' => 'kept', 'pick_status' => 'replaced', 'final_minor' => (int) $line->replacement_minor])->save();

            return;
        }
        $line->forceFill(['replacement_answer' => 'refused', 'pick_status' => 'removed', 'final_minor' => 0])->save();
    }

    /** An unanswered replacement follows the order's preference: keep it unless the preference removes items. */
    private function preferenceAnswer(Order $order): string
    {
        $optionId = $order->getAttribute('substitution_option_id');
        if ($optionId === null) {
            return 'refused'; // no preference recorded: the shopper never agreed to replacements
        }
        $option = DB::table('modifiers')->where('id', (int) $optionId)->first(['picker_action', 'picker_fallback']);
        if ($option === null) {
            return 'refused';
        }
        $action = (string) $option->picker_action;
        if ($action === 'call_customer') {
            $action = (string) ($option->picker_fallback ?: 'remove_item');
        }

        return $action === 'offer_replacement' ? 'kept' : 'refused';
    }

    /**
     * @template T
     *
     * @param  \Closure(Order): T  $fn
     * @return T
     */
    private function locked(Order $order, \Closure $fn, bool $picking = true, bool $picked = false): mixed
    {
        return DB::transaction(function () use ($order, $fn, $picking, $picked) {
            $locked = Order::query()->where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new GroceryPickingException(___('Order not found.'));
            }
            if ($picked) {
                if ($locked->picking_status !== self::STATUS_PICKED) {
                    throw new GroceryPickingException(___('Finish picking first.'));
                }
            } elseif (in_array((string) $locked->status, self::CLOSED, true)) {
                throw new GroceryPickingException(___('This order cannot be picked now.'));
            } elseif ($picking && $locked->picking_status !== self::STATUS_PICKING) {
                throw new GroceryPickingException($locked->picking_status === self::STATUS_PICKED
                    ? ___('Picking is already finished.') : ___('Start picking first.'));
            }

            return $fn($locked);
        });
    }

    private function line(Order $order, int $itemId): OrderItem
    {
        $line = OrderItem::query()->where('order_id', $order->id)->whereKey($itemId)->first();
        if ($line === null) {
            throw new GroceryPickingException(___('Item not found in this order.'));
        }

        return $line;
    }

    private function assertOpenLine(OrderItem $line, bool $allowProposed = false): void
    {
        $status = $line->pick_status;
        if ($status === 'replaced' || $status === 'removed' || ($status === 'proposed' && ! $allowProposed)) {
            throw new GroceryPickingException(___('This item was already settled.'));
        }
    }

    /** The line as ordered (the estimate of a weighed line), in minor units — ignoring any picked final amount. */
    private function estimateMinor(Order $order, OrderItem $line): int
    {
        $copy = $order->replicate();
        $copy->id = $order->id;
        $copy->exists = true;
        $copy->setRelation('items', OrderItem::query()->whereKey($line->id)->get()->each(fn (OrderItem $i) => $i->setAttribute('final_minor', null)));
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));

        return Money::minorFromMajor((float) $copy->itemsSubtotal(), $currency);
    }

    /** What a removed line took off the basket: the line as ordered. */
    private function orderedMinor(Order $order, OrderItem $line): int
    {
        return $this->estimateMinor($order, $line);
    }
}
