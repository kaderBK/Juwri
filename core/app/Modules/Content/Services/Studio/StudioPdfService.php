<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

/**
 * Wrap a raster design image into a single-page PDF for a clean "Download PDF".
 *
 * Dependency-free: the image is flattened onto white and re-encoded to JPEG via
 * GD, then embedded as a DCTDecode image XObject in a hand-built PDF. No PDF
 * library is required. The page is sized to the image so the chosen aspect
 * (a4 / letter / square / story — already baked into the generated image) is
 * preserved exactly.
 */
class StudioPdfService
{
    /** Convert PNG/JPEG image bytes into a one-page PDF (page sized to the image). */
    public function imageToPdf(string $imageBytes): string
    {
        $img = @imagecreatefromstring($imageBytes);
        if ($img === false) {
            throw new \RuntimeException('Unreadable design image.');
        }
        $w = imagesx($img);
        $h = imagesy($img);

        // Flatten any alpha onto white so transparent areas don't blacken in JPEG.
        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        return $this->buildPdf($jpeg, $w, $h);
    }

    /** Assemble a minimal single-image PDF with the JPEG drawn full-page. */
    private function buildPdf(string $jpeg, int $w, int $h): string
    {
        $content = "q {$w} 0 0 {$h} 0 0 cm /Im0 Do Q";

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] "
                . '/Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>',
            4 => "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream",
            5 => "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} "
                . '/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode '
                . "/Length " . strlen($jpeg) . " >>\nstream\n{$jpeg}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $size = count($objects) + 1; // +1 for the free object 0
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}
