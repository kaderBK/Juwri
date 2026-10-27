<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN E-P1: builds an ETA Receipt v1.2 JSON (and Return Receipt v1.0 for
 * refunds) from order-derived totals. Computes the canonical uuid and chains
 * previousUUID per POS device. Never recomputes tax. A reconciliation assert
 * guards the total.
 */
class ReceiptBuilder
{
    public function __construct(private EtaCanonicalizer $canon = new EtaCanonicalizer) {}

    /**
     * @param  array{rin:string,branchCode:string,deviceSerial:string,receiptNumber:string,previousUUID?:string}  $device
     * @return array{receipt:array,uuid:string,canonical:string}
     */
    public function build(FiscalReceiptContext $ctx, array $device, bool $isReturn = false, ?string $referenceOldUUID = null): array
    {
        $this->reconcile(round($ctx->total - $ctx->taxAmount, 2), $ctx->taxAmount, $ctx->total);
        // P6-99: per-line receipt. ETA applies the receipt-level discount AFTER tax (extraReceiptDiscountData), which
        // is how the order discount was charged — so item VAT stays per line and the discount is its gross amount.
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $m = fn (int|float $v): string => FiscalInvoiceMath::fmt($v, 2);
        $extra = $f['allowance_total'] + array_sum(array_column($f['allowances'], 'tax'));
        $byType = [];
        foreach ($f['lines'] as $l) {
            $byType['T1'] = ($byType['T1'] ?? 0) + $l['tax'];
        }

        $receipt = [
            'header' => [
                'dateTimeIssued' => gmdate('Y-m-d\TH:i:s\Z', $ctx->issuedAt->getTimestamp()),
                'receiptNumber' => $device['receiptNumber'],
                'uuid' => '',
                'previousUUID' => $device['previousUUID'] ?? '',
                'referenceOldUUID' => $referenceOldUUID ?? '',
                'currency' => 'EGP',
            ],
            'documentType' => [
                'receiptType' => $isReturn ? 'r' : 's', // s=sale, r=return
                'typeVersion' => $isReturn ? '1.0' : '1.2',
            ],
            'seller' => [
                'rin' => $device['rin'],
                'companyTradeName' => $ctx->sellerName,
                'branchCode' => $device['branchCode'],
                'deviceSerialNumber' => $device['deviceSerial'],
            ],
            'itemData' => array_map(fn (array $l) => [
                'description' => $l['name'],
                'quantity' => $l['qty'],
                'unitPrice' => FiscalInvoiceMath::unitPrice($l, 2),
                'totalSale' => $m($l['gross']),
                'commercialDiscountData' => $l['discount'] !== 0 ? [['amount' => $m($l['discount']), 'description' => 'Discount']] : [],
                'netSale' => $m($l['net']),
                'taxableItems' => [[
                    'taxType' => 'T1',
                    'amount' => $m($l['tax']),
                    'subType' => $l['exemption_code'] ?? 'V009',
                    'rate' => round($l['rate'], 2),
                ]],
                'total' => $m($l['net'] + $l['tax']),
            ], $f['lines']),
            'totalSales' => $m(array_sum(array_column($f['lines'], 'gross'))),
            'totalCommercialDiscount' => $m(array_sum(array_column($f['lines'], 'discount'))),
            'netAmount' => $m($f['line_extension']),
            'extraReceiptDiscountData' => $extra !== 0 ? [['amount' => $m($extra), 'description' => 'Discount']] : [],
            'roundingAmount' => $m($f['rounding']),
            'totalAmount' => $m($f['payable']),
            'taxTotals' => array_map(fn (string $t, int $v) => ['taxType' => $t, 'amount' => $m($v)], array_keys($byType ?: ['T1' => 0]), array_values($byType ?: ['T1' => 0])),
        ];

        // uuid = SHA-256 of the canonicalized content (uuid field itself blank).
        $uuidSource = $receipt;
        unset($uuidSource['header']['uuid']);
        $uuid = $this->canon->uuid($uuidSource);
        $receipt['header']['uuid'] = $uuid;
        $canonical = $this->canon->canonicalize($uuidSource);

        return ['receipt' => $receipt, 'uuid' => $uuid, 'canonical' => $canonical];
    }

    /** P6-99: the VAT an ETA receipt reports — the items' VAT (the receipt discount is after tax). Major units. */
    public static function vat(FiscalReceiptContext $ctx): float
    {
        return FiscalInvoiceMath::major(array_sum(array_column(FiscalInvoiceMath::compute($ctx, 2)['lines'], 'tax')), 2);
    }

    public function reconcile(float $net, float $tax, float $total, float $tolerance = 0.01): void
    {
        if ($tax < -$tolerance || $tax > $total + $tolerance || abs(($net + $tax) - $total) > $tolerance) {
            throw new \RuntimeException(sprintf(
                'ETA reconciliation failed: net=%.2f tax=%.2f total=%.2f.', $net, $tax, $total
            ));
        }
    }
}
