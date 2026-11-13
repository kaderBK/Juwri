<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Core\Money\LargestRemainder;
use Juwri\Modules\Fiscal\Data\FiscalLine;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;

/**
 * P6-99: the one place a fiscal document's lines and totals are derived — every builder (ZATCA UBL, JoFotara, ETA,
 * the PINT/UBL export) formats this result instead of inventing a single "Order" line.
 *
 * All arithmetic is in integer MINOR units of the document currency (2 for SAR/EGP, 3 for JOD/OMR/BHD/TND).
 *
 * Line (rounded per line, then summed — ZATCA/EN 16931 line-level rounding):
 *   tax-exclusive line: net = amount − line discount; VAT = the checkout's tax for the line, else round(net × rate)
 *   tax-inclusive line: gross = amount − line discount; VAT = the checkout's tax, else round(gross × rate / (100+rate)); net = gross − VAT
 * Document allowance (the order discount): split over the lines in proportion to their gross (net + VAT) with
 *   largest-remainder in minor units, then one allowance per VAT category/rate; a tax-inclusive discount carries
 *   its own VAT share (round(d × 100/(100+rate)) net, the rest VAT), a tax-exclusive one round(d × rate) VAT.
 * Per category/rate: TaxableAmount = Σ line net − Σ allowance net; TaxAmount = Σ line VAT − Σ allowance VAT.
 * Document: LineExtension = Σ line net; TaxExclusive = LineExtension − allowances; TaxInclusive = TaxExclusive + VAT;
 *   PayableRounding = order total − TaxInclusive (− out-of-scope amounts left off the document); Payable = order total.
 * A rounding amount above one minor unit per line (+1) is a HARD FAIL: the sale's numbers do not add up.
 *
 * A context without lines (legacy callers) becomes one line: net = total − tax, VAT = tax, rate = VAT / net.
 *
 * @phpstan-type Line array{name:string,qty:float,unit_price_minor:float,gross:int,discount:int,net:int,tax:int,category:string,rate:float,exemption_code:?string,exemption_reason:?string,kind:string}
 * @phpstan-type Allowance array{category:string,rate:float,exemption_code:?string,exemption_reason:?string,amount:int,tax:int}
 * @phpstan-type Subtotal array{category:string,rate:float,exemption_code:?string,exemption_reason:?string,taxable:int,tax:int}
 * @phpstan-type Result array{decimals:int,lines:list<Line>,allowances:list<Allowance>,subtotals:list<Subtotal>,line_extension:int,allowance_total:int,tax_exclusive:int,tax_total:int,tax_inclusive:int,rounding:int,excluded:int,payable:int}
 */
final class FiscalInvoiceMath
{
    /**
     * @param  bool  $dropOutOfScope  ZATCA: an invoice may not mix category O with others (BR-O-*) — O lines (tips,
     *                                donations) are then left off the document and reported as `excluded`.
     * @return Result
     */
    public static function compute(FiscalReceiptContext $ctx, int $decimals, bool $dropOutOfScope = false): array
    {
        $f = 10 ** $decimals;
        $total = self::minor($ctx->total, $f);
        $lines = $ctx->lines !== [] ? $ctx->lines : [self::aggregateLine($ctx)];

        $hasInScope = false;
        foreach ($lines as $l) {
            $hasInScope = $hasInScope || $l->category !== FiscalLine::OUT_OF_SCOPE;
        }

        $out = [];
        $excluded = 0;
        foreach ($lines as $l) {
            $row = self::line($l, $f);
            if ($dropOutOfScope && $hasInScope && $l->category === FiscalLine::OUT_OF_SCOPE) {
                $excluded += $row['net'] + $row['tax'];

                continue;
            }
            $out[] = $row;
        }

        $allowances = self::allowances($out, self::minor($ctx->discount, $f), $ctx->discountIncludesTax);

        $groups = [];
        foreach ($out as $r) {
            $k = self::key($r);
            $groups[$k] ??= ['category' => $r['category'], 'rate' => $r['rate'], 'exemption_code' => $r['exemption_code'], 'exemption_reason' => $r['exemption_reason'], 'taxable' => 0, 'tax' => 0];
            $groups[$k]['taxable'] += $r['net'];
            $groups[$k]['tax'] += $r['tax'];
        }
        foreach ($allowances as $a) {
            $k = self::key($a);
            $groups[$k]['taxable'] -= $a['amount'];
            $groups[$k]['tax'] -= $a['tax'];
        }

        $lineExt = array_sum(array_column($out, 'net'));
        $allowTotal = array_sum(array_column($allowances, 'amount'));
        $taxTotal = array_sum(array_column($groups, 'tax'));
        $taxExcl = $lineExt - $allowTotal;
        $taxIncl = $taxExcl + $taxTotal;
        $rounding = $total - $taxIncl - $excluded;

        $slack = count($out) + count($allowances) + 1;
        if (abs($rounding) > $slack) {
            throw new \RuntimeException(sprintf(
                'Fiscal reconciliation failed: lines %d + VAT %d (+ %d out of scope) ≠ sale total %d minor units.',
                $taxExcl, $taxTotal, $excluded, $total
            ));
        }

        return [
            'decimals' => $decimals, 'lines' => $out, 'allowances' => $allowances, 'subtotals' => array_values($groups),
            'line_extension' => $lineExt, 'allowance_total' => $allowTotal, 'tax_exclusive' => $taxExcl,
            'tax_total' => $taxTotal, 'tax_inclusive' => $taxIncl, 'rounding' => $rounding, 'excluded' => $excluded,
            'payable' => $taxIncl + $rounding,
        ];
    }

    /** Minor units → a fixed-decimals string ("12.345"). */
    public static function fmt(int|float $minor, int $decimals): string
    {
        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    /** Minor units → major float. */
    public static function major(int $minor, int $decimals): float
    {
        return round($minor / (10 ** $decimals), $decimals);
    }

    /**
     * Unit price (net, before the line discount) as a string: currency decimals when exact, else up to 6.
     *
     * @param  Line  $line
     */
    public static function unitPrice(array $line, int $decimals): string
    {
        $p = $line['unit_price_minor'] / (10 ** $decimals);
        $exact = number_format($p, $decimals, '.', '');
        if (abs((float) $exact * $line['qty'] - $line['gross'] / (10 ** $decimals)) < 1e-9) {
            return $exact;
        }

        return rtrim(rtrim(number_format($p, 6, '.', ''), '0'), '.');
    }

    /**
     * Split $total minor units over $weights (non-negative) by largest remainder — the parts always sum to $total.
     *
     * @param  array<int,int>  $weights
     * @return array<int,int>
     */
    public static function allocate(int $total, array $weights): array
    {
        return LargestRemainder::allocate($total, $weights);
    }

    private static function minor(float $v, int $f): int
    {
        return (int) round(round($v * $f, 6));
    }

    private static function aggregateLine(FiscalReceiptContext $ctx): FiscalLine
    {
        $net = $ctx->total - $ctx->taxAmount;
        $taxed = $ctx->taxAmount > 0;

        return new FiscalLine(
            name: 'Order', quantity: 1.0, unitPrice: $net,
            category: $taxed ? FiscalLine::STANDARD : FiscalLine::OUT_OF_SCOPE,
            rate: $taxed && $net > 0 ? round($ctx->taxAmount / $net * 100, 2) : 0.0,
            tax: $ctx->taxAmount,
            exemptionCode: $taxed ? null : 'VATEX-SA-OOS', exemptionReason: $taxed ? null : 'Not subject to VAT',
        );
    }

    /** @return Line */
    private static function line(FiscalLine $l, int $f): array
    {
        $rate = $l->category === FiscalLine::STANDARD ? $l->rate : 0.0;
        $gross = $l->amount !== null ? self::minor($l->amount, $f) : self::minor($l->quantity * $l->unitPrice, $f);
        $disc = self::minor($l->discount, $f);
        $after = $gross - $disc;

        if ($l->pricesIncludeTax) {
            $tax = $l->tax !== null ? self::minor($l->tax, $f) : (int) round($after * $rate / (100 + $rate));
            $net = $after - $tax;
            $netGross = $rate > 0 ? (int) round($gross * 100 / (100 + $rate)) : $gross;
            $netDisc = $netGross - $net;
        } else {
            $net = $after;
            $tax = $l->tax !== null ? self::minor($l->tax, $f) : (int) round($net * $rate / 100);
            $netGross = $gross;
            $netDisc = $disc;
        }
        $qty = $l->quantity > 0 ? $l->quantity : 1.0;

        return [
            'name' => $l->name, 'qty' => $qty, 'unit_price_minor' => $netGross / $qty, 'gross' => $netGross,
            'discount' => $netDisc, 'net' => $net, 'tax' => $tax, 'category' => $l->category, 'rate' => $rate,
            'exemption_code' => $l->category === FiscalLine::STANDARD ? null : $l->exemptionCode,
            'exemption_reason' => $l->category === FiscalLine::STANDARD ? null : $l->exemptionReason,
            'kind' => $l->kind,
        ];
    }

    /**
     * @param  list<Line>  $lines
     * @return list<Allowance>
     */
    private static function allowances(array $lines, int $discount, bool $includesTax): array
    {
        if ($discount <= 0) {
            return [];
        }
        $weights = [];
        foreach ($lines as $i => $r) {
            // an order discount never reduces a tip/donation, only what was sold
            $weights[$i] = $r['category'] === FiscalLine::OUT_OF_SCOPE && $r['kind'] !== 'item' ? 0 : max(0, $r['net'] + ($includesTax ? $r['tax'] : 0));
        }
        $discount = min($discount, array_sum($weights)); // the sale total is floored at 0
        $byKey = [];
        foreach (self::allocate($discount, $weights) as $i => $part) {
            if ($part === 0) {
                continue;
            }
            $k = self::key($lines[$i]);
            $byKey[$k] ??= ['row' => $lines[$i], 'gross' => 0];
            $byKey[$k]['gross'] += $part;
        }
        $out = [];
        foreach ($byKey as $g) {
            $rate = $g['row']['rate'];
            if ($includesTax) {
                $amount = $rate > 0 ? (int) round($g['gross'] * 100 / (100 + $rate)) : $g['gross'];
                $tax = $g['gross'] - $amount;
            } else {
                $amount = $g['gross'];
                $tax = (int) round($amount * $rate / 100);
            }
            $out[] = [
                'category' => $g['row']['category'], 'rate' => $rate, 'exemption_code' => $g['row']['exemption_code'],
                'exemption_reason' => $g['row']['exemption_reason'], 'amount' => $amount, 'tax' => $tax,
            ];
        }

        return $out;
    }

    /** @param  array{category:string,rate:float,exemption_code:?string}  $r */
    private static function key(array $r): string
    {
        return $r['category'].'|'.number_format($r['rate'], 2, '.', '').'|'.($r['exemption_code'] ?? '');
    }
}
