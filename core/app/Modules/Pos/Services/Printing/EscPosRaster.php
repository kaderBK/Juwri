<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;


/**
 * PARITY-RUN T1-2: GD image → ESC/POS `GS v 0` raster bit-image bytes.
 *
 * Used for two things the printer's own character generator cannot do:
 *   • Arabic/RTL lines (shaped by {@see ArabicShaper}, drawn with a TTF the
 *     owner configures — no Arabic-capable font ships with this repo);
 *   • the store logo at the top of a receipt.
 *
 * Everything degrades instead of failing: no GD extension, no font, or an
 * unreadable image all return null and the caller falls back to text mode. A
 * receipt that prints in plain characters beats a receipt that does not print.
 */
class EscPosRaster
{
    private const GS = "\x1D";

    /** Is raster output possible at all in this PHP? */
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * Encode a GD image as `GS v 0` (normal density). Pixels darker than the
     * midpoint become dots. Width is padded to a byte boundary; the printer
     * ignores the padding because it is white.
     *
     * @param \GdImage $image
     */
    public static function encode($image): ?string
    {
        if (! self::available() || ! $image) {
            return null;
        }

        $w = (int) imagesx($image);
        $h = (int) imagesy($image);
        if ($w < 1 || $h < 1) {
            return null;
        }

        $bytesPerRow = (int) ceil($w / 8);
        $data = '';

        for ($y = 0; $y < $h; $y++) {
            $row = array_fill(0, $bytesPerRow, 0);
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // Rec. 601 luma; anything below mid-grey is ink.
                if ((0.299 * $r + 0.587 * $g + 0.114 * $b) < 128) {
                    $row[intdiv($x, 8)] |= (0x80 >> ($x % 8));
                }
            }
            foreach ($row as $byte) {
                $data .= chr($byte);
            }
        }

        return self::GS . 'v0' . chr(0)
            . chr($bytesPerRow & 0xFF) . chr(($bytesPerRow >> 8) & 0xFF)
            . chr($h & 0xFF) . chr(($h >> 8) & 0xFF)
            . $data;
    }

    /**
     * Render one line of text to raster bytes at the given dot width.
     * Uses the configured TTF when present (required for Arabic glyphs), else
     * GD's built-in Latin bitmap font.
     *
     * @param bool $rtl right-align the line (the visual order is already handled
     *                  by ArabicShaper; this only decides which margin it hugs)
     */
    public static function textLine(string $text, int $dotWidth, ?string $fontPath = null, int $fontSize = 22, bool $rtl = false, bool $center = false): ?string
    {
        if (! self::available() || $text === '') {
            return null;
        }

        $useTtf = $fontPath && is_file($fontPath) && function_exists('imagettfbbox');

        if ($useTtf) {
            $box = @imagettfbbox($fontSize, 0, $fontPath, $text);
            if (! $box) {
                return null;
            }
            $textWidth = (int) abs($box[2] - $box[0]);
            $height = (int) max(1, abs($box[7] - $box[1])) + 10;
            $baseline = $height - 8;
        } else {
            $font = 5; // GD built-in, 9×15
            $textWidth = (int) (imagefontwidth($font) * mb_strlen($text));
            $height = (int) imagefontheight($font) + 4;
            $baseline = 2;
        }

        $img = imagecreatetruecolor(max(1, $dotWidth), max(1, $height));
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $dotWidth, $height, $white);

        $x = 0;
        if ($center) {
            $x = (int) max(0, ($dotWidth - $textWidth) / 2);
        } elseif ($rtl) {
            $x = (int) max(0, $dotWidth - $textWidth);
        }

        if ($useTtf) {
            @imagettftext($img, $fontSize, 0, $x, $baseline, $black, $fontPath, $text);
        } else {
            imagestring($img, 5, $x, $baseline, $text, $black);
        }

        $bytes = self::encode($img);
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Rasterise a store logo from an image file, scaled to fit the paper width.
     * Returns null for anything GD cannot open — a missing logo never breaks a receipt.
     */
    public static function logo(string $path, int $dotWidth, int $maxHeight = 240): ?string
    {
        if (! self::available() || ! is_file($path)) {
            return null;
        }

        $src = @imagecreatefromstring((string) @file_get_contents($path));
        if (! $src) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($dotWidth / max(1, $sw), $maxHeight / max(1, $sh), 1.0);
        $tw = max(1, (int) floor($sw * $scale));
        $th = max(1, (int) floor($sh * $scale));

        // Centre on a white canvas the full paper width so the printer does not
        // left-align a narrow logo.
        $canvas = imagecreatetruecolor($dotWidth, $th);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dotWidth, $th, $white);
        imagecopyresampled($canvas, $src, (int) max(0, ($dotWidth - $tw) / 2), 0, 0, 0, $tw, $th, $sw, $sh);

        $bytes = self::encode($canvas);
        imagedestroy($src);
        imagedestroy($canvas);

        return $bytes;
    }
}
