<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting\Gl;

use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Models\GlJournal;
use App\Models\Order;
use App\Services\FeatureRegistry;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Contracts\OrderTax;

/**
 * SYSTEMS-RUN A-P1: classify settled money events into GL journals. Hooks the
 * EXISTING choke points (PaymentController::paySuccess, refund flows) — it never
 * moves money, only records what the money spine already settled. No-op unless
 * the accounting_gl kill-switch is on. Idempotent per (Order, rule_key).
 */
class OrderJournalPoster
{
    /** Post the sales journal for a paid order. Returns the journal, or null when off/empty. */
    public static function postOrderPaid(Order $order): ?GlJournal
    {
        if (!FeatureRegistry::isEnabled('accounting_gl')) {
            return null;
        }

        $rid = (int) $order->store_id;
        if ($rid <= 0) {
            return null;
        }

        // P1-05: amounts in minor units of the store currency (HALF_UP to its exponent, PHP-version independent);
        // revenue is the exact remainder, so the journal balances per currency.
        $currency = store_currency($rid);
        $totalMinor = Money::minorFromMajor((float) $order->total, $currency);
        if ($totalMinor <= 0) {
            return null;
        }
        // P6-99b: the VAT the order charged — its own derivation (the per-line VAT engine snapshot when it has one), so
        // the journal's Output VAT == order tax == the fiscal invoice's VAT (no re-derivation from the total)
        $taxMinor = Money::minorFromMajor((float) $order->computeMoney()['tax'], $currency);
        $tipMinor = Money::minorFromMajor((float) ($order->tip_amount ?? 0), $currency);
        // J32: pre-VAT levies (municipality fee, stamp duty…) are collected for a third party, not revenue
        $levyMinor = Money::minorFromMajor(app(OrderTax::class)->levyTotal($order), $currency);
        $total = Money::majorFromMinor($totalMinor, $currency);
        $tax = Money::majorFromMinor($taxMinor, $currency);
        $tip = Money::majorFromMinor($tipMinor, $currency);
        $levy = Money::majorFromMinor($levyMinor, $currency);
        $revenue = Money::majorFromMinor($totalMinor - $taxMinor - $tipMinor - $levyMinor, $currency);

        $cashCode = self::cashCode($order);
        $lines = [
            ['account' => JournalEngine::account($rid, $cashCode), 'debit' => $total, 'credit' => 0, 'memo' => 'Order #' . $order->id],
        ];
        if ($revenue != 0.0) {
            $lines[] = ['account' => JournalEngine::account($rid, '4100'), 'debit' => 0, 'credit' => $revenue, 'memo' => 'F&B revenue'];
        }
        if ($tax > 0) {
            $lines[] = ['account' => JournalEngine::account($rid, '2200'), 'debit' => 0, 'credit' => $tax, 'memo' => 'Output VAT'];
        }
        if ($levy > 0) {
            if (! GlAccount::where('store_id', $rid)->where('code', ChartOfAccounts::LEVIES_PAYABLE)->exists()) {
                ChartOfAccounts::seed($rid); // a chart seeded before J32 gets the new account (idempotent)
            }
            $lines[] = ['account' => JournalEngine::account($rid, ChartOfAccounts::LEVIES_PAYABLE), 'debit' => 0, 'credit' => $levy, 'memo' => 'Levies'];
        }
        if ($tip > 0) {
            $lines[] = ['account' => JournalEngine::account($rid, '2310'), 'debit' => 0, 'credit' => $tip, 'memo' => 'Tips'];
        }
        return JournalEngine::post(
            $rid,
            $lines,
            'Order',
            (int) $order->id,
            'order_paid',
            'Sales — order #' . $order->id,
            $order->created_at ?? now(),
            $order->branch_id ? (int) $order->branch_id : null
        );
    }

    /** Post a refund reversal for an order (rule_key keeps repeat refunds distinct). */
    public static function postRefund(Order $order, float $amount, ?string $ref = null): ?GlJournal
    {
        if (!FeatureRegistry::isEnabled('accounting_gl')) {
            return null;
        }
        $rid = (int) $order->store_id;
        if ($rid <= 0) {
            return null;
        }
        $amount = Money::round($amount, Currencies::exponent(store_currency($rid)));
        if ($amount < 0.001) {
            return null;
        }
        $cashCode = self::cashCode($order);
        $lines = [
            ['account' => JournalEngine::account($rid, '4900'), 'debit' => $amount, 'credit' => 0, 'memo' => 'Refund order #' . $order->id],
            ['account' => JournalEngine::account($rid, $cashCode), 'debit' => 0, 'credit' => $amount, 'memo' => 'Refund out'],
        ];
        return JournalEngine::post(
            $rid,
            $lines,
            'Order',
            (int) $order->id,
            'refund' . ($ref ? ':' . $ref : ''),
            'Refund — order #' . $order->id,
            now(),
            $order->branch_id ? (int) $order->branch_id : null
        );
    }

    private static function cashCode(Order $order): string
    {
        $method = strtolower((string) ($order->payment_method ?? $order->payment_gateway ?? ''));
        if (in_array($method, ['cash', 'cod', 'pos_cash'], true) || str_contains($method, 'cash')) {
            return '1110'; // cash on hand
        }
        return '1130'; // gateway clearing
    }
}
