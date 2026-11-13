<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\Pdf\PdfDocument;
use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;

/**
 * P6-172: the order receipt / simplified tax invoice as a PDF, in pure PHP (TCPDF, J70) so it works on shared hosting:
 * no headless browser, Node or shell process.
 *
 * This class RENDERS, it never computes money. Every amount on the page (lines, per-line VAT and rate, the VAT total
 * and the grand total) is read from {@see OrderFiscalContext} -- the same snapshot the printed receipt block and the
 * fiscal documents use. The QR is the store's fiscal profile QR (ZATCA TLV for KSA) for that same context. Amounts are
 * only formatted for display (decimals of the order's currency), never summed, rounded or derived.
 */
class OrderReceiptPdf
{
    /** @return string the PDF bytes */
    public function render(Store $store, Order $order): string
    {
        [$html, $title, $rtl, $qr] = $this->build($store, $order);

        return $this->pdf($html, $title.' #'.$order->id, $rtl, $qr);
    }

    /**
     * The HTML the PDF is written from. The fiscal QR is not part of it: it is drawn natively by TCPDF in render().
     *
     * @return array{0: string, 1: string, 2: bool} [html, title, rtl]
     */
    public function html(Store $store, Order $order): array
    {
        return array_slice($this->build($store, $order), 0, 3);
    }

    /** @return array{0: string, 1: string, 2: bool, 3: ?string} [html, title, rtl, qr payload] */
    private function build(Store $store, Order $order): array
    {
        $order->loadMissing(['items.menu', 'items.variant', 'items.itemExtras.extra']);
        $ctx = OrderFiscalContext::for($store, $order);

        $header = app(FiscalReceipts::class)->receiptHeader($store, $ctx);
        $qr = $header['qr'];
        $title = $header['title'] ?? ___('Receipt');

        $currency = $ctx->currency;
        $decimals = money_decimals($currency);
        $fmt = fn (float $v): string => number_format($v, $decimals, '.', ',');

        $lines = [];
        foreach ($ctx->lines as $l) {
            $lines[] = [
                'name' => $l->name,
                'qty' => rtrim(rtrim(number_format($l->quantity, 3, '.', ''), '0'), '.'),
                'unit' => $fmt($l->unitPrice),
                'rate' => $l->tax !== null || $l->rate > 0 ? rtrim(rtrim(number_format($l->rate, 2, '.', ''), '0'), '.').'%' : '',
                'tax' => $l->tax !== null ? $fmt($l->tax) : '',
                'discount' => $l->discount > 0 ? $fmt($l->discount) : '',
                'amount' => $l->amount !== null ? $fmt($l->amount) : '',
                'kind' => $l->kind,
            ];
        }

        $locale = app()->getLocale();
        $rtl = function_exists('is_rtl') ? is_rtl($locale) : in_array($locale, ['ar', 'ur', 'fa', 'he'], true);

        $html = view('owner.orders.receipt-pdf', [
            'title' => $title,
            'store' => $store,
            'order' => $order,
            'ctx' => $ctx,
            'showVat' => $header['showVat'],
            'showCr' => $header['showCr'],
            'lines' => $lines,
            'currency' => $currency,
            'total' => $fmt($ctx->total),
            'vatTotal' => $fmt($ctx->taxAmount),
            'discount' => $ctx->discount > 0 ? $fmt($ctx->discount) : null,
            'issuedAt' => $ctx->issuedAt->format('Y-m-d H:i'),
            'hasQr' => $qr !== null && $qr !== '',
            'qrPng' => null,
            'rtl' => $rtl,
        ])->render();

        return [$html, $title, $rtl, $qr !== null && $qr !== '' ? $qr : null];
    }

    private function pdf(string $html, string $title, bool $rtl, ?string $qr = null): string
    {
        $pdf = PdfDocument::make([80, 297], [5, 6, 5, 6], $title, $rtl);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        if ($qr !== null) {
            $size = min(70, PdfDocument::qrSize($qr, 36)); // square incl. the 4-module quiet zone; grows for a 9-tag ZATCA code
            $y = $pdf->GetY() + 3;
            if ($y + $size > $pdf->getPageHeight() - 6) {
                $pdf->AddPage();
                $y = 6;
            }
            PdfDocument::drawQr($pdf, $qr, (80 - $size) / 2, $y, $size);
        }

        return $pdf->Output('receipt.pdf', 'S');
    }
}
