<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Tax;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\LargestRemainder;
use Juwri\Core\Settings\PlatformSettings;

/**
 * P6-99b: the VAT engine (TAX-ENGINE.md). One derivation of an order's VAT, line by line, in integer
 * minor units of the order's currency — the order's tax, its fiscal invoice and its accounting journal all read it.
 *
 * Applies when the store opted in (`tax_engine` = vat, percentage tax) or the order already carries a snapshot
 * (`orders.tax_lines`: an order keeps the rates and mode it was placed with). Every other order keeps the legacy
 * tax ({@see Order::computeTax()}), bit-identical.
 *
 * - items: the line amount as charged (the snapshots {@see Order::itemsSubtotal()} reads); the item's own rate and
 *   category (items.tax_rate / tax_category), else the store's rate;
 * - delivery / room-service tray / service charge: each taxed at the store rate when the owner turned that fee's
 *   switch on (J32: `tax_delivery_taxable`, `tax_tray_taxable`, `tax_service_taxable`, all OFF by default), else
 *   out of scope;
 * - tip, donation: out of scope (no supply);
 * - the order discount reduces the taxable base pro rata (largest remainder over the items, any excess over fees);
 * - exclusive: VAT = round(net × rate) on top; inclusive: prices hold the VAT, VAT = round(gross × rate / (100+rate)).
 * - J32 levies ({@see TaxLevies}): after the discount and before VAT, each its own line (kind `levy`), added on top
 *   (never inside menu prices); VAT at the store rate is due on a levy when the store charges VAT.
 *
 * @phpstan-type TaxLine array{kind: string, ref: int|string|null, name: string, qty: float, amount: int, discount: int, net: int, tax: int, rate: float, category: string}
 * @phpstan-type TaxResult array{v: int, mode?: string, inclusive: bool, fees_taxable: bool, fees?: array<string, bool>, levies?: list<array<string, mixed>>, rate: float, decimals: int, tax: int, lines: list<TaxLine>}
 */
final class TaxEngine
{
    public const CATEGORIES = ['S', 'Z', 'E'];

    /** J32: fee => its own taxability switch (OFF by default). */
    public const FEE_FLAGS = ['delivery' => 'tax_delivery_taxable', 'tray' => 'tax_tray_taxable', 'service' => 'tax_service_taxable'];

    /** P6-186 (J44): VAT rounded and stored per line ("VAT per line"). */
    public const MODE_LINE = 'line';

    /** P6-186 (J44): VAT worked out once per rate on the whole order, then shared over the lines ("whole-order VAT"). */
    public const MODE_ORDER = 'order';

    /** P6-186: platform switch "force VAT per line for all KSA stores" (J44a: default ON). */
    public const FORCE_KSA_LINE = 'zatca_force_vat_per_line';

    /** P6-186: `orders.tax_lines` marker of an order placed on the legacy tax before its store moved to the engine. */
    public const LEGACY_MARKER = '{"mode":"legacy"}';

    /** Whether the store's settings put it on the engine (a fixed tax stays legacy). */
    public static function enabledFor(?object $opts): bool
    {
        return self::storeMode($opts) !== null;
    }

    /**
     * P6-186 (J44): the VAT method a NEW order of this store gets — MODE_LINE, MODE_ORDER, or null (legacy tax).
     * KSA (percentage tax): VAT per line by default; the owner may pick whole-order VAT (`tax_engine` = order) unless
     * the platform forces VAT per line. Elsewhere: `tax_engine` vat / order, or a levy turned on (line).
     */
    public static function storeMode(?object $opts): ?string
    {
        if ((string) ($opts->restaurant_tax_type ?? 'percentage') === 'fixed') {
            return null;
        }
        $engine = (string) ($opts->tax_engine ?? '');
        if (self::isKsa($opts)) {
            return $engine === 'order' && ! self::ksaForced() ? self::MODE_ORDER : self::MODE_LINE;
        }
        if ($engine === 'order') {
            return self::MODE_ORDER;
        }

        return $engine === 'vat' || TaxLevies::enabled($opts) !== [] ? self::MODE_LINE : null;
    }

    public static function isKsa(?object $opts): bool
    {
        return strtoupper(trim((string) ($opts->fiscal_country ?? ''))) === 'SA';
    }

    /** P6-186: the platform admin forced VAT per line on every KSA store. */
    public static function ksaForced(): bool
    {
        return PlatformSettings::bool(self::FORCE_KSA_LINE, true); // J44a: forced unless the admin turned it off
    }

    /** P6-186: the owner's VAT-per-line toggle is shown locked (KSA store, platform force on). */
    public static function ownerToggleLocked(?object $opts): bool
    {
        return self::isKsa($opts) && self::ksaForced();
    }

    /**
     * P6-186: the `store.options.saving` hook.
     *
     * @param  array<string, mixed>  $options
     * @return array{options: array<string, mixed>, after: callable(): void}
     */
    public static function onStoreOptionsSaving(int $storeId, array $options): array
    {
        $before = store_options($storeId);
        if (array_key_exists('tax_engine', $options) && self::ownerToggleLocked($before)) {
            unset($options['tax_engine']);
        }
        $wasEngine = self::enabledFor($before);

        return ['options' => $options, 'after' => function () use ($storeId, $wasEngine): void {
            if (! $wasEngine && self::enabledFor(store_options($storeId))) {
                self::freezeLegacyOrders($storeId);
            }
        }];
    }

    /**
     * P6-186: a store moving from the legacy tax onto the engine — its orders placed so far keep the legacy tax
     * (issued invoices are never recalculated): every order without a snapshot gets the legacy marker.
     */
    public static function freezeLegacyOrders(int $storeId): int
    {
        return DB::table('orders')->where('store_id', $storeId)->whereNull('tax_lines')->update(['tax_lines' => self::LEGACY_MARKER]);
    }

    /**
     * J32: each fee's taxability, from its own switch (OFF unless the owner turned it on).
     *
     * @return array<string, bool>
     */
    public static function feeFlags(?object $opts): array
    {
        $out = [];
        foreach (self::FEE_FLAGS as $fee => $key) {
            $out[$fee] = filter_var($opts->{$key} ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    public static function inclusive(?object $opts): bool
    {
        return filter_var($opts->tax_prices_include ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** True when the order is priced by the engine (snapshot, or an opted-in store; an offline till's tax wins). */
    public static function applies(Order $order, ?object $opts = null): bool
    {
        if ($order->getAttribute('offline_tax_minor') !== null) {
            return false;
        }
        if (self::snapshot($order) !== null) {
            return true;
        }
        if (self::legacyMarked($order)) {
            return false; // P6-186: placed on the legacy tax before the store moved to the engine
        }

        return self::enabledFor($opts ?? store_options($order->store_id));
    }

    /**
     * The order's stored snapshot, or null.
     *
     * @return TaxResult|null
     */
    public static function snapshot(Order $order): ?array
    {
        $raw = $order->getAttribute('tax_lines');
        if ($raw === null || $raw === '') {
            return null;
        }
        $d = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($d) && isset($d['lines'], $d['tax']) ? $d : null;
    }

    /** P6-186: the order carries the legacy marker. */
    public static function legacyMarked(Order $order): bool
    {
        $raw = $order->getAttribute('tax_lines');
        $d = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : null);

        return is_array($d) && ($d['mode'] ?? null) === 'legacy';
    }

    /**
     * The order's VAT lines, or null when the engine does not apply.
     *
     * @return TaxResult|null
     */
    public static function compute(Order $order, ?object $opts = null): ?array
    {
        $opts ??= store_options($order->store_id);
        if (! self::applies($order, $opts)) {
            return null;
        }
        $frozen = self::snapshot($order);
        // P6-186: a placed order keeps its VAT method (a snapshot before P6-186 was per line)
        $mode = $frozen !== null ? (string) ($frozen['mode'] ?? self::MODE_LINE) : (self::storeMode($opts) ?? self::MODE_LINE);
        $inclusive = $frozen !== null ? (bool) $frozen['inclusive'] : self::inclusive($opts);
        // a v1 snapshot had one switch for every fee; a placed order keeps its levies (none before J32)
        $feeFlags = $frozen !== null
            ? (isset($frozen['fees']) ? array_map('boolval', $frozen['fees']) : array_fill_keys(array_keys(self::FEE_FLAGS), (bool) $frozen['fees_taxable']))
            : self::feeFlags($opts);
        $levies = $frozen !== null ? TaxLevies::normalize($frozen['levies'] ?? []) : TaxLevies::enabled($opts);
        $storeRate = $frozen !== null ? (float) $frozen['rate'] : max(0.0, (float) ($opts->restaurant_taxx_charge ?? 0));
        $frozenRates = [];
        foreach ($frozen['lines'] ?? [] as $l) {
            if ($l['kind'] === 'item' && $l['ref'] !== null) {
                $frozenRates['i'.$l['ref']] = [(float) $l['rate'], (string) $l['category']];
            }
        }

        $currency = $order->currencyCode();
        $decimals = money_decimals($currency);
        $f = 10 ** $decimals;
        $minor = fn ($v): int => (int) round(round((float) $v * $f, 6));

        $rows = [];
        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $amount = $minor($order->itemsSubtotal(fn ($i) => $i === $item || ($item->exists && $i->is($item))));
            [$rate, $cat] = isset($frozenRates['i'.$item->id])
                ? $frozenRates['i'.$item->id]
                : self::itemRate($item, $storeRate);
            $rows[] = ['kind' => 'item', 'ref' => $item->id, 'name' => (string) ($item->displayName() ?: 'Item'),
                'qty' => (float) max(1, (int) $item->quantity), 'amount' => $amount, 'rate' => $rate, 'category' => $cat];
        }
        $subtotal = (float) $order->itemsSubtotal();
        $fees = [
            'delivery' => ['Delivery', $order->deliveryCharge($opts)],
            'tray' => ['Room service', Order::trayCharge($order->type, $subtotal, $order->store_id, $currency)],
            'service' => ['Service charge', (float) ($order->service_charge_amount ?? 0)],
        ];
        foreach ($fees as $key => [$name, $fee]) {
            if ($fee > 0) {
                $taxable = $feeFlags[$key] ?? false;
                $rows[] = ['kind' => 'fee', 'ref' => $name, 'name' => $name, 'qty' => 1.0, 'amount' => $minor($fee),
                    'rate' => $taxable ? $storeRate : 0.0, 'category' => $taxable && $storeRate > 0 ? 'S' : 'O'];
            }
        }
        foreach (['Tip' => $order->tip_amount, 'Donation' => $order->donation_amount] as $name => $v) {
            if ((float) ($v ?? 0) > 0) {
                $rows[] = ['kind' => 'tip', 'ref' => $name, 'name' => $name, 'qty' => 1.0, 'amount' => $minor($v), 'rate' => 0.0, 'category' => 'O'];
            }
        }

        $discounts = self::spreadDiscount($rows, $minor($order->discount_amount ?? 0));
        $lines = [];
        $tax = 0;
        $base = ['items' => 0, 'total' => 0];
        foreach ($rows as $i => $r) {
            $after = $r['amount'] - $discounts[$i];
            $rate = $r['category'] === 'S' ? (float) $r['rate'] : 0.0;
            $t = $rate <= 0 ? 0 : (int) round($inclusive ? $after * $rate / (100 + $rate) : $after * $rate / 100);
            $net = $inclusive ? $after - $t : $after;
            $lines[] = $r + ['discount' => $discounts[$i], 'net' => $net, 'tax' => $t];
            $tax += $t;
            if ($r['kind'] !== 'tip') {
                $base['total'] += $net;
                $base['items'] += $r['kind'] === 'item' ? $net : 0;
            }
        }
        // J32 levies: on the discounted pre-VAT base, VAT (store rate) on top of each — always added, never inclusive
        foreach ($levies as $levy) {
            $amount = TaxLevies::amount($levy, max(0, $base[$levy['base']]), $decimals);
            if ($amount <= 0) {
                continue;
            }
            $rate = $storeRate > 0 ? $storeRate : 0.0;
            $t = $rate > 0 ? (int) round($amount * $rate / 100) : 0;
            $lines[] = ['kind' => 'levy', 'ref' => $levy['id'], 'name' => $levy['name'], 'qty' => 1.0, 'amount' => $amount,
                'rate' => $rate, 'category' => $rate > 0 ? 'S' : 'O', 'discount' => 0, 'net' => $amount, 'tax' => $t];
            $tax += $t;
        }

        if ($mode === self::MODE_ORDER) {
            [$lines, $tax] = self::wholeOrderVat($lines, $inclusive);
        }

        return ['v' => 3, 'mode' => $mode, 'inclusive' => $inclusive, 'fees_taxable' => in_array(true, $feeFlags, true), 'fees' => $feeFlags,
            'levies' => $levies, 'rate' => $storeRate, 'decimals' => $decimals, 'tax' => $tax, 'lines' => $lines];
    }

    /**
     * P6-186 (J44) whole-order VAT: per rate (and inclusive/exclusive), VAT = round(Σ base × rate) once on the whole
     * order, shared back over the lines by largest remainder — Σ line VAT = the order's VAT, so the receipt, the
     * invoice lines and the journal still read one number. Levies are always exclusive (their own group).
     *
     * @param  list<array<string, mixed>>  $lines  line-mode lines
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function wholeOrderVat(array $lines, bool $inclusive): array
    {
        $groups = [];
        foreach ($lines as $i => $l) {
            $rate = $l['category'] === 'S' ? (float) $l['rate'] : 0.0;
            if ($rate <= 0) {
                continue;
            }
            $incl = $inclusive && $l['kind'] !== 'levy';
            $groups[$rate.'|'.($incl ? 1 : 0)]['rate'] = $rate;
            $groups[$rate.'|'.($incl ? 1 : 0)]['incl'] = $incl;
            $groups[$rate.'|'.($incl ? 1 : 0)]['w'][$i] = max(0, $l['amount'] - $l['discount']);
        }
        foreach ($groups as $g) {
            $base = array_sum($g['w']);
            $t = (int) round($g['incl'] ? $base * $g['rate'] / (100 + $g['rate']) : $base * $g['rate'] / 100);
            foreach (LargestRemainder::allocate($t, $g['w']) as $i => $part) {
                $lines[$i]['tax'] = $part;
                if ($g['incl']) {
                    $lines[$i]['net'] = $lines[$i]['amount'] - $lines[$i]['discount'] - $part;
                }
            }
        }

        return [$lines, array_sum(array_column($lines, 'tax'))];
    }

    /**
     * The order's money on the engine (same shape as {@see Order::computeMoney()}), or null when it does not apply.
     *
     * @return array{subtotal: float, tax: float, grand: float}|null
     */
    public static function money(Order $order, ?object $opts = null): ?array
    {
        $r = self::compute($order, $opts);
        if ($r === null) {
            return null;
        }
        $f = 10 ** $r['decimals'];
        $gross = 0;
        $discount = 0;
        $levyTax = 0;
        foreach ($r['lines'] as $l) {
            $gross += $l['amount'];
            $discount += $l['discount'];
            $levyTax += $l['kind'] === 'levy' ? $l['tax'] : 0;
        }
        // inclusive prices hold their VAT; a levy's VAT is always on top
        $grand = $gross - $discount + ($r['inclusive'] ? $levyTax : $r['tax']);

        return ['subtotal' => (float) $order->itemsSubtotal(), 'tax' => $r['tax'] / $f, 'grand' => max(0, $grand) / $f];
    }

    /**
     * J32: the order's levy lines (minor units of the order currency), for receipts, invoices and the journal.
     *
     * @return list<array{id: string, name: string, amount: int, tax: int, decimals: int}>
     */
    public static function levyLines(Order $order, ?object $opts = null): array
    {
        $r = self::compute($order, $opts);
        $out = [];
        foreach ($r['lines'] ?? [] as $l) {
            if ($l['kind'] === 'levy') {
                $out[] = ['id' => (string) $l['ref'], 'name' => $l['name'], 'amount' => $l['amount'], 'tax' => $l['tax'], 'decimals' => $r['decimals']];
            }
        }

        return $out;
    }

    /** J32: Σ levies (without their VAT) in major units of the order currency. */
    public static function levyTotal(Order $order, ?object $opts = null): float
    {
        $lines = self::levyLines($order, $opts);

        return $lines === [] ? 0.0 : array_sum(array_column($lines, 'amount')) / (10 ** $lines[0]['decimals']);
    }

    /**
     * JSON for `orders.tax_lines`.
     *
     * @param  array<string,mixed>  $result
     */
    public static function encode(array $result): string
    {
        return (string) json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * An item's rate and category: the item's own, else the store's standard rate.
     *
     * @return array{0: float, 1: string}
     */
    public static function itemRate(OrderItem $item, float $storeRate): array
    {
        $menu = $item->item_id ? $item->menu : null;
        $cat = strtoupper((string) ($menu?->getAttribute('tax_category') ?? ''));
        $cat = in_array($cat, self::CATEGORIES, true) ? $cat : 'S';
        if ($cat !== 'S') {
            return [0.0, $cat];
        }
        $own = $menu?->getAttribute('tax_rate');
        $rate = $own !== null && $own !== '' ? max(0.0, (float) $own) : $storeRate;

        // P6-186: no VAT charged (a store without a VAT rate, now on the engine by default in KSA) is out of scope,
        // never "standard rate 0 %" (an invalid S line on a ZATCA document — Z is the zero-rated category)
        return $rate > 0 ? [$rate, 'S'] : [0.0, 'O'];
    }

    /**
     * The order discount over the lines: items first (pro rata, largest remainder), any excess over the fees; never
     * a tip or donation, never more than the lines.
     *
     * @param  list<array{kind: string, amount: int}>  $rows
     * @return array<int, int>
     */
    private static function spreadDiscount(array $rows, int $discount): array
    {
        $out = array_fill(0, count($rows), 0);
        if ($discount <= 0 || $rows === []) {
            return $out;
        }
        foreach (['item', 'fee'] as $kind) {
            $weights = [];
            foreach ($rows as $i => $r) {
                if ($r['kind'] === $kind) {
                    $weights[$i] = max(0, $r['amount']);
                }
            }
            $take = min($discount, array_sum($weights));
            foreach (LargestRemainder::allocate($take, $weights) as $i => $part) {
                $out[$i] += $part;
            }
            $discount -= $take;
            if ($discount <= 0) {
                break;
            }
        }

        return $out;
    }
}
