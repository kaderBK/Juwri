<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Pdf;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use TCPDF;

/**
 * The one place a PDF engine is configured (J70: TCPDF, LGPL-3.0, pure PHP, so it runs on shared hosting).
 *
 * TCPDF shapes Arabic and applies the Unicode bidi algorithm to every text run by itself; the document-level
 * direction only decides alignment and table column order. The bundled `dejavusans` family covers Latin, Arabic
 * (incl. presentation forms), Cyrillic and Greek. Currency amounts are printed as number + ISO code (SAR, AED, ...),
 * the same fallback the app uses on every non-web surface, so no currency sign glyph is needed.
 */
final class PdfDocument
{
    public const FONT = 'dejavusans';

    /**
     * @param  string|array{0: float|int, 1: float|int}  $format  'A4' or [width, height] in mm
     * @param  array{0: float|int, 1: float|int, 2: float|int, 3: float|int}  $margins  left, top, right, bottom (mm)
     */
    public static function make(string|array $format, array $margins, string $title, bool $rtl = false, bool $autoPageBreak = true): TCPDF
    {
        self::defineEngineConstants();
        self::defineCachePath();

        $pdf = new TCPDF('P', 'mm', $format, true, 'UTF-8', false);
        $pdf->SetCreator('Juwri');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($margins[0], $margins[1], $margins[2]);
        $pdf->SetAutoPageBreak($autoPageBreak, $margins[3]);
        $pdf->setCellPadding(0);
        $pdf->setFontSubsetting(true);
        $pdf->setRTL($rtl);
        $pdf->SetFont(self::FONT, '', 9);
        // Tables and images in the HTML renderer.
        $pdf->setImageScale(1.0);
        $pdf->setHtmlVSpace([]);

        return $pdf;
    }

    /**
     * Defines the TCPDF config constants. Must run before TCPDF's own autoconfig is loaded (it defines the same
     * constants with its defaults, and a define() after that is silently ignored). It is therefore also called when
     * composer loads app/Http/Helpers/CspHelper.php (before any test or request code runs), not only from
     * {@see make()}: anything that autoloads TCPDF first (a Pest arch() scan, a reflection pass) can no longer turn a
     * TCPDF error back into die().
     */
    public static function defineEngineConstants(): void
    {
        // Never let document text call TCPDF methods through <tcpdf> tags (user-controlled names reach the HTML).
        // The bundled config file would define it unguarded, so skip every external TCPDF config file (the package
        // defaults in tcpdf_autoconfig.php apply; a host's /etc/tcpdf_config.php must not turn this on).
        defined('K_TCPDF_EXTERNAL_CONFIG') || define('K_TCPDF_EXTERNAL_CONFIG', true);
        defined('K_TCPDF_CALLS_IN_HTML') || define('K_TCPDF_CALLS_IN_HTML', false);
        // A TCPDF error must throw (a 500 the app logs), never die() with a 200 or kill a queue worker.
        defined('K_TCPDF_THROW_EXCEPTION_ERROR') || define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
    }

    private static function defineCachePath(): void
    {
        // TCPDF reads its cache path from this constant; define it before the first instance so a read-only
        // system temp dir (shared hosting) is never used.
        if (! defined('K_PATH_CACHE')) {
            $cache = storage_path('app/tcpdf');
            if (! is_dir($cache)) {
                @mkdir($cache, 0775, true);
            }
            define('K_PATH_CACHE', rtrim($cache, '/').'/');
        }
    }

    /** Smallest module a phone camera reads reliably on a printed receipt or invoice, in mm. */
    public const QR_MIN_MODULE_MM = 0.4;

    /**
     * The side of the square (quiet zone included) a QR code for this payload needs: at least $minimum mm, and big
     * enough that every module is {@see QR_MIN_MODULE_MM}. A live 9-tag ZATCA code has about 89 modules.
     */
    public static function qrSize(string $payload, float $minimum): float
    {
        $modules = Encoder::encode($payload, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING)->getMatrix()->getWidth();

        return max($minimum, ($modules + 8) * self::QR_MIN_MODULE_MM);
    }

    /**
     * A QR code drawn onto the page as vector rectangles: no GD, Imagick or raster step, so a tax document can never
     * lose its QR on a host without GD. The modules come from BaconQrCode at error correction M (the encoder the rest
     * of the app uses), not from TCPDF's own QR encoder: that one picks its data mask at random and splits the text
     * into numeric / alphanumeric / byte segments, so its output cannot be checked against the payload. Coordinates are
     * page-absolute mm from the top-left corner; $size is the whole square including a 4-module quiet zone on every
     * side (see {@see qrSize()}). The square is painted white first, so the code stays readable on coloured paper or
     * in a dark-mode viewer, and each horizontal run of dark modules is one rectangle (no hairline seams between
     * neighbours).
     */
    public static function drawQr(TCPDF $pdf, string $payload, float $x, float $y, float $size): void
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING)->getMatrix();
        $modules = $matrix->getWidth();
        $cell = $size / ($modules + 8);

        $rtl = $pdf->getRTL();
        $pdf->setRTL(false);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $size, $size, 'F');
        $pdf->SetFillColor(0, 0, 0);
        for ($row = 0; $row < $modules; $row++) {
            $col = 0;
            while ($col < $modules) {
                if ($matrix->get($col, $row) !== 1) {
                    $col++;

                    continue;
                }
                $start = $col;
                while ($col < $modules && $matrix->get($col, $row) === 1) {
                    $col++;
                }
                $pdf->Rect($x + ($start + 4) * $cell, $y + ($row + 4) * $cell, ($col - $start) * $cell, $cell, 'F');
            }
        }
        $pdf->setRTL($rtl);
    }

    /** True when the text holds Arabic / Hebrew / Syriac / Thaana-script letters. */
    public static function isRtlText(string $text): bool
    {
        return (bool) preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FEFF}]/u', $text);
    }
}
