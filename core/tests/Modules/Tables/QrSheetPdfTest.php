<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use Juwri\Modules\Tables\Services\Qr\QrRenderService;
use Juwri\Modules\Tables\Services\Qr\QrSheetPdfService;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/** P6-264: the A4 QR sheet is TCPDF; Arabic / Urdu names are in the PDF text and the QR bitmap is kept. */
class QrSheetPdfTest extends TestCase
{
    private function cell(string $label): array
    {
        return ['png' => app(QrRenderService::class)->png('https://example.test/t/'.md5($label)), 'label' => $label];
    }

    private function text(string $pdf): string
    {
        if (class_exists(Parser::class)) {
            return preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
        }
        $f = tempnam(sys_get_temp_dir(), 'qrs').'.pdf';
        file_put_contents($f, $pdf);
        $out = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($f).' - 2>/dev/null');
        @unlink($f);
        if (trim($out) === '') {
            $this->markTestSkipped('No PDF text extractor (smalot/pdfparser or pdftotext) available.');
        }

        return preg_replace('/\s+/u', ' ', $out);
    }

    public function test_arabic_and_urdu_names_are_drawn_not_dropped(): void
    {
        $pdf = (new QrSheetPdfService)->sheet([$this->cell('طاولة 1'), $this->cell('میز 2'), $this->cell('Table 3')], 'مقهى — Table QR codes');

        $this->assertStringStartsWith('%PDF', $pdf);
        $text = $this->text($pdf);
        $this->assertStringContainsString('Table 3', $text);
        // TCPDF writes shaped (presentation-form) glyphs; the Arabic digit/letters survive as non-ASCII text.
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}\x{FB50}-\x{FEFF}]/u', $text);
        $this->assertStringContainsString('/Image', $pdf); // QR bitmaps embedded
    }

    public function test_more_than_a_page_of_cells_paginates(): void
    {
        $cells = array_map(fn ($i) => $this->cell('T'.$i), range(1, 14));
        $pdf = (new QrSheetPdfService)->sheet($cells, 'Cafe');

        $this->assertSame(2, preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf));
    }
}
