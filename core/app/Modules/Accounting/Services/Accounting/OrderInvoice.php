<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Contracts\OrderTax;

/**
 * Bug (2026-10-01, accounting exports ≠ order): the invoice an accounting export sends, read from the STORED order
 * in the order's own currency, in minor units. Both providers ({@see XeroProvider}, {@see QuickBooksProvider}) map
 * it to their own shape; neither prices anything from the live menu.
 *
 * Lines, in order:
 *  - one `item` line per order line: unit = the line's snapshotted unit price (`order_items.unit_price_minor`, the
 *    price sold) + its snapshotted modifiers (`order_item_extras.price_minor`) + its snapshotted choice-group /
 *    combo options (`options[*].selected[*].price_minor`, P1-06), × quantity — the same per-line sum as
 *    {@see Order::computeMoney()}. A legacy line that never got a unit-price snapshot carries no other price, so it
 *    falls back to the variant / menu price exactly as computeMoney() does;
 *  - `item_adjustment` when those lines do not add up to the stored `subtotal_minor` (a legacy line whose menu price
 *    changed since the order was totalled) — the stored subtotal wins;
 *  - `delivery` (delivery orders) / `tray_charge` (room-service orders): not stored on their own, so they are the
 *    stored grand total minus every stored component (subtotal, tax, tip, donation, service charge, discount); any
 *    other non-zero remainder becomes an `adjustment` line;
 *  - `service_charge`, `tip`, `donation` from their stored columns;
 *  - `discount` (negative) = the stored `discount_amount_minor` (coupon, loyalty reward, POS manual discount — juwri
 *    keeps one order-level discount; order lines carry no line discount). When the order total was floored at 0
 *    the discount line is capped at what it actually took off.
 * Tax is the stored `tax_total_minor`, exclusive (added on top of the pre-discount item subtotal, as juwri computes
 * it); providers attach it themselves. Σ lines + tax = the stored `grand_total_minor`, always.
 *
 * Orders written before the totals snapshot (null `*_total_minor`) fall back to computeMoney() for the missing value.
 */
final class OrderInvoice
{
    public const ITEM = 'item';

    public const ITEM_ADJUSTMENT = 'item_adjustment';

    public const DELIVERY = 'delivery';

    public const TRAY_CHARGE = 'tray_charge';

    public const SERVICE_CHARGE = 'service_charge';

    public const TIP = 'tip';

    public const DONATION = 'donation';

    public const DISCOUNT = 'discount';

    public const ADJUSTMENT = 'adjustment';

    /** J32: a pre-VAT levy (municipality / tourism fee, table tax, stamp duty) — its own line. */
    public const LEVY = 'levy';

    /**
     * @param  list<array{kind: string, description: string, quantity: int, unit_minor: int, amount_minor: int, taxable: bool}>  $lines
     */
    private function __construct(
        public readonly string $currency,
        public readonly array $lines,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly int $discountMinor,
    ) {}

    public static function fromOrder(Order $order): self
    {
        $currency = strtoupper($order->minorMoneyCurrency());
        $toMinor = static fn (?Money $m): ?int => $m === null ? null
            : ($m->currency() === $currency ? $m->minor() : Money::minorFromMajor($m->major(), $currency));

        $lines = [];
        $itemsMinor = 0;
        // Order lines in the order they were rung up (id): the relation has no ORDER BY, and PostgreSQL returns rows in
        // physical order, which an UPDATE changes — the invoice (and its tax spread) must not depend on it.
        foreach ($order->items->sortBy('id')->values() as $item) {
            $unit = self::unitMinor($item, $currency);
            $qty = (int) $item->quantity;
            $amount = $unit * $qty;
            $itemsMinor += $amount;
            $lines[] = [
                'kind' => self::ITEM, 'description' => self::describe($item), 'quantity' => $qty,
                'unit_minor' => $unit, 'amount_minor' => $amount, 'taxable' => true,
            ];
        }

        $computed = null;
        $live = function (string $key) use ($order, $currency, &$computed): int {
            $computed ??= $order->computeMoney();

            return Money::minorFromMajor(Money::round($computed[$key], 3), $currency);
        };
        $subtotal = $order->getAttribute('subtotal_minor') !== null ? (int) $order->getAttribute('subtotal_minor') : $live('subtotal');
        $tax = $order->getAttribute('tax_total_minor') !== null ? (int) $order->getAttribute('tax_total_minor') : $live('tax');
        $grand = $order->getAttribute('grand_total_minor') !== null ? (int) $order->getAttribute('grand_total_minor') : $live('grand');

        if ($itemsMinor !== $subtotal) {
            $lines[] = self::single(self::ITEM_ADJUSTMENT, 'Order price adjustment (stored order subtotal)', $subtotal - $itemsMinor, true);
        }

        // P6-99b: a tax-inclusive store (VAT engine) — the item prices hold the VAT; the invoice states them net so
        // Σ lines + tax = total still holds and a provider adding the tax on top does not charge it twice
        $orderTax = app(OrderTax::class);
        $levyLines = $orderTax->levyLines($order);
        if ($orderTax->pricesIncludeTax($order) && $tax !== 0) {
            // J32: a levy's VAT is added on top, never inside the prices
            $levyVat = 0;
            foreach ($levyLines as $l) {
                $levyVat += Money::minorFromMajor($l['tax'] / (10 ** $l['decimals']), $currency);
            }
            $lines[] = self::single(self::ITEM_ADJUSTMENT, 'VAT included in prices', -($tax - $levyVat), true);
            $subtotal -= $tax - $levyVat;
        }

        $tip = $toMinor($order->money('tip_amount')) ?? 0;
        $donation = $toMinor($order->money('donation_amount')) ?? 0;
        $service = $toMinor($order->money('service_charge_amount')) ?? 0;
        $discount = $toMinor($order->money('discount_amount')) ?? 0;

        // J32: each levy its own line (taxable: VAT is charged on it when the store charges VAT)
        $levies = [];
        foreach ($levyLines as $l) {
            $levies[] = [$l['name'], Money::minorFromMajor($l['amount'] / (10 ** $l['decimals']), $currency)];
        }
        $components = $subtotal + $tax + $tip + $donation + $service + array_sum(array_column($levies, 1));
        if ($grand === 0 && $discount > 0 && $components - $discount + self::liveCharges($order, $currency) <= 0) {
            // The total was floored at 0 (Order::computeMoney): the discount took off only what there was to take.
            $charges = self::liveCharges($order, $currency);
            $discountApplied = $components + $charges;
        } else {
            $charges = $grand - $components + $discount;
            $discountApplied = $discount;
        }

        if ($charges !== 0) {
            $kind = match (true) {
                $charges > 0 && $order->type === 'delivery' => self::DELIVERY,
                $charges > 0 && $order->type === 'room_service' => self::TRAY_CHARGE,
                default => self::ADJUSTMENT,
            };
            $label = [self::DELIVERY => 'Delivery fee', self::TRAY_CHARGE => 'Room-service tray charge', self::ADJUSTMENT => 'Order adjustment'][$kind];
            $lines[] = self::single($kind, $label, $charges, false);
        }
        foreach ($levies as [$name, $minor]) {
            if ($minor !== 0) {
                $lines[] = self::single(self::LEVY, $name, $minor, true);
            }
        }
        foreach ([[self::SERVICE_CHARGE, 'Service charge', $service], [self::TIP, 'Tip', $tip], [self::DONATION, 'Donation', $donation]] as [$kind, $label, $minor]) {
            if ($minor !== 0) {
                $lines[] = self::single($kind, $label, $minor, false);
            }
        }
        if ($discountApplied !== 0) {
            $label = 'Discount'.(($code = self::couponCode($order)) !== null ? ' (coupon '.$code.')' : '');
            $lines[] = self::single(self::DISCOUNT, $label, -$discountApplied, false);
        }

        return new self($currency, $lines, $subtotal, $tax, $grand, $discountApplied);
    }

    /** Major amount (float) of $minor in the invoice currency, at the currency's decimals. */
    public function major(int $minor): float
    {
        return Money::majorFromMinor($minor, $this->currency);
    }

    public function exponent(): int
    {
        return Money::zero($this->currency)->exponent();
    }

    /** Σ line amounts (minor) — + tax = total. */
    public function linesMinor(): int
    {
        return array_sum(array_column($this->lines, 'amount_minor'));
    }

    /**
     * One order line's unit price in minor units, as sold: the same sum as Order::computeMoney() per line.
     */
    private static function unitMinor(OrderItem $item, string $currency): int
    {
        $lineCurrency = strtoupper($item->minorMoneyCurrency());
        $fromLine = static fn (int $minor): int => $lineCurrency === $currency
            ? $minor : Money::minorFromMajor(Money::ofMinor($minor, $lineCurrency)->major(), $currency);

        $unit = 0;
        if ($item->menu) {
            if ($item->getAttribute('unit_price_minor') !== null) {
                $unit = $fromLine((int) $item->getAttribute('unit_price_minor'));
            } else {
                // A legacy line without a unit-price snapshot: its only price is the variant / menu one (as computeMoney()).
                $base = ($item->variation && $item->variant) ? $item->variant->price : $item->menu->price;
                $unit = Money::minorFromMajor($base ?? 0, $currency);
            }
            foreach ($item->itemExtras as $extra) {
                if ($extra->getAttribute('price_minor') !== null) {
                    $unit += $fromLine((int) $extra->getAttribute('price_minor'));
                } elseif (($livePrice = optional($extra->extra)->price) !== null) {
                    $unit += Money::minorFromMajor($livePrice, $currency); // pre-snapshot modifier row
                }
            }
        }
        foreach ((array) $item->options as $group) {
            foreach ((array) ($group['selected'] ?? []) as $opt) {
                $unit += isset($opt['price_minor']) && is_numeric($opt['price_minor'])
                    ? $fromLine((int) $opt['price_minor'])
                    : Money::minorFromMajor(is_numeric($opt['price'] ?? null) ? $opt['price'] : 0, $currency);
            }
        }

        return $unit;
    }

    /** "Tea (Large, Honey)": the line's name with its chosen variant, options and modifiers. */
    private static function describe(OrderItem $item): string
    {
        $name = $item->displayName();
        if ($name === '') {
            $name = (string) (optional($item->menu)->name ?? '');
        }
        $name = $name !== '' ? $name : 'Item';

        $details = [];
        if ($item->variation && $item->variant) {
            $details[] = (string) $item->variation_title;
        }
        foreach ((array) $item->options as $group) {
            if (! empty($group['combo'])) {
                continue; // the combo's own name is the line name
            }
            foreach ((array) ($group['selected'] ?? []) as $opt) {
                $details[] = (string) ($opt['option'] ?? '');
            }
        }
        foreach ($item->itemExtras as $extra) {
            $details[] = (string) (optional($extra->extra)->title ?? '');
        }
        $details = array_values(array_filter($details, static fn (string $d) => $d !== ''));

        return $details === [] ? $name : $name.' ('.implode(', ', $details).')';
    }

    /**
     * @return array{kind: string, description: string, quantity: int, unit_minor: int, amount_minor: int, taxable: bool}
     */
    private static function single(string $kind, string $description, int $minor, bool $taxable): array
    {
        return ['kind' => $kind, 'description' => $description, 'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor, 'taxable' => $taxable];
    }

    /** Delivery / tray charge as Order::computeMoney() derives it — only used when a floored total hides it. */
    private static function liveCharges(Order $order, string $currency): int
    {
        $postOptions = store_options($order->store_id);
        $delivery = $order->deliveryCharge($postOptions);
        $subtotal = $order->getAttribute('subtotal_minor') !== null
            ? Money::majorFromMinor((int) $order->getAttribute('subtotal_minor'), $currency)
            : (float) $order->computeMoney()['subtotal'];
        $tray = Order::trayCharge($order->type, $subtotal, $order->store_id, $currency);

        return Money::minorFromMajor($delivery + $tray, $currency);
    }

    private static function couponCode(Order $order): ?string
    {
        if (! $order->coupon_id || ! class_exists(Coupon::class)) {
            return null;
        }
        $code = Coupon::query()->withoutGlobalScopes()->whereKey($order->coupon_id)->value('code');

        return $code !== null && $code !== '' ? (string) $code : null;
    }
}
