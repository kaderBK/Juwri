<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * TAX-RUN Z-P1: value object describing the data a ZATCA UBL invoice needs.
 * Built from an Order + F0 identity by ZatcaDriver; kept framework-free so the
 * UblBuilder and its golden tests never touch the DB.
 */
class UblInvoice
{
    /**
     * @param  string  $type  simplified|standard|credit_note
     * @param  array<int,array{name:string,qty:float,unit_price:float,tax_rate:float,line_total:float,tax_amount:float,tax_category?:string,exemption_code?:string,exemption_reason?:string}>  $lines
     * @param  array<string,mixed>|null  $fiscal
     * @param  array<string,string>  $sellerAddress
     * @param  array<string,string>|null  $buyer
     */
    public function __construct(
        public string $type,
        public string $number,
        public string $uuid,
        public \DateTimeInterface $issuedAt,
        public string $currency,
        public string $sellerName,
        public string $sellerVat,
        public ?string $sellerCr,
        public int $icv,
        public ?string $previousHash,
        public array $lines,
        public float $total,
        public float $taxTotal,
        public ?string $referencedNumber = null, // credit note → original invoice
        // P4-01: a credit note of a SIMPLIFIED (B2C) invoice is itself simplified (subtype 02…); KSA-10 reason.
        public bool $simplifiedNote = true,
        public ?string $reason = null,
        // P6-99: the per-line result of FiscalInvoiceMath (SAR, 2 decimals). Null = legacy `lines` arrays.
        public ?array $fiscal = null,
        // P6-176: the seller's national address (ZatcaParties::ADDRESS keys) and, on a standard (B2B) document, the buyer
        // (name, vat + the same address keys). Empty / null = not given (a standard document is then refused).
        public array $sellerAddress = [],
        public ?array $buyer = null,
        // P6-186 (SDK BR-KSA-16): BT-81 payment means code — 10 cash, 30 credit transfer, 42 bank account, 48 card,
        // 1 instrument not defined (the default: the sale's tender is not passed to the document yet)
        public string $paymentMeansCode = '1',
    ) {}

    /**
     * P6-99: lines, document allowances, TaxSubtotals and totals in minor units (see FiscalInvoiceMath). Legacy
     * `lines` arrays (name/qty/unit_price/tax_rate/line_total/tax_amount) map to standard-rated lines.
     *
     * @return array<string,mixed>
     */
    public function fiscal(): array
    {
        if ($this->fiscal !== null) {
            return $this->fiscal;
        }
        $rows = [];
        $groups = [];
        foreach ($this->lines as $l) {
            $net = (int) round((float) $l['line_total'] * 100);
            $tax = (int) round((float) $l['tax_amount'] * 100);
            $rate = (float) $l['tax_rate'];
            $cat = (string) ($l['tax_category'] ?? ($rate > 0 ? 'S' : 'Z'));
            $rows[] = ['name' => (string) $l['name'], 'qty' => (float) $l['qty'], 'unit_price_minor' => (float) $l['unit_price'] * 100,
                'gross' => $net, 'discount' => 0, 'net' => $net, 'tax' => $tax, 'category' => $cat, 'rate' => $rate,
                'exemption_code' => $l['exemption_code'] ?? null, 'exemption_reason' => $l['exemption_reason'] ?? null, 'kind' => 'item'];
            $k = $cat.'|'.$rate;
            $groups[$k] ??= ['category' => $cat, 'rate' => $rate, 'exemption_code' => $l['exemption_code'] ?? null, 'exemption_reason' => $l['exemption_reason'] ?? null, 'taxable' => 0, 'tax' => 0];
            $groups[$k]['taxable'] += $net;
        }
        $lineExt = array_sum(array_column($rows, 'net'));
        $tax = (int) round($this->taxTotal * 100);
        // the document VAT is the given total; per-category VAT follows the lines, the last category absorbs any difference
        $left = $tax;
        $keys = array_keys($groups);
        foreach ($keys as $i => $k) {
            $groups[$k]['tax'] = $i === count($keys) - 1 ? $left : array_sum(array_map(fn ($r) => $k === $r['category'].'|'.$r['rate'] ? $r['tax'] : 0, $rows));
            $left -= $groups[$k]['tax'];
        }

        return $this->fiscal = [
            'decimals' => 2, 'lines' => $rows, 'allowances' => [], 'subtotals' => array_values($groups),
            'line_extension' => $lineExt, 'allowance_total' => 0, 'tax_exclusive' => $lineExt, 'tax_total' => $tax,
            'tax_inclusive' => $lineExt + $tax, 'rounding' => 0, 'excluded' => 0, 'payable' => $lineExt + $tax,
        ];
    }

    public function isSimplified(): bool
    {
        return $this->type === 'simplified';
    }

    public function isCreditNote(): bool
    {
        return $this->type === 'credit_note';
    }

    /** ZATCA invoice type code: 388 invoice, 381 credit note. */
    public function isDebitNote(): bool
    {
        return $this->type === 'debit_note';
    }

    /** Credit or debit note: carries the invoice reference + KSA-10 reason. */
    public function isNote(): bool
    {
        return $this->isCreditNote() || $this->isDebitNote();
    }

    public function typeCode(): string
    {
        return $this->isCreditNote() ? '381' : ($this->isDebitNote() ? '383' : '388');
    }

    /** Transaction subtype: position 1 = 01 standard / 02 simplified. */
    public function typeSubCode(): string
    {
        // P4-01 bug fix: a credit note was always typed "standard" (0100000), also when it credits a simplified
        // (B2C) invoice — ZATCA reads 381 + 0200000 as the simplified credit note.
        return $this->isSimplified() || ($this->isNote() && $this->simplifiedNote) ? '0200000' : '0100000';
    }

    /** P6-176: a standard (B2B, 0100000) document — invoice or a note of one — needs both full parties. */
    public function isStandardDocument(): bool
    {
        return $this->typeSubCode() === '0100000';
    }

    /** Tax-exclusive amount (lines − document allowances), major units. */
    public function netTotal(): float
    {
        return $this->fiscal()['tax_exclusive'] / 100;
    }
}
