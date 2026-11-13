<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\LargestRemainder;
use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;
use Juwri\Modules\Fiscal\Data\FiscalLine;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Data\FiscalSale;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;

/**
 * J25-8: Ordering fills the fiscal context of an order — Fiscal builds documents and receipt blocks ONLY from
 * {@see FiscalReceiptContext} and never reads an order.
 *
 * The numbers are exactly what the customer was charged: total AND tax come from the order's one money derivation,
 * {@see Order::computeMoney()} (line `unit_price` snapshots, modifier `price` snapshots, choice-group option
 * snapshots, the order's stamped currency) — the same derivation as `$order->total`, so net + tax = total.
 * (Before the P1-08b follow-up the tax was recomputed over the LIVE menu/variant/extra prices; for an order whose
 * snapshots equal the live prices both give the same bytes — tests/Modules/Fiscal/FiscalDocumentParityTest.)
 *
 * issued at = created_at (now() for an unsaved order); orderId = the id of a saved order (enables the QR lookup).
 */
final class OrderFiscalContext
{
    /**
     * @param  array<string,mixed>  $extra  adapter-supplied QR/signature bits
     */
    public static function for(Store $store, Order $order, array $extra = []): FiscalReceiptContext
    {
        return app(FiscalReceipts::class)->context($store, self::sale($store, $order), $extra);
    }

    public static function sale(Store $store, Order $order): FiscalSale
    {
        $money = $order->computeMoney();
        // P6-99b: on the VAT engine the invoice is the order's own per-line VAT (discount already inside the lines)
        $vat = TaxEngine::compute($order);
        if ($vat !== null) {
            return new FiscalSale(
                total: (float) $money['grand'],
                taxAmount: (float) $money['tax'],
                issuedAt: $order->created_at ?? now(),
                orderId: $order->exists ? (int) $order->id : null,
                lines: self::engineLines($vat, store_options($store->id)),
            );
        }

        return new FiscalSale(
            total: (float) $money['grand'],
            taxAmount: (float) $money['tax'],
            issuedAt: $order->created_at ?? now(),
            orderId: $order->exists ? (int) $order->id : null,
            lines: self::lines($order, $money),
            // P6-271: the discount lowers the taxed items (it sits on the item lines, see lines()); only what it takes
            // beyond the items comes off the total. An order placed before P6-271 took its discount off the total AFTER
            // tax (tax on the pre-discount items), i.e. off the tax-inclusive amount: the invoice carves its VAT share out.
            discount: self::documentDiscount($order),
            discountIncludesTax: true,
        );
    }

    /**
     * P6-99: the order's lines as charged, for a per-line fiscal document.
     *
     * - items: each line's amount from the same snapshots as {@see Order::itemsSubtotal()}; the order's tax (as
     *   {@see Order::computeMoney()} charged it — tax on the items, added on top) is split over the item lines by
     *   largest remainder in minor units, so the lines' VAT sums to the order's VAT exactly. The rate is the one the
     *   checkout applied: the store's percentage when it reproduces the charged tax, else the effective rate.
     * - delivery / tray / service charges: the checkout did not add VAT on top, so in a taxed order they are
     *   standard-rated at the same rate with the VAT inside the amount; in an untaxed order out of scope.
     * - tip, donation: out of scope (not a supply).
     *
     * @param  array{subtotal: float, tax: float, grand: float}  $money
     * @return list<FiscalLine>
     */
    public static function lines(Order $order, array $money): array
    {
        $currency = $order->currencyCode();
        $decimals = money_decimals($currency);
        $f = 10 ** $decimals;
        $items = [];
        foreach ($order->items as $item) {
            $amount = (float) $order->itemsSubtotal(fn ($i) => $i->is($item));
            $items[] = ['item' => $item, 'amount' => $amount, 'minor' => (int) round($amount * $f)];
        }
        $taxMinor = (int) round($money['tax'] * $f);
        $base = array_sum(array_column($items, 'minor'));
        // P6-271: the order discount on the item lines (largest remainder by amount), so each line's VAT is on what
        // was paid for it; none for an order placed before P6-271 (its discount is a document allowance)
        $lineDisc = array_fill(0, count($items), 0);
        if (! $order->getAttribute('tax_pre_discount') && $base > 0) {
            $lineDisc = LargestRemainder::allocate(min($base, (int) round((float) ($order->discount_amount ?? 0) * $f)), array_column($items, 'minor'));
        }
        $after = array_map(fn ($row, $d) => $row['minor'] - $d, $items, $lineDisc);
        $taxed = $taxMinor > 0 && $base > 0;
        $rate = $taxed ? self::rate($order, max(1, array_sum($after)), $taxMinor, $decimals) : 0.0;
        $shares = LargestRemainder::allocate($taxMinor, array_sum($after) > 0 ? $after : array_column($items, 'minor'));
        $oos = fn (string $name, float $qty, float $unit, float $amount, string $kind) => new FiscalLine(name: $name, quantity: $qty,
            unitPrice: $unit, category: FiscalLine::OUT_OF_SCOPE, amount: $amount, exemptionCode: 'VATEX-SA-OOS',
            exemptionReason: 'Not subject to VAT', kind: $kind);

        $lines = [];
        foreach ($items as $i => $row) {
            $qty = max(1, (int) $row['item']->quantity);
            $name = $row['item']->displayName() ?: 'Item';
            $lines[] = $taxed
                ? new FiscalLine(name: $name, quantity: $qty, unitPrice: $row['amount'] / $qty, category: FiscalLine::STANDARD,
                    rate: $rate, tax: $shares[$i] / $f, amount: $row['amount'], discount: $lineDisc[$i] / $f)
                : $oos($name, $qty, $row['amount'] / $qty, $row['amount'], 'item');
        }
        // fixed-amount tax with no items (an empty order): keep the charged tax on one line
        if ($taxMinor > 0 && $base === 0) {
            $lines[] = new FiscalLine(name: 'Tax', quantity: 1, unitPrice: 0, category: FiscalLine::STANDARD, rate: 0.0, tax: $taxMinor / $f);
        }

        $postOptions = store_options($order->store_id);
        $fees = [
            'Delivery' => $order->deliveryCharge($postOptions),
            'Room service' => Order::trayCharge($order->type, (float) $money['subtotal'], $order->store_id, $currency),
            'Service charge' => (float) ($order->service_charge_amount ?? 0),
        ];
        foreach ($fees as $name => $fee) {
            if ($fee > 0) {
                $lines[] = $taxed
                    ? new FiscalLine(name: $name, quantity: 1, unitPrice: $fee, category: FiscalLine::STANDARD, rate: $rate, pricesIncludeTax: true, kind: 'fee')
                    : $oos($name, 1, $fee, $fee, 'fee');
            }
        }
        foreach (['Tip' => $order->tip_amount, 'Donation' => $order->donation_amount] as $name => $v) {
            if ((float) ($v ?? 0) > 0) {
                $lines[] = $oos($name, 1, (float) $v, (float) $v, 'tip');
            }
        }

        return $lines;
    }

    /**
     * P6-99b: the engine's lines as fiscal lines — the VAT of each line is the one the order charged, so the
     * invoice's VAT is the order's VAT to the minor unit. J32: a levy is its own line (kind `levy`), VAT on top.
     * P6-175: a Z or E line carries the store's own VATEX reason (`tax_zero_vatex` / `tax_exempt_vatex`), else the
     * ZATCA default (Fiscal's Vatex::DEFAULTS); the reason text is the ZATCA code list's own. The setting is checked
     * against the code list when it is saved; a code of the wrong category stops the ZATCA document (never invalid XML).
     *
     * @param  array{inclusive: bool, decimals: int, lines: list<array{kind: string, name: string, qty: float, amount: int, discount: int, tax: int, rate: float, category: string}>}  $vat
     * @return list<FiscalLine>
     */
    public static function engineLines(array $vat, ?object $opts = null): array
    {
        $f = 10 ** $vat['decimals'];
        $own = fn (string $key): ?string => trim((string) ($opts->{$key} ?? '')) !== '' ? trim((string) $opts->{$key}) : null;
        $reasons = [
            FiscalLine::ZERO => [$own('tax_zero_vatex'), null],
            FiscalLine::EXEMPT => [$own('tax_exempt_vatex'), null],
            FiscalLine::OUT_OF_SCOPE => ['VATEX-SA-OOS', 'Not subject to VAT'],
        ];
        $lines = [];
        foreach ($vat['lines'] as $l) {
            $cat = $l['category'];
            $lines[] = new FiscalLine(
                name: $l['name'], quantity: $l['qty'], unitPrice: $l['amount'] / $f / max(1.0, $l['qty']), category: $cat,
                rate: $cat === FiscalLine::STANDARD ? (float) $l['rate'] : 0.0, discount: $l['discount'] / $f,
                pricesIncludeTax: $vat['inclusive'] && $cat === FiscalLine::STANDARD && $l['kind'] !== 'levy', tax: $l['tax'] / $f, amount: $l['amount'] / $f,
                exemptionCode: $reasons[$cat][0] ?? null, exemptionReason: $reasons[$cat][1] ?? null, kind: $l['kind'],
            );
        }

        return $lines;
    }

    /** P6-271: the part of the order discount the item lines do not carry (all of it for an order placed before P6-271). */
    private static function documentDiscount(Order $order): float
    {
        $discount = (float) ($order->discount_amount ?? 0);
        if ($order->getAttribute('tax_pre_discount') || $discount <= 0) {
            return $discount;
        }
        $currency = $order->currencyCode();

        return max(0.0, money_round($discount - (float) $order->itemsSubtotal(), money_decimals($currency)));
    }

    /** The percent the checkout applied: the store's percentage when it gives exactly the charged tax, else effective. */
    private static function rate(Order $order, int $baseMinor, int $taxMinor, int $decimals): float
    {
        $opts = store_options($order->store_id);
        $pct = (float) ($opts->restaurant_taxx_charge ?? 0);
        if (($opts->restaurant_tax_type ?? 'percentage') !== 'fixed' && $pct > 0
            && (int) round(money_round($baseMinor * $pct / 100 / (10 ** $decimals), $decimals) * (10 ** $decimals)) === $taxMinor) {
            return $pct;
        }

        return round($taxMinor / $baseMinor * 100, 2);
    }
}
