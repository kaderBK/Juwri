<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services;

use Juwri\Modules\Tables\Models\CheckPayment;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Models\TableCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Money\Money;
use App\Services\PosService;
use App\Services\WebhookService;

/**
 * Split-bill (pay-by-seat) logic. Splits an order total into N even shares, absorbing the
 * rounding remainder into the last share so the shares always sum exactly to the total.
 * P1-05: the split is done in minor units of the order's currency (cents, fils, whole yen).
 */
class SplitBillService
{
    /**
     * Create (or replace, if unpaid) an even split for an order into $parts shares.
     */
    public static function createEvenSplit(Order $order, int $parts): TableCheck
    {
        $parts = max(1, min(50, $parts));
        $currency = $order->minorMoneyCurrency();
        $totalMinor = Money::minorFromMajor((float) $order->total, $currency);
        $total = Money::ofMinor($totalMinor, $currency)->major();

        return DB::transaction(function () use ($order, $parts, $total, $totalMinor, $currency) {
            $existing = TableCheck::where('order_id', $order->id)->get();

            /* Re-splitting once a share is paid strands the money already collected:
               the old code KEPT the paid check and created a SECOND full-total check,
               so paying the new shares collected the order total a second time (a $20
               order settled for $30), and — because markSharePaid settles per-check —
               the order could be flagged paid while the kept check still had pending
               shares. Mirror the destroy guard (SplitBillController::destroy): a bill
               with payments cannot be re-split. Re-splitting is still allowed freely
               BEFORE any share is paid. */
            foreach ($existing as $tc) {
                if ($tc->payments()->where('status', 'paid')->exists()) {
                    throw ValidationException::withMessages([
                        'parts' => ___('Cannot re-split a bill that already has payments.'),
                    ]);
                }
            }

            // No paid shares — clear the prior (unpaid) split and re-create.
            foreach ($existing as $tc) {
                $tc->payments()->delete();
                $tc->delete();
            }

            $check = TableCheck::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'total' => $total,
                'parts' => $parts,
                'status' => 'open',
            ]);

            // Even shares rounded down to the minor unit; the last share absorbs the remainder.
            $base = intdiv($totalMinor, $parts);
            $shares = array_fill(0, $parts, $base);
            $shares[$parts - 1] = $totalMinor - $base * ($parts - 1);

            foreach ($shares as $i => $minor) {
                CheckPayment::create([
                    'table_check_id' => $check->id,
                    'share_no' => $i + 1,
                    'currency_code' => $currency,
                    'amount' => Money::ofMinor($minor, $currency)->major(),
                    'method' => 'cash',
                    'status' => 'pending',
                    'token' => Str::random(40),
                ]);
            }

            return $check->fresh('payments');
        });
    }

    /**
     * Mark a single share paid. When every share is paid, close the check and flag the order paid.
     */
    public static function markSharePaid(CheckPayment $share, ?string $payerName = null, string $method = 'cash'): void
    {
        DB::transaction(function () use ($share, $payerName, $method) {
            /* Lock the parent check so two concurrent final-share payments serialise
               on the settlement decision. Without the lock each transaction saved its
               own share, then re-read the payment set before the other committed, saw
               a sibling still 'pending', and wrote 'partial' — leaving a fully
               collected check stuck 'partial' with the order never marked paid. */
            $check = TableCheck::whereKey($share->table_check_id)->lockForUpdate()->first();
            // P6-180: re-read the share under the lock — a concurrent double pay must
            // not overwrite the first payment's method/payer/time.
            $share->refresh();

            if ($share->status !== 'paid') {
                $share->status = 'paid';
                $share->method = in_array($method, ['cash', 'card', 'online'], true) ? $method : 'cash';
                $share->payer_name = $payerName ?: $share->payer_name;
                $share->paid_at = now();
                $share->save();
            }

            // Authoritative under the lock: is any share still unpaid?
            $allPaid = ! CheckPayment::where('table_check_id', $check->id)
                ->where('status', '!=', 'paid')
                ->exists();
            $check->status = $allPaid ? 'paid' : 'partial';
            $check->save();

            if ($allPaid) {
                $order = $check->order;
                if ($order && ! $order->is_paid) {
                    $order->is_paid = true;

                    /* Stamp the settlement so the drawer can see it. Before
                       this, a split-settled order carried neither
                       payment_method nor pos_shift_id, and PosService's drawer
                       math only counts orders matching BOTH
                       (payment_method='cash' AND pos_shift_id=<shift>) — so
                       cash a cashier collected on a split bill never appeared
                       in expected cash. The till balanced whether or not the
                       money went in the drawer.

                       payment_method is 'split' rather than 'cash' on purpose:
                       shares can be paid by different methods, and the amount
                       per method lives on the shares. PosService::expectedCash
                       adds up the CASH shares of orders in the shift, which is
                       why this must not masquerade as a plain cash sale. */
                    $order->payment_method = 'split';
                    $shift = PosService::currentShift(
                        (int) $order->store_id,
                        $order->branch_id ?: null
                    );
                    if ($shift) {
                        $order->pos_shift_id = $shift->id;
                    }
                    $order->save();

                    // #99: notify subscribers the order is fully paid.
                    try {
                        $ownerId = Store::where('id', $order->store_id)->value('user_id');
                        if ($ownerId) {
                            WebhookService::dispatch((int) $ownerId, 'order.paid', [
                                'order_id' => $order->id,
                                'total' => $order->total,
                                'via' => 'split_bill',
                            ]);
                        }
                    } catch (\Throwable $e) {
                    }

                    PosService::postSaleToLedger($order); // the settled check is revenue: into the books (no-op unless accounting_gl is on)
                }
            }
        });
    }
}
