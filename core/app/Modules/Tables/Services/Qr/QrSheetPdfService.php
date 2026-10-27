<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services\Qr;

use Juwri\Core\Support\Pdf\PdfDocument;

/**
 * The A4 bulk sheet, drawn with TCPDF (J70) so Arabic / Urdu table and store names are shaped and
 * drawn (bundled DejaVu Sans, bidi + Arabic joining done by the engine), RTL-aware per label.
 *
 * The QR bitmaps are embedded as the original PNG bytes (lossless, no JPEG ringing, which
 * would cost a scan). Each cell carries its own direction, so an Arabic label sits correctly
 * under a code even on an English sheet.
 */
class QrSheetPdfService
{
    private const COLS = 3;

    private const ROWS = 4;

    private const MARGIN_X = 10;

    private const MARGIN_TOP = 10;

    private const CELL_H = 68;

    private const QR_SIZE = 48;

    /** Kept for callers: with a Unicode font nothing is dropped any more. */
    public function droppedNonLatinLabels(): bool
    {
        return false;
    }

    /**
     * @param  array<int, array{png: string, label: string}>  $cells
     */
    public function sheet(array $cells, string $title = ''): string
    {
        $cells = array_values(array_filter($cells, fn ($c) => ! empty($c['png'])));
        $perPage = self::COLS * self::ROWS;

        $pdf = PdfDocument::make('A4', [self::MARGIN_X, self::MARGIN_TOP, self::MARGIN_X, 12], $title !== '' ? $title : 'QR', false, false);
        $cellW = (210 - 2 * self::MARGIN_X) / self::COLS;

        $pages = max(1, (int) ceil(count($cells) / $perPage));
        for ($p = 0; $p < $pages; $p++) {
            $pdf->AddPage();
            $slice = array_slice($cells, $p * $perPage, $perPage);

            for ($r = 0; $r < self::ROWS; $r++) {
                for ($c = 0; $c < self::COLS; $c++) {
                    $cell = $slice[$r * self::COLS + $c] ?? null;
                    if ($cell === null) {
                        continue;
                    }
                    $x = self::MARGIN_X + $c * $cellW;
                    $y = self::MARGIN_TOP + $r * self::CELL_H;

                    $pdf->SetLineStyle(['width' => 0.2, 'dash' => '2,1.5', 'color' => [187, 187, 187]]);
                    $pdf->Rect($x, $y, $cellW, self::CELL_H);

                    $pdf->Image('@'.$cell['png'], $x + ($cellW - self::QR_SIZE) / 2, $y + 7, self::QR_SIZE, self::QR_SIZE, 'PNG');

                    $label = trim((string) ($cell['label'] ?? ''));
                    if ($label !== '') {
                        $pdf->setRTL(PdfDocument::isRtlText($label));
                        $pdf->SetFont(PdfDocument::FONT, '', 10);
                        $pdf->SetTextColor(0, 0, 0);
                        // In an RTL run TCPDF measures x from the right page edge, so mirror the left edge.
                        $labelX = $x + 2;
                        $pdf->setXY($pdf->getRTL() ? 210 - $labelX - ($cellW - 4) : $labelX, $y + 7 + self::QR_SIZE + 3);
                        $pdf->MultiCell($cellW - 4, 5, $label, 0, 'C', false, 0, null, null, true, 0, false, true, self::CELL_H - 7 - self::QR_SIZE - 5, 'T');
                        $pdf->setRTL(false);
                    }
                }
            }

            if ($title !== '') {
                $pdf->setRTL(PdfDocument::isRtlText($title));
                $pdf->SetFont(PdfDocument::FONT, '', 8);
                $pdf->SetTextColor(85, 85, 85);
                $pdf->setXY(self::MARGIN_X, 285);
                $pdf->Cell(210 - 2 * self::MARGIN_X, 4, $title, 0, 0, 'L');
                $pdf->setRTL(false);
            }
        }

        return $pdf->Output('qr.pdf', 'S');
    }
}
