<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Seeders\Concerns;

/**
 * Best-effort SVG → PNG rasterization for social-share (og:image) assets.
 * The storefront listing screenshots are generated as SVG (crisp, tiny), but
 * social crawlers cannot render SVG — so we also emit a PNG raster of the hero.
 *
 * Portable tool chain, first available wins: librsvg (rsvg-convert, common on
 * Linux/CI), then the Imagick extension, then the macOS Quick Look + sips pair
 * (dev machines). If none are present the method returns false and the caller
 * simply ships without a PNG — the views fall back to emitting no og:image for
 * that listing rather than a broken SVG one, so this degrades cleanly.
 */
trait RasterizesSvg
{
    protected function rasterizeToPng(string $svgPath, string $pngPath, int $w = 1200, int $h = 750): bool
    {
        if (! is_file($svgPath)) {
            return false;
        }

        // 1) librsvg — exact width/height, no padding.
        if ($this->binaryExists('rsvg-convert')) {
            @exec('rsvg-convert -w ' . $w . ' -h ' . $h . ' ' . escapeshellarg($svgPath)
                . ' -o ' . escapeshellarg($pngPath) . ' 2>/dev/null', $o, $code);
            if ($code === 0 && is_file($pngPath)) {
                return true;
            }
        }

        // 2) Imagick — set background transparent, resize to fit.
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->setBackgroundColor(new \ImagickPixel('transparent'));
                $im->readImage($svgPath);
                $im->setImageFormat('png32');
                $im->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1, true);
                $im->writeImage($pngPath);
                $im->clear();

                return is_file($pngPath);
            } catch (\Throwable $e) {
                // fall through
            }
        }

        // 3) macOS Quick Look thumbnail (square, padded) + sips centre-crop.
        if ($this->binaryExists('qlmanage') && $this->binaryExists('sips')) {
            $dir = dirname($pngPath);
            @exec('qlmanage -t -s ' . max($w, $h) . ' -o ' . escapeshellarg($dir) . ' '
                . escapeshellarg($svgPath) . ' 2>/dev/null');
            $thumb = $dir . '/' . basename($svgPath) . '.png';
            if (is_file($thumb)) {
                @rename($thumb, $pngPath);
                @exec('sips -c ' . $h . ' ' . $w . ' ' . escapeshellarg($pngPath) . ' 2>/dev/null');

                return is_file($pngPath);
            }
        }

        return false;
    }

    private function binaryExists(string $bin): bool
    {
        @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);

        return $code === 0 && ! empty($out);
    }
}
