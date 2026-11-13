<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\StaffMember;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Support\OrderCreatedWebhookPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Juwri\Modules\Ordering\Services\Tax\TaxByRate;
use Juwri\Modules\Ordering\Services\Tax\TaxSnapshot;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\TableCheck;

/**
 * POS P1: staff-facing register operations. Order assembly + server-side pricing
 * is delegated to OrderApiService (single source of truth); this service adds only
 * POS concerns — send-to-kitchen vs hold, cash tender/change, and resume (which
 * applies the side-effects that were deferred while the order was parked).
 */
class PosService
{
    /**
     * Ring an order from the register. `held` parks it (no KDS, no inventory);
     * otherwise it is sent to the kitchen immediately. Prices are always computed
     * server-side by OrderApiService — the client sends ids + quantities only.
     */
    public static function createOrder(Store $store, ?StaffMember $staff, array $data): Order
    {
        $data['source'] = 'pos';
        $data['created_by_staff_id'] = $staff?->id;
        if ($staff && $staff->branch_id && empty($data['branch_id'])) {
            $data['branch_id'] = $staff->branch_id;
        }

        [$order, $replayed] = OrderApiService::createOrReplay((int) $store->user_id, (int) $store->id, $data);

        // J27-A2: a resent register sale (retry / offline replay) is the order already rung — its KDS ticket and
        // tax snapshot were done the first time.
        if ($replayed) {
            return $order->refresh();
        }

        // A sent (not held) order must appear on the KDS — derive its ticket status
        // ('new') from the queued items, which OrderApiService leaves at 'pending'.
        if (empty($data['held'])) {
            KdsService::syncOrderStatus($order->id);
        }

        // D7: freeze the applied taxes on the fresh order so the receipt/invoice
        // never re-price an old sale after a rate change. Idempotent + null-safe.
        TaxSnapshot::stamp($order->refresh());

        return $order->refresh();
    }

    /**
     * Settle payment. For cash, records tender and computes change from the live
     * order total. Card/other just mark paid (physical terminal is external).
     */
    public static function settle(Order $order, string $method, ?float $tendered = null, ?float $tip = null): Order
    {
        // A held order deferred its stock deduction (hold() restored it). Settling it
        // directly — without resume() first — used to clear held_at but never deduct,
        // so a real paid sale left stock untouched (oversell). Apply the deferred
        // deduction, exactly as resume() does.
        $wasHeld = $order->held_at !== null;

        $order = DB::transaction(function () use ($order, $method, $tendered, $tip, $wasHeld) {
            if ($tip !== null) {
                $order->tip_amount = $tip;
            }
            /* P6-330: change is owed on what the guest still has to pay — total minus
               the store credit / gift card / platform wallet already applied at order
               creation — not on the gross total (re-read after any tip change). */
            $due = (float) $order->amount_due;

            $order->payment_method = $method;
            if ($method === 'cash') {
                $order->amount_tendered = $tendered;
                $order->change_due = $tendered !== null ? Money::round(max(0, $tendered - $due), money_decimals(store_currency((int) $order->store_id))) : null; // MC-22: store decimals
            } else {
                $order->amount_tendered = null;
                $order->change_due = null;
            }

            /* Stamp the open shift for EVERY method, not just cash. The
               Z-report is built entirely from orders carrying pos_shift_id, so
               while this lived inside the cash branch a shift closed showing
               only its cash takings: card and online sales, the tips paid on
               them and their refunds were all absent from sales_by_method and
               from the tips total. Expected-cash math is unaffected — it
               filters on payment_method='cash' separately. */
            $shift = self::currentShift((int) $order->store_id, $order->branch_id ?: null);
            if ($shift) {
                $order->pos_shift_id = $shift->id;
            }
            $order->is_paid = 1;
            $order->held_at = null; // a settled order can't stay parked in the On-hold tab
            if ($wasHeld) {
                // No longer "stock returned" — a later void may restore it again (mirrors resume()).
                $order->stock_restored_at = null;
            }
            $order->save();

            return $order;
        });

        // Deduct the stock hold() deferred (post-commit, mode-aware) — only when the
        // order was actually held; a normal settle already deducted at creation.
        if ($wasHeld) {
            self::applyDeferredDeduction($order);
        }

        /* Loyalty accrual on the PAID POS sale. POS orders are open tabs until
           settled, so points accrue here (not at tab-open) — the register's
           "earns ~N" preview finally credits. Idempotent per order + self-guarded,
           and post-commit so it can never fail the settlement. */
        LoyaltyAccrual::earnForOrder($order);

        self::postSaleToLedger($order);

        return $order;
    }

    /**
     * A register sale is revenue, so it reaches the books like a gateway-paid order (no-op unless accounting_gl is on;
     * idempotent per order). Reporting only — it never fails the settlement. Also used by the split-bill settle.
     */
    public static function postSaleToLedger(Order $order): void
    {
        try {
            app(GeneralLedger::class)->postOrderPaid($order);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── Shifts (P2) ───────────────────────────────────────────────────────────

    /** The currently-open shift for a restaurant + branch, if any. */
    public static function currentShift(int $storeId, ?int $branchId): ?PosShift
    {
        return PosShift::where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Open a drawer shift. One open shift per (restaurant, branch) — enforced here.
     *
     * P6-342: the "no open shift" check and the insert run in one transaction under a row
     * lock on the store, so two opens at once (double tap, two tills, an offline replay
     * beside an online open) serialise: the second sees the first one's shift and is
     * refused instead of opening a second drawer. The webhook fires after the commit.
     */
    public static function openShift(Store $store, ?StaffMember $staff, ?int $branchId, float $openingFloat): PosShift
    {
        $branchId = $branchId ?: ($staff?->branch_id ?: null);

        $shift = DB::transaction(function () use ($store, $staff, $branchId, $openingFloat) {
            Store::query()->whereKey($store->id)->lockForUpdate()->value('id');
            $open = PosShift::where('store_id', $store->id)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->lockForUpdate()->exists();
            if ($open) {
                throw new \RuntimeException('A shift is already open for this drawer.');
            }

            return PosShift::create([
                'store_id' => $store->id,
                'branch_id' => $branchId,
                'staff_id' => $staff->id ?? 0,
                'opened_at' => now(),
                'opening_float' => $openingFloat,
                'status' => 'open',
            ]);
        });

        WebhookFanout::forRestaurant((int) $store->id, 'shift.opened', ['shift_id' => $shift->id, 'branch_id' => $branchId, 'staff_id' => $staff->id ?? 0]); // HPMS E10

        return $shift;
    }

    /**
     * D2 — drawer handoff. Close the current open shift (counted cash → over/short,
     * the same Z idiom as a normal close) and, in the SAME transaction, open a fresh
     * shift for the next cashier with that counted amount as its opening float, linked
     * back via handoff_from_shift_id. The drawer never leaves accountability: every
     * banknote is signed off by the outgoing cashier before the incoming one owns it.
     *
     * Idempotent: a replayed POST carrying the same idempotency_key returns the shift
     * it already created rather than closing a second time.
     *
     * @return array{closed: array<string, mixed>, shift: PosShift}
     */
    public static function handoffShift(
        Store $store,
        ?StaffMember $toStaff,
        float $countedCash,
        ?int $branchId,
        ?string $idempotencyKey = null
    ): array {
        $replay = fn () => $idempotencyKey ? self::handoffByKey((int) $store->id, $idempotencyKey) : null;
        if ($hit = $replay()) {
            return $hit;
        }

        /* P6-332: the open-shift lookup, the close and the new shift all happen under
           a row lock on the drawer's open shift, so two handoff posts (double tap, two
           devices) serialise: the second sees the first one's committed result instead
           of closing the same shift again and opening a second drawer. */
        $done = DB::transaction(function () use ($store, $toStaff, $countedCash, $branchId, $idempotencyKey, $replay) {
            $current = PosShift::where('store_id', $store->id)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->orderByDesc('opened_at')->orderByDesc('id')
                ->lockForUpdate()->first();

            // Re-check the key inside the lock: a racing replay that committed first is visible now.
            if ($hit = $replay()) {
                return $hit + ['fresh' => false];
            }
            if (! $current) {
                throw new \RuntimeException('No open shift to hand over.');
            }
            /* The drawer already belongs to this cashier through a handoff: a second post
               without a key (double tap, offline re-send) must not close their fresh shift
               and open yet another one — return what the first post produced. */
            if ($current->handoff_from_shift_id && $toStaff && (int) $current->staff_id === (int) $toStaff->id) {
                $from = PosShift::find($current->handoff_from_shift_id);

                return ['closed' => $from ? self::zReport($from) : [], 'shift' => $current, 'fresh' => false];
            }

            $z = self::closeLocked($current, $countedCash);

            $new = PosShift::create([
                'store_id' => $store->id,
                'branch_id' => $current->branch_id, // the same physical drawer
                'staff_id' => $toStaff->id ?? 0,
                'handoff_from_shift_id' => $current->id,
                'opened_at' => now(),
                'opening_float' => $countedCash, // the counted cash carries straight over
                'status' => 'open',
                'meta' => $idempotencyKey ? ['handoff_key' => $idempotencyKey] : null,
            ]);

            return ['closed' => $z, 'shift' => $new, 'fresh' => true, 'from' => $current];
        });

        if ($done['fresh']) {
            self::afterClose($done['from'], $done['closed']);
        }

        return ['closed' => $done['closed'], 'shift' => $done['shift']];
    }

    /**
     * The shift a handoff with this idempotency key already created, if any.
     *
     * @return array{closed: array<string, mixed>, shift: PosShift}|null
     */
    private static function handoffByKey(int $storeId, string $key): ?array
    {
        $existing = PosShift::where('store_id', $storeId)
            ->whereNotNull('handoff_from_shift_id')
            ->latest('id')->take(25)->get()
            ->first(fn ($s) => (($s->meta['handoff_key'] ?? null) === $key));
        if (! $existing) {
            return null;
        }
        $from = PosShift::find($existing->handoff_from_shift_id);

        return ['closed' => $from ? self::zReport($from) : [], 'shift' => $existing];
    }

    /**
     * Close a shift: compute expected vs counted cash → over/short, return the Z-report.
     *
     * P6-332: the shift row is locked and its status re-checked inside the transaction.
     * A second close (another manager, a double tap, an offline re-send) of a shift that
     * is already closed returns the FIRST Z-report unchanged: the count, over/short and
     * closed_at written by the first close are never overwritten, and the print and the
     * shift.closed webhook fire once.
     */
    public static function closeShift(PosShift $shift, float $countedCash): array
    {
        $done = DB::transaction(function () use ($shift, $countedCash) {
            $locked = PosShift::whereKey($shift->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new \RuntimeException('Shift not found.');
            }
            if ($locked->status !== 'open') {
                return ['z' => self::zReport($locked) + ['already_closed' => true], 'shift' => $locked, 'fresh' => false];
            }

            return ['z' => self::closeLocked($locked, $countedCash), 'shift' => $locked, 'fresh' => true];
        });

        $shift->setRawAttributes($done['shift']->getAttributes(), true);
        if ($done['fresh']) {
            self::afterClose($done['shift'], $done['z']);
        }

        return $done['z'];
    }

    /**
     * Write the close onto a shift row the caller holds locked and has seen open.
     *
     * @return array<string, mixed>
     */
    private static function closeLocked(PosShift $shift, float $countedCash): array
    {
        $expected = self::expectedCash($shift);
        $shift->closed_at = now();
        $shift->expected_cash = $expected;
        $shift->counted_cash = $countedCash;
        $shift->over_short = Money::round($countedCash - $expected, money_decimals(store_currency((int) $shift->store_id))); // MC-22: store decimals
        $shift->status = 'closed';
        $shift->save();

        return self::zReport($shift->refresh());
    }

    /**
     * Side effects of a close, run once, after the close committed.
     *
     * @param  array<string, mixed>  $z
     */
    private static function afterClose(PosShift $shift, array $z): void
    {
        /* PARITY-RUN T1: shift-close summary to any `shift_close` profile. The
           printed numbers are literally this array, so paper and screen can
           never disagree. Best-effort: a dead printer must not block a close. */
        try {
            PrintDispatcher::onShiftClosed($shift, $z);
        } catch (\Throwable $e) {
        }

        WebhookFanout::forRestaurant((int) $shift->store_id, 'shift.closed', ['shift_id' => $shift->id, 'over_short' => $shift->over_short]); // HPMS E10
    }

    /** expected drawer cash = opening float + cash sales − cash refunds in the shift. */
    private static function expectedCash(PosShift $shift): float
    {
        $cashSales = (float) Order::where('pos_shift_id', $shift->id)
            ->where('payment_method', 'cash')->where('is_paid', 1)->get()
            // P6-330: only what was paid in cash — the store credit, gift card or platform
            // wallet applied to the bill never entered the drawer.
            ->sum(fn ($o) => (float) $o->amount_due);

        /* Split bills settle share by share, each with its own method, so the
           order carries payment_method 'split' and is skipped above. Its CASH
           shares are still money in the drawer — without this a cashier could
           take a table's cash on a split bill and the till would still balance,
           because the sale was invisible to expected cash. */
        $splitOrderIds = Order::where('pos_shift_id', $shift->id)
            ->where('payment_method', 'split')->pluck('id');
        $cashSplitShares = (float) CheckPayment::where('status', 'paid')
            ->where('method', 'cash')
            ->whereIn('table_check_id', TableCheck::whereIn('order_id', $splitOrderIds)->pluck('id'))
            ->sum(DB::raw(CheckPayment::minorMoneySql('amount'))); // P1-05: per-row exponent

        /* Cash refunds are attributed to the shift they were ISSUED in (the drawer the
           cash came out of), stamped on the adjustment at refund time — NOT the order's
           settle shift. A refund on an order settled in an earlier, now-closed shift
           belongs to today's open drawer, and must not vanish from its reconciliation. */
        $cashRefunds = (float) OrderAdjustment::where('type', 'refund')->where('method', 'cash')
            ->where('pos_shift_id', $shift->id)
            ->sum(DB::raw(OrderAdjustment::minorMoneySql('amount')));

        return Money::round((float) $shift->opening_float + $cashSales + $cashSplitShares - $cashRefunds, money_decimals(store_currency((int) $shift->store_id))); // MC-22: store decimals
    }

    /**
     * Z-report data for a shift (sales by method, tips, voids/refunds, over/short).
     *
     * P6-260: split bills are itemised by their paid shares' methods (a remainder no share covers stays under
     * 'split'); net / tax / discount / service totals and per-rate tax lines; refunds per method; and
     * `cash_reconciliation` = opening float + cash sales − cash refunds, the same money expectedCash() counts.
     * All sums in minor units of the store currency, so the lines add up exactly.
     */
    public static function zReport(PosShift $shift): array
    {
        $currency = store_currency((int) $shift->store_id);
        $minor = fn ($v): int => Money::minorFromMajor((float) $v, $currency);
        $major = fn (int $m): float => Money::majorFromMinor($m, $currency);

        $orders = Order::where('pos_shift_id', $shift->id)->where('is_paid', 1)->with('items')->orderBy('id')->get();
        $byMethod = [];
        $splitShares = [];
        $net = $tax = $discount = $service = $tips = 0;
        $splitIds = $orders->where('payment_method', 'split')->pluck('id');
        $checkOrder = $splitIds->isEmpty() ? collect() : TableCheck::whereIn('order_id', $splitIds->all())->pluck('order_id', 'id');
        $shares = $checkOrder->isEmpty() ? collect() : CheckPayment::where('status', 'paid')
            ->whereIn('table_check_id', $checkOrder->keys()->all())->orderBy('id')->get()
            ->groupBy(fn ($p) => (int) $checkOrder[$p->table_check_id]);
        $tenders = ['wallet_applied' => 'store_credit', 'gift_card_applied' => 'gift_card', 'mk_wallet_applied' => 'platform_wallet'];
        foreach ($orders as $o) {
            /* P6-330: the settle method gets only what was still due; store credit, gift
               card and platform wallet applied to the bill are their own lines, so the
               cash line matches the drawer and the lines still add up to the gross sales. */
            $m = $o->payment_method ?: 'other';
            $total = $minor($o->amount_due);
            foreach ($tenders as $col => $line) {
                $v = $minor($o->{$col} ?? 0);
                if ($v > 0) {
                    $byMethod[$line] = ($byMethod[$line] ?? 0) + $v;
                }
            }
            if ($m === 'split') {
                foreach ($shares->get($o->id, collect()) as $p) {
                    $pm = (string) ($p->method ?: 'other');
                    $amt = $minor($p->amount);
                    $byMethod[$pm] = ($byMethod[$pm] ?? 0) + $amt;
                    $splitShares[$pm] = ($splitShares[$pm] ?? 0) + $amt;
                    $total -= $amt;
                }
                if ($total === 0) {
                    continue;
                }
            }
            $byMethod[$m] = ($byMethod[$m] ?? 0) + $total;
        }
        foreach ($orders as $o) {
            $money = $o->computeMoney();
            $t = $minor($money['tax']);
            $tip = $minor($o->tip_amount ?? 0);
            $tax += $t;
            $tips += $tip;
            $net += $minor($o->total) - $t - $tip;
            $discount += $minor($o->discount_amount ?? 0);
            $service += $minor($o->service_charge_amount ?? 0);
        }
        // Refunds are attributed to the shift they were issued in (see expectedCash),
        // so the Z-report's refund total matches the cash that left THIS drawer.
        $refundsByMethod = [];
        foreach (OrderAdjustment::where('type', 'refund')->where('pos_shift_id', $shift->id)->get() as $r) {
            $rm = (string) ($r->method ?: 'other');
            $refundsByMethod[$rm] = ($refundsByMethod[$rm] ?? 0) + $minor($r->amount);
        }
        $refunds = array_sum($refundsByMethod);
        // P6-331: voids are stamped with the drawer open at void time (see void()), so the
        // Z-report counts the voids THIS shift made — keyed on pos_shift_id, not the
        // (unpaid) order's settle shift, which never carries one.
        $voidRows = OrderAdjustment::where('type', 'void')->where('pos_shift_id', $shift->id)->get();
        $voids = $voidRows->count();
        $voidsTotal = $voidRows->sum(fn ($v): int => $minor($v->amount));
        $opening = $minor($shift->opening_float);
        $cashExpected = $opening + ($byMethod['cash'] ?? 0) - ($refundsByMethod['cash'] ?? 0);

        return [
            'shift_id' => $shift->id,
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
            'opening_float' => (float) $shift->opening_float,
            'orders' => $orders->count(),
            'sales_by_method' => array_map($major, $byMethod),
            'method_labels' => array_combine(array_keys($byMethod), array_map(fn ($m) => payment_method_label((string) $m), array_keys($byMethod))) ?: [],
            'split_shares_by_method' => array_map($major, $splitShares),
            'gross_sales' => $major($net + $tax + $tips),
            'net_sales' => $major($net),
            'tax_total' => $major($tax),
            'tax_lines' => TaxByRate::major($orders, (int) $shift->store_id, $currency),
            'discount_total' => $major($discount),
            'service_total' => $major($service),
            'tips' => $major($tips),
            'refunds_total' => $major($refunds),
            'refunds_by_method' => array_map($major, $refundsByMethod),
            'voids' => $voids,
            'voids_total' => $major((int) $voidsTotal),
            'cash_reconciliation' => $major($cashExpected),
            'expected_cash' => $shift->expected_cash !== null ? (float) $shift->expected_cash : self::expectedCash($shift),
            'counted_cash' => $shift->counted_cash !== null ? (float) $shift->counted_cash : null,
            'over_short' => $shift->over_short !== null ? (float) $shift->over_short : null,
        ];
    }

    // ── Voids / refunds (P2, append-only) ─────────────────────────────────────

    /**
     * Void an unpaid order (or a single item). Cancels it so it leaves the kitchen,
     * restores quick stock, and writes an immutable audit row. Refuse a paid order
     * (use refund instead).
     */
    public static function void(Order $order, ?StaffMember $staff, string $reason, ?int $orderItemId = null): OrderAdjustment
    {
        return DB::transaction(function () use ($order, $staff, $reason, $orderItemId) {
            // Lock the row so a concurrent / replayed void serialises against this one.
            $order = Order::whereKey($order->id)->lockForUpdate()->first() ?? $order;

            // Idempotent full-void replay: the order is already cancelled, so re-running
            // would double-restore stock (Menu::restoreStock is a raw increment, not keyed
            // per order) and write a second 'void' adjustment (inflating the Z-report's
            // voids total). The POS offline queue re-POSTs a void on reconnect and a
            // cancelled order is still is_paid=0 (the endpoint's only guard) + still
            // fetchable by id, so this replay is reachable. Return the recorded void.
            if (! $orderItemId && $order->status === 'cancelled') {
                $existing = OrderAdjustment::where('order_id', $order->id)->where('type', 'void')
                    ->whereNull('order_item_id')->latest('id')->first();
                if ($existing) {
                    return $existing;
                }
            }

            $fullTotal = (float) $order->total;
            $amount = $fullTotal;
            // A held order never deducted stock (deduction is deferred to resume),
            // so restoring on void would over-count.
            $restock = $order->held_at === null;

            if ($orderItemId) {
                $item = $order->items->firstWhere('id', $orderItemId);
                $amount = 0.0; // nothing voided unless the line is actually found
                if ($item) {
                    if ($restock) {
                        self::restoreLineStock($item, $order);
                    }
                    $item->delete();
                    // The voided value of ONE line is the drop it causes in the order
                    // total (so tax/discount are apportioned exactly) — not the whole
                    // order, which is what recording $order->total up front did.
                    $dec = money_decimals(store_currency((int) $order->store_id));
                    $amount = Money::round($fullTotal - (float) $order->fresh()->total, $dec);
                }
            } else {
                if ($restock && $order->stock_restored_at === null) {
                    $order->load('items');
                    foreach ($order->items as $item) {
                        self::restoreLineStock($item, $order);
                    }
                    $order->stock_restored_at = now(); // a later cancel/delete OR a void replay must not double-restore
                }
                $order->status = 'cancelled';
                $order->save();
                // A full void fully reverses the order (mirrors refund()/refundItems()):
                // re-credit any gift-card tender the diner paid with, and forfeit loyalty
                // points earned at placement. Both are idempotent + no-op when there is
                // nothing to reverse, so a later cancel can't double-credit.
                GiftCardService::reverseRedemption($order);
                CustomerWalletService::reverseForOrder($order);
                LoyaltyAccrual::reverseForOrder($order);
                // Full reversal → also give back the points the diner REDEEMED on it.
                LoyaltyAccrual::restoreRedemptionForOrder($order);
            }

            // Attribute this void to the drawer OPEN NOW, exactly as refund() does —
            // the Z-report counts voids per shift, so a void made while a shift is
            // open must carry its pos_shift_id (null when no shift is open).
            $voidShift = self::currentShift((int) $order->store_id, $order->branch_id ?: null);

            return OrderAdjustment::create([
                'order_id' => $order->id,
                'staff_id' => $staff?->id,
                'pos_shift_id' => $voidShift?->id,
                'type' => 'void',
                'amount' => $amount,
                'reason' => $reason,
                'order_item_id' => $orderItemId,
            ]);
        });
    }

    /**
     * Restore ALL of an order's stock (mode-aware), exactly once. An order deducts
     * stock at creation, so an abandoned order (cancel / delete / reject) must give
     * it back. Idempotent via orders.stock_restored_at (so void→cancel or cancel→
     * delete can't double-restore), and skips held orders whose deduction was
     * deferred and never happened.
     */
    public static function restoreOrderStock(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->held_at !== null || $locked->stock_restored_at !== null) {
                return;
            }
            $locked->load('items');
            foreach ($locked->items as $item) {
                self::restoreLineStock($item, $locked);
            }
            $locked->stock_restored_at = now();
            $locked->save();
        });
    }

    /**
     * Restore stock for one order line — mode-aware. Recipe (advanced) mode reverses
     * the ingredient ledger; simple mode bumps menu.stock_qty; combos restore each
     * component. Previously this only ever called Menu::restoreStock, so advanced-mode
     * ingredient stock (and combo components in advanced mode) was never given back.
     */
    private static function restoreLineStock(OrderItem $item, Order $order): void
    {
        $advanced = inventory_mode((int) $order->store_id) === 'advanced';
        $branchId = $order->branch_id ?: null;

        $restore = function (int $menuId, int $qty) use ($advanced, $branchId, $order) {
            if ($menuId <= 0 || $qty <= 0) {
                return;
            }
            try {
                if ($advanced) {
                    InventoryService::reverseForOrderItem($menuId, $qty, $order->id, $branchId);
                } else {
                    Menu::restoreStock($menuId, $qty);
                }
            } catch (\Throwable $e) {
                logger()->warning('[pos] stock restore failed', ['menu_id' => $menuId, 'error' => $e->getMessage()]);
            }
        };

        if ($item->isCombo()) {
            foreach ($item->comboSnapshot()['components'] as $c) {
                $restore((int) ($c['menu_id'] ?? 0), (int) $item->quantity);
            }

            return;
        }

        $restore((int) $item->item_id, (int) $item->quantity);

        // rev/multi-vertical Lane A2: mirror of OrderApiService::persistLines — restore
        // the line's own variant stock too (no-op for a null stock_qty variant).
        if ((int) $item->variation) {
            MenuVariant::restoreStock((int) $item->variation, (int) $item->quantity);
        }
    }

    /** Refund money on a paid order — records the money out (counts against the drawer if cash). */
    public static function refund(Order $order, ?StaffMember $staff, float $amount, string $method, string $reason, ?string $idempotencyKey = null): OrderAdjustment
    {
        // Idempotency: a replayed / double-submitted refund carrying the same key returns
        // the first adjustment instead of stacking a second one. Amount-mode refund used
        // to lack the guard its item-mode sibling (refundItems) has, so a PARTIAL refund
        // re-sent on a slow-network retry or the POS offline-queue flush recorded twice —
        // over-paying the customer and short-changing the drawer (the amount cap only stops
        // it once cumulative refunds reach the collected total). UNIQUE(order_id,
        // idempotency_key) is the DB backstop; a null key preserves the old behaviour.
        if ($idempotencyKey) {
            $existing = OrderAdjustment::where('order_id', $order->id)
                ->where('type', 'refund')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $result = DB::transaction(function () use ($order, $staff, $amount, $method, $reason, $idempotencyKey) {
            // Serialize concurrent refunds on the same order (MySQL row lock).
            $order = Order::whereKey($order->id)->lockForUpdate()->first() ?? $order;

            // Re-check INSIDE the lock: a racing request that committed first is now
            // visible, so return its adjustment rather than writing a second one (the
            // pre-lock check only catches sequential replay).
            if ($idempotencyKey) {
                $dup = OrderAdjustment::where('order_id', $order->id)
                    ->where('type', 'refund')->where('idempotency_key', $idempotencyKey)->first();
                if ($dup) {
                    return ['adj' => $dup, 'new' => false];
                }
            }

            if (! $order->is_paid) {
                throw ValidationException::withMessages([
                    'amount' => ___('Order is not paid — void it instead of refunding.'),
                ]);
            }

            $dec = money_decimals(store_currency((int) $order->store_id)); // MC-22: store decimals
            $amount = Money::round($amount, $dec);
            $already = (float) OrderAdjustment::where('order_id', $order->id)
                ->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount')));
            // Cap the refund at the money actually COLLECTED, not the gross order value.
            // `total` is the gross grand; wallet_applied/gift_card_applied/mk_wallet_applied
            // are tenders (store credit / gift card / platform wallet), so the real cash+card
            // collected is `amount_due` (= total − those tenders). refund() records a straight
            // money-out and never restores the consumed tender, so bounding at `total` would let
            // an order paid partly by gift card / store credit be refunded that non-cash portion
            // as cash — a payout beyond what the store ever received. For a tender-free order
            // amount_due == total, so this is a no-op there.
            $refundable = Money::round((float) $order->amount_due - $already, $dec);
            if ($amount > $refundable) {
                throw ValidationException::withMessages([
                    'amount' => ___('Refund exceeds the remaining refundable amount')." ({$refundable}).",
                ]);
            }

            // Attribute this refund to the drawer OPEN NOW, not the order's settle
            // shift. A cash refund physically leaves the current drawer; keying drawer
            // reconciliation off orders.pos_shift_id sent cross-shift refunds to an
            // already-closed shift and left the live drawer silently short.
            $refundShift = self::currentShift((int) $order->store_id, $order->branch_id ?: null);

            $adj = OrderAdjustment::create([
                'order_id' => $order->id,
                'staff_id' => $staff?->id,
                'pos_shift_id' => $refundShift?->id,
                'type' => 'refund',
                'amount' => $amount,
                'method' => $method,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);

            return ['adj' => $adj, 'new' => true];
        });

        $adjustment = $result['adj'];

        // Side effects fire ONCE, only when this call created the adjustment — an
        // idempotent replay must not re-forfeit loyalty, re-restore tenders or re-emit
        // the webhook (mirrors refundItems()).
        if ($result['new']) {
            /* A2 / P6-240: claw back the loyalty points earned on the REFUNDED share
               (cumulative refunds / collected cash). Idempotent per order (tops up on a
               later refund, never repeats). Points already spent leave a debt (negative
               balance, redemption blocked — J46). Self-guarded + post-commit. */
            LoyaltyAccrual::reverseForOrder($order, self::refundedShare($order));

            // Return the stored-value tenders too, once the collected cash is fully refunded.
            self::restoreTendersIfFullyRefunded($order);

            WebhookFanout::forRestaurant((int) $order->store_id, 'refund.completed', ['order_id' => $order->id, 'adjustment_id' => $adjustment->id, 'amount' => $amount, 'method' => $method]); // HPMS E10
            self::postRefundToLedger($order, (float) $adjustment->amount, 'pos:'.$adjustment->id);
        }

        return $adjustment;
    }

    /**
     * Once the full COLLECTED cash on an order has been refunded, the order is
     * effectively fully refunded — return the stored-value tenders (gift card + store
     * credit) it was ALSO paid with, so a part-gift-card / part-cash order refunded in
     * full gives back the whole amount, not just the cash. Before this, refund()/
     * refundItems() capped at amount_due (collected cash) and NEVER restored the tender
     * share, so refunding such an order stranded the gift-card / wallet value.
     *
     * Deliberately gated on FULL cash refund (refundedCash >= collected): a partial cash
     * refund returns cash only, and the tenders are restored at the same point void()
     * restores them, which avoids splitting a tender proportionally across several
     * partial refunds (rounding drift + double-restore risk). A fully tender-paid order
     * (amount_due == 0) is reversed through void(), never here. Both reversals are
     * idempotent (fixed ledger refs) and self-guarded — a hiccup never unwinds the
     * committed refund.
     */
    /** P6-240: cumulative refunded cash / collected cash, 0..1 (1 when nothing was collected). */
    private static function refundedShare(Order $order): float
    {
        $collected = (float) $order->amount_due;
        if ($collected <= 0) {
            return 1.0;
        }
        $refunded = (float) OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')
            ->sum(DB::raw(OrderAdjustment::minorMoneySql('amount')));

        return max(0.0, min(1.0, $refunded / $collected));
    }

    private static function restoreTendersIfFullyRefunded(Order $order): void
    {
        $dec = money_decimals(store_currency((int) $order->store_id));
        $collected = Money::round((float) $order->amount_due, $dec);
        if ($collected <= 0) {
            return; // nothing collected in cash (fully tender-paid → void() territory)
        }
        $refundedCash = Money::round(
            (float) OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))),
            $dec
        );
        if ($refundedCash < $collected) {
            return; // still a partial cash refund — tenders stay put
        }
        try {
            GiftCardService::reverseRedemption($order);
            CustomerWalletService::reverseForOrder($order);
            // A full refund reverses the reward discount too → restore the redeemed points.
            LoyaltyAccrual::restoreRedemptionForOrder($order);
        } catch (\Throwable $e) {
            // Post-commit best effort: never fail a committed refund on a tender-restore hiccup.
        }
    }

    /**
     * D1 — itemised refund. Each line names an order_items row + the quantity and
     * money coming back; the sum records a single append-only refund adjustment
     * (so the drawer + Z-report see it exactly like an amount refund) plus one
     * order_refund_items row per line for the audit trail.
     *
     * Caps (both enforced under the row lock): per line, refunded qty ≤ sold −
     * already-refunded qty for that line; and the total never exceeds the money
     * actually COLLECTED minus what was already refunded (the :355-372 amount cap,
     * reused — so item-mode and amount-mode share one ceiling). `idempotency_key`
     * absorbs a double-submitted POST: a second call with the same key on the same
     * order returns the first adjustment without recording anything.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function refundItems(
        Order $order,
        array $lines,
        string $reason,
        ?StaffMember $staff,
        string $method = 'cash',
        ?string $idempotencyKey = null
    ): OrderAdjustment {
        if ($idempotencyKey) {
            $existing = OrderAdjustment::where('order_id', $order->id)
                ->where('type', 'refund')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $result = DB::transaction(function () use ($order, $lines, $reason, $staff, $method, $idempotencyKey) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first() ?? $order;

            // Concurrency-safe idempotency: re-check INSIDE the lock. A racing request
            // that committed first is now visible, so we return its adjustment instead
            // of writing a second one (the pre-lock check above only catches sequential
            // replay). The UNIQUE(order_id, idempotency_key) is the DB backstop.
            if ($idempotencyKey) {
                $dup = OrderAdjustment::where('order_id', $order->id)
                    ->where('type', 'refund')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($dup) {
                    return ['adj' => $dup, 'new' => false];
                }
            }

            if (! $order->is_paid) {
                throw ValidationException::withMessages([
                    'lines' => ___('Order is not paid — void it instead of refunding.'),
                ]);
            }

            $dec = money_decimals(store_currency((int) $order->store_id));
            $order->load('items');

            $clean = [];
            $sum = 0.0;
            $reqQty = []; // per-item qty already claimed by earlier lines in THIS request
            foreach ($lines as $line) {
                $itemId = (int) ($line['order_item_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? 0);
                $amount = Money::round((float) ($line['amount'] ?? 0), $dec);

                $item = $order->items->firstWhere('id', $itemId);
                if (! $item || $qty <= 0 || $amount < 0) {
                    continue; // skip empty / unknown lines rather than fail the whole refund
                }

                $alreadyQty = (int) OrderRefundItem::where('order_id', $order->id)
                    ->where('order_item_id', $itemId)->sum('qty');
                // Subtract qty claimed by earlier duplicate lines in the same request so
                // two lines naming the same item can't each pass the cap independently.
                $remainingQty = (int) $item->quantity - $alreadyQty - ($reqQty[$itemId] ?? 0);
                if ($qty > $remainingQty) {
                    throw ValidationException::withMessages([
                        'lines' => ___('Refund quantity exceeds what remains on this line')." (#{$itemId}).",
                    ]);
                }
                $reqQty[$itemId] = ($reqQty[$itemId] ?? 0) + $qty;

                $clean[] = ['order_item_id' => $itemId, 'qty' => $qty, 'amount' => $amount];
                $sum = Money::round($sum + $amount, $dec);
            }

            if (! $clean || $sum <= 0) {
                throw ValidationException::withMessages([
                    'lines' => ___('Select at least one item and amount to refund.'),
                ]);
            }

            // Same ceiling as amount-mode refund(): cap at money COLLECTED (amount_due,
            // net of store-credit/gift-card tenders) minus everything already refunded.
            $already = (float) OrderAdjustment::where('order_id', $order->id)
                ->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount')));
            $refundable = Money::round((float) $order->amount_due - $already, $dec);
            if ($sum > $refundable) {
                throw ValidationException::withMessages([
                    'lines' => ___('Refund exceeds the remaining refundable amount')." ({$refundable}).",
                ]);
            }

            $refundShift = self::currentShift((int) $order->store_id, $order->branch_id ?: null);

            $adj = OrderAdjustment::create([
                'order_id' => $order->id,
                'staff_id' => $staff?->id,
                'pos_shift_id' => $refundShift?->id,
                'type' => 'refund',
                'amount' => $sum,
                'method' => $method,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($clean as $line) {
                OrderRefundItem::create([
                    'order_id' => $order->id,
                    'order_item_id' => $line['order_item_id'],
                    'qty' => $line['qty'],
                    'amount' => $line['amount'],
                    'reason' => $reason,
                    'staff_id' => $staff?->id,
                ]);
            }

            return ['adj' => $adj, 'new' => true];
        });

        $adjustment = $result['adj'];

        // Side effects fire ONCE, only when this call created the adjustment — an
        // idempotent replay (sequential or the in-lock race) must not re-forfeit
        // loyalty or re-emit the webhook.
        if ($result['new']) {
            // Mirror refund(): the first refund on an order forfeits its loyalty points.
            LoyaltyAccrual::reverseForOrder($order, self::refundedShare($order));
            // Mirror refund(): restore the gift-card / wallet tender once fully refunded.
            self::restoreTendersIfFullyRefunded($order);
            WebhookFanout::forRestaurant((int) $order->store_id, 'refund.completed', [
                'order_id' => $order->id, 'adjustment_id' => $adjustment->id,
                'amount' => (float) $adjustment->amount, 'method' => $method,
            ]); // HPMS §8-P1: item refunds now emit refund.completed like amount-mode
            self::postRefundToLedger($order, (float) $adjustment->amount, 'pos:'.$adjustment->id);
        }

        return $adjustment;
    }

    /**
     * A register refund is money out of the till, so it reaches the books like the other refund paths (no-op unless
     * accounting_gl is on; idempotent per adjustment). Reporting only — it never fails the refund.
     */
    private static function postRefundToLedger(Order $order, float $amount, string $ref): void
    {
        try {
            app(GeneralLedger::class)->postRefund($order, $amount, $ref);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Park an order. A held order holds NO inventory — resume re-deducts. */
    public static function hold(Order $order): Order
    {
        if (! $order->held_at) {
            // A live (non-held) order already deducted its stock at creation. Release
            // it here so held_at truly means "not currently deducted" — otherwise
            // resuming re-deducted (double) and voiding-while-held skipped the restore
            // (leak). restoreOrderStock is idempotent and marks stock_restored_at,
            // which resume() clears before it re-deducts.
            self::restoreOrderStock($order);
            $order->held_at = now();
            $order->save();
        }

        return $order;
    }

    /**
     * Resume a parked order: it now enters the kitchen (KDS), deducts inventory,
     * and fires the deferred order.created webhook.
     */
    public static function resume(Order $order): Order
    {
        if (! $order->held_at) {
            return $order;
        }

        DB::transaction(function () use ($order) {
            $order->held_at = null;
            // Re-deducting below — this order is no longer in the "stock returned" state,
            // so a later cancel/void can restore it again.
            $order->stock_restored_at = null;
            $order->save();
        });

        KdsService::syncOrderStatus($order->id);
        self::applyDeferredDeduction($order);

        try {
            WebhookService::dispatch((int) ($order->store->user_id ?? 0), 'order.created', OrderCreatedWebhookPayload::build($order, 'pos'));
        } catch (\Throwable $e) {
        }

        /* PARITY-RUN T1: a parked order printed nothing when it was created
           (nothing had happened yet) — resuming it is when the kitchen needs the
           ticket. Same job keys as the create path, so an order that somehow
           printed already will not print twice. */
        try {
            PrintDispatcher::onOrderReleased($order->refresh());
        } catch (\Throwable $e) {
        }

        return $order->refresh();
    }

    /** Deduct inventory for each line — the deduction OrderApiService skipped while held. */
    private static function applyDeferredDeduction(Order $order): void
    {
        $advanced = inventory_mode($order->store_id) === 'advanced';
        foreach ($order->items as $item) {
            try {
                if ($item->isCombo()) {
                    // Combo lines carry item_id = null; deducting by item_id was a no-op,
                    // so resuming a held combo never consumed its component stock. Deduct
                    // the snapshot components exactly as the create path does (each
                    // component is quantity 1, mode handled inside deductComponents).
                    ComboService::deductComponents(
                        (int) $order->store_id, $order, $item->comboSnapshot()['components'], (int) $item->quantity
                    );
                } elseif ($advanced) {
                    InventoryService::deductForOrderItem((int) $item->item_id, (int) $item->quantity, $order->id, $order->branch_id ?: null);
                } else {
                    Menu::deductStock((int) $item->item_id, (int) $item->quantity);
                }
            } catch (\Throwable $e) {
                logger()->warning('[pos] stock deduct failed', ['menu_id' => $item->item_id, 'error' => $e->getMessage()]);
            }
        }
    }
}
