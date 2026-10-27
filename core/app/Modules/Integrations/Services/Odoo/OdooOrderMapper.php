<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use App\Models\Order;
use Brick\Math\RoundingMode;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Models\OrderAdjustment;

/**
 * P4-21: an order (or a refund of it) as Odoo invoice lines — in MINOR units of the order's stamped currency, from
 * the order's one money derivation ({@see Order::computeMoney()}: line snapshots, the store's tax on the item
 * subtotal, delivery / tray / tip / donation / service charge added, discount taken off, never below zero).
 *
 * Exact by construction: the product lines sum to the item subtotal, the untaxed add-on lines and the (negative)
 * discount line make up the rest, and the tax is the store's tax on the products — so lines + tax = the order total
 * to the minor unit. Odoo recomputes the tax from the mapped Odoo tax; {@see OdooSync} compares Odoo's total and tax
 * with these before posting and refuses a mismatch (wrong tax mapping or rounding), never posting a wrong invoice.
 *
 * A line's unit price is exact only when the line total divides by its quantity; otherwise the line goes as
 * quantity 1 named "3 × Tea" (the amount stays exact, Odoo's quantity is then 1).
 */
final class OdooOrderMapper
{
    /**
     * @return array{currency: string, lines: list<array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}>,
     *               untaxed_minor: int, tax_minor: int, total_minor: int, fixed_tax: bool}
     */
    public function invoice(Order $order): array
    {
        $currency = (string) ($order->currencyCode() ?: store_currency((int) $order->store_id));
        $minor = fn (float|int|string|null $major): int => Money::minorFromMajor((float) ($major ?? 0), $currency);
        $order->loadMissing(['items.menu', 'items.variant', 'items.itemExtras']);
        $money = $order->computeMoney();
        $postOptions = store_options($order->store_id);

        $subtotal = $minor($money['subtotal']);
        $tax = $minor($money['tax']);
        $total = $minor($money['grand']);
        $fixedTax = (string) (@$postOptions->restaurant_tax_type ?: 'percentage') === 'fixed';

        // Product lines: each line's own amount from the same derivation; any rounding residue lands on the last line.
        $lines = [];
        $sum = 0;
        foreach ($order->items as $item) {
            $lineMinor = $minor($order->itemsSubtotal(fn ($i) => (int) $i->id === (int) $item->id));
            $lines[] = $this->productLine($item, $lineMinor, ! $fixedTax && $tax > 0);
            $sum += $lineMinor;
        }
        if ($lines !== [] && $sum !== $subtotal) {
            $last = array_key_last($lines);
            $lines[$last] = $this->asSingle($lines[$last], $lines[$last]['price_minor'] * $lines[$last]['quantity'] + ($subtotal - $sum));
        }

        $addons = [
            'Delivery' => $minor($order->deliveryCharge($postOptions)),
            'Room service tray' => $minor(Order::trayCharge($order->type, (float) $money['subtotal'], $order->store_id, $currency)),
            'Tip' => $minor($order->tip_amount),
            'Donation' => $minor($order->donation_amount),
            'Service charge' => $minor($order->service_charge_amount),
        ];
        foreach ($addons as $name => $amount) {
            if ($amount !== 0) {
                $lines[] = ['name' => $name, 'quantity' => 1, 'price_minor' => $amount, 'taxed' => false, 'sku' => null];
            }
        }
        if ($fixedTax && $tax > 0) {
            // A fixed-amount store tax: one zero-priced carrier line holds the mapped fixed Odoo tax.
            $lines[] = ['name' => 'Tax', 'quantity' => 1, 'price_minor' => 0, 'taxed' => true, 'sku' => null];
        }

        // The discount is whatever takes the lines + tax to the order total (the granted discount, or less when the
        // total was floored at zero).
        $before = array_sum(array_map(fn ($l) => $l['price_minor'] * $l['quantity'], $lines)) + $tax;
        $discount = $before - $total;
        $granted = $minor($order->discount_amount);
        if ($discount !== $granted && $total !== 0) {
            throw new OdooError("Order #{$order->id}: its amounts do not add up to its total ({$before} − {$granted} ≠ {$total} minor units); it was not sent.", permanent: true);
        }
        if ($discount !== 0) {
            // P6-271: a new order's percentage tax is charged AFTER the discount (Order::taxBase), so the part of the
            // discount that lowers the taxed item subtotal is itself a taxed line — Odoo then taxes products − discount,
            // the same base as the order. An order placed before P6-271 (tax_pre_discount) keeps an untaxed discount.
            $taxedDiscount = (! $fixedTax && $tax > 0 && ! $order->getAttribute('tax_pre_discount') && $discount > 0)
                ? min($discount, $subtotal) : 0;
            if ($taxedDiscount !== 0) {
                $lines[] = ['name' => 'Discount', 'quantity' => 1, 'price_minor' => -$taxedDiscount, 'taxed' => true, 'sku' => null];
            }
            if ($discount - $taxedDiscount !== 0) {
                $lines[] = ['name' => 'Discount', 'quantity' => 1, 'price_minor' => -($discount - $taxedDiscount), 'taxed' => false, 'sku' => null];
            }
        }

        return ['currency' => $currency, 'lines' => $lines, 'untaxed_minor' => $total - $tax, 'tax_minor' => $tax, 'total_minor' => $total, 'fixed_tax' => $fixedTax];
    }

    /**
     * A refund / void of the order as credit-note lines. The whole order (or more) → the invoice's own lines. A part
     * → one line whose net, with the invoice's tax rate added the way Odoo rounds it, is exactly the refunded amount.
     *
     * @param  array{currency: string, lines: list<array<string, mixed>>, untaxed_minor: int, tax_minor: int, total_minor: int, fixed_tax: bool}  $invoice
     * @return array{currency: string, lines: list<array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}>, tax_minor: int, total_minor: int}
     */
    public function refund(OrderAdjustment $adjustment, array $invoice, ?float $taxRatePercent): array
    {
        $amount = min((int) $adjustment->amount_minor, $invoice['total_minor']);
        if ($amount >= $invoice['total_minor']) {
            /** @var list<array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}> $lines */
            $lines = $invoice['lines'];

            return ['currency' => $invoice['currency'], 'lines' => $lines, 'tax_minor' => $invoice['tax_minor'], 'total_minor' => $invoice['total_minor']];
        }
        $label = ((string) $adjustment->type === 'void' ? 'Void' : 'Refund').' — '.mb_substr((string) $adjustment->reason, 0, 80);
        if ($invoice['tax_minor'] <= 0 || $invoice['fixed_tax'] || $taxRatePercent === null || $taxRatePercent <= 0) {
            // No tax on the sale, or a fixed-amount tax that cannot be split: the refunded amount as one untaxed line.
            return ['currency' => $invoice['currency'], 'lines' => [['name' => $label, 'quantity' => 1, 'price_minor' => $amount, 'taxed' => false, 'sku' => null]],
                'tax_minor' => 0, 'total_minor' => $amount];
        }
        $net = self::netForGross($amount, $taxRatePercent);
        $tax = self::taxOf($net, $taxRatePercent);
        $lines = [['name' => $label, 'quantity' => 1, 'price_minor' => $net, 'taxed' => true, 'sku' => null]];
        if ($net + $tax !== $amount) {
            // No net gives exactly this gross at this rate (e.g. 12.34 at 7.5 %): the minor-unit rest as its own line.
            $lines[] = ['name' => 'Rounding', 'quantity' => 1, 'price_minor' => $amount - $net - $tax, 'taxed' => false, 'sku' => null];
        }

        return ['currency' => $invoice['currency'], 'lines' => $lines, 'tax_minor' => $tax, 'total_minor' => $amount];
    }

    /** The net n (minor) with n + round_half_up(n × rate) = gross; the nearest when none is exact. */
    public static function netForGross(int $gross, float $ratePercent): int
    {
        $guess = (int) floor($gross / (1 + $ratePercent / 100));
        $best = $guess;
        $bestDiff = PHP_INT_MAX;
        for ($n = max(0, $guess - 3); $n <= $guess + 3; $n++) {
            $diff = abs($n + self::taxOf($n, $ratePercent) - $gross);
            if ($diff < $bestDiff) {
                [$best, $bestDiff] = [$n, $diff];
            }
            if ($diff === 0) {
                return $n;
            }
        }

        return $best;
    }

    /** Odoo's percentage tax of a minor amount, rounded half-up to the minor unit. */
    public static function taxOf(int $netMinor, float $ratePercent): int
    {
        return Money::decimal($netMinor)->multipliedBy(Money::decimal((string) $ratePercent))
            ->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
    }

    /** Minor → the decimal Odoo expects for the currency. */
    public static function major(int $minor, string $currency): float
    {
        return round($minor / (10 ** Currencies::exponent($currency)), Currencies::exponent($currency));
    }

    /** @return array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string} */
    private function productLine(object $item, int $lineMinor, bool $taxed): array
    {
        $name = trim((string) ($item->menu->name ?? 'Item'));
        $qty = max(1, (int) $item->quantity);
        $variantSku = $item->variant !== null ? (string) $item->variant->sku : '';
        $menuSku = $item->menu !== null ? (string) $item->menu->external_sku : '';
        $sku = trim($variantSku !== '' ? $variantSku : $menuSku) ?: null;
        $line = ['name' => $name, 'quantity' => $qty, 'price_minor' => intdiv($lineMinor, $qty), 'taxed' => $taxed, 'sku' => $sku];

        return $lineMinor % $qty === 0 ? $line : $this->asSingle($line, $lineMinor);
    }

    /**
     * @param  array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}  $line
     * @return array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}
     */
    private function asSingle(array $line, int $amountMinor): array
    {
        $name = $line['quantity'] > 1 ? $line['quantity'].' × '.$line['name'] : $line['name'];

        return ['name' => $name, 'quantity' => 1, 'price_minor' => $amountMinor, 'taxed' => $line['taxed'], 'sku' => $line['sku']];
    }
}
