<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services\Qr;

use Juwri\Modules\Tables\Contracts\QrImages;
use Juwri\Modules\Tables\Models\QrDesign;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\Cache;

/**
 * PARITY-RUN T10-2: the ONE server-side QR renderer.
 *
 * Every QR the platform emits — store, table, branch, hotel room, kiosk,
 * signage, fiscal receipt, and whatever T2's board wants later — comes through
 * here. `bacon/bacon-qr-code` (already a dependency) is used only for the
 * ENCODER; the module matrix is then painted by this class, because the
 * library's SvgImageBackEnd cannot do dot/eye styles, gradients or a logo
 * overlay, and has no GD backend at all for PNG.
 *
 * Contract:
 *   - `svg()` is canonical. Module coordinates are in MODULE units inside a
 *     viewBox, so the same markup is crisp at any size and byte-identical for
 *     identical input (the determinism test pins that).
 *   - `png()` rasters the same geometry through GD for downloads, ZIPs and the
 *     A4 sheet.
 *   - Renders are cached per (payload, settings fingerprint, format) — a table
 *     list re-renders the same 40 codes on every page view otherwise.
 *
 * Callers pass a URL built by `store_public_qr_url()`. This class never
 * assembles a URL itself.
 */
class QrRenderService implements QrImages
{
    /** How long a rendered asset stays cached. Designs change rarely; payloads never. */
    private const CACHE_TTL = 3600;

    /** Finder patterns are 7×7 modules at three corners. */
    private const EYE = 7;

    /* ---------------------------------------------------------------- public */

    /**
     * Canonical SVG.
     *
     * @param  string  $payload   the URL (or fiscal TLV) to encode
     * @param  array|QrDesign|null  $design  settings array, model, or null for plain
     */
    public function svg(string $payload, array|QrDesign|null $design = null, array $overrides = []): string
    {
        $settings = $this->settings($design, $overrides);

        return $this->cached($payload, $settings, 'svg', fn () => $this->buildSvg($payload, $settings));
    }

    /** PNG raster of the same geometry. Empty string when GD is unavailable. */
    public function png(string $payload, array|QrDesign|null $design = null, array $overrides = []): string
    {
        $settings = $this->settings($design, $overrides);

        return $this->cached($payload, $settings, 'png', fn () => $this->buildPng($payload, $settings));
    }

    /**
     * The bare module matrix (true = dark). Exposed so QrScanGuard can compare a
     * rendered raster against the code it was supposed to carry.
     *
     * @return array<int, array<int, bool>>
     */
    public function matrix(string $payload, string $ec = 'M'): array
    {
        // BaconQrCode's Writer used to reject this; we call the Encoder directly,
        // which happily encodes nothing into a "valid" empty code. Keep the old
        // contract — qr_svg('') returns '' rather than an unscannable blank.
        if ($payload === '') {
            throw new \InvalidArgumentException('Found empty contents');
        }

        $qr = Encoder::encode($payload, $this->ecLevel($ec), Encoder::DEFAULT_BYTE_MODE_ECODING);
        $bytes = $qr->getMatrix();

        $out = [];
        for ($y = 0; $y < $bytes->getHeight(); $y++) {
            $row = [];
            for ($x = 0; $x < $bytes->getWidth(); $x++) {
                $row[] = $bytes->get($x, $y) === 1;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Normalize whatever the caller had into the renderer contract. */
    public function settings(array|QrDesign|null $design, array $overrides = []): array
    {
        if ($design instanceof QrDesign) {
            $design = $design->settings();
        }
        if (! is_array($design)) {
            $design = QrDesign::plain();
        }

        return $overrides ? QrDesign::normalize($overrides + $design) : QrDesign::normalize($design);
    }

    /** Stable fingerprint of a settings array — the cache key and the ETag. */
    public function fingerprint(array $settings): string
    {
        ksort($settings);

        return substr(sha1(json_encode($settings)), 0, 16);
    }

    /* --------------------------------------------------------------- geometry */

    private function ecLevel(string $ec): ErrorCorrectionLevel
    {
        return match (strtoupper($ec)) {
            'L' => ErrorCorrectionLevel::L(),
            'Q' => ErrorCorrectionLevel::Q(),
            'H' => ErrorCorrectionLevel::H(),
            default => ErrorCorrectionLevel::M(),
        };
    }

    /** Is (x,y) inside one of the three finder patterns? */
    private function inEye(int $x, int $y, int $n): bool
    {
        return ($x < self::EYE && $y < self::EYE)
            || ($x >= $n - self::EYE && $y < self::EYE)
            || ($x < self::EYE && $y >= $n - self::EYE);
    }

    /**
     * Logo footprint in module units: [x0, y0, side] centred on the code.
     * Returns null when there is no logo.
     */
    public function logoBox(int $n, array $settings): ?array
    {
        if (! $settings['logo_file']) {
            return null;
        }

        $side = max(1, (int) round($n * $settings['logo_scale'] / 100));
        $x0   = (int) floor(($n - $side) / 2);

        return [$x0, $x0, $side];
    }

    /* -------------------------------------------------------------------- SVG */

    private function buildSvg(string $payload, array $settings): string
    {
        $m = $this->matrix($payload, $settings['ec']);
        $n = count($m);
        $q = $settings['quiet_zone'];
        $side = $n + 2 * $q;                          // module units across
        $band = $settings['frame'] === 'none' ? 0 : 6; // frame band height, modules
        $height = $side + $band;
        $top = $settings['frame'] === 'top' ? $band : 0;

        $px = $settings['size'];
        $pxH = (int) round($px * $height / $side);

        $fill = $settings['gradient_to'] ? 'url(#qrg)' : $settings['fg'];
        $eyeFill = $settings['eye'];

        $out = [];
        $out[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" shape-rendering="crispEdges">',
            $px,
            $pxH,
            $side,
            $height
        );

        if ($settings['gradient_to']) {
            $out[] = $settings['gradient_type'] === 'radial'
                ? sprintf(
                    '<defs><radialGradient id="qrg" cx="50%%" cy="50%%" r="75%%"><stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></radialGradient></defs>',
                    $this->esc($settings['fg']),
                    $this->esc($settings['gradient_to'])
                )
                : sprintf(
                    '<defs><linearGradient id="qrg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient></defs>',
                    $this->esc($settings['fg']),
                    $this->esc($settings['gradient_to'])
                );
        }

        $out[] = sprintf('<rect width="%d" height="%d" fill="%s"/>', $side, $height, $this->esc($settings['bg']));

        // Data modules (finder patterns are drawn separately below).
        $paths = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (! $m[$y][$x] || $this->inEye($x, $y, $n)) {
                    continue;
                }
                $paths[] = $this->svgModule($x + $q, $y + $q + $top, $settings['dot_style']);
            }
        }
        $out[] = sprintf('<g fill="%s">%s</g>', $this->esc($fill), implode('', $paths));

        // Finder patterns.
        $eyes = [[0, 0], [$n - self::EYE, 0], [0, $n - self::EYE]];
        $eyeParts = [];
        foreach ($eyes as [$ex, $ey]) {
            $eyeParts[] = $this->svgEye($ex + $q, $ey + $q + $top, $settings['eye_style'], $settings['bg']);
        }
        $out[] = sprintf('<g fill="%s">%s</g>', $this->esc($eyeFill), implode('', $eyeParts));

        // Logo overlay — padded so the modules under it read as one clean block.
        if ($box = $this->logoBox($n, $settings)) {
            [$lx, $ly, $lside] = $box;
            $pad = 0.6;
            $out[] = sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="%s"/>',
                $this->num($lx + $q - $pad),
                $this->num($ly + $q + $top - $pad),
                $this->num($lside + 2 * $pad),
                $this->num($lside + 2 * $pad),
                $this->num(($lside + 2 * $pad) * 0.16),
                $this->esc($settings['bg'])
            );
            if ($data = $this->logoDataUri($settings['logo_file'])) {
                $out[] = sprintf(
                    '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" href="%s"/>',
                    $this->num($lx + $q),
                    $this->num($ly + $q + $top),
                    $this->num($lside),
                    $this->num($lside),
                    $this->esc($data)
                );
            }
        }

        // Frame band + label.
        if ($band) {
            $by = $settings['frame'] === 'top' ? 0 : $side;
            $out[] = sprintf(
                '<rect x="0" y="%s" width="%d" height="%d" fill="%s"/>',
                $this->num($by),
                $side,
                $band,
                $this->esc($settings['fg'])
            );
            if ($settings['frame_text']) {
                $out[] = sprintf(
                    '<text x="%s" y="%s" text-anchor="middle" dominant-baseline="central" fill="%s" '
                    . 'font-family="Helvetica, Arial, sans-serif" font-size="%s" font-weight="600">%s</text>',
                    $this->num($side / 2),
                    $this->num($by + $band / 2),
                    $this->esc($settings['bg']),
                    $this->num($band * 0.5),
                    $this->esc($settings['frame_text'])
                );
            }
        }

        $out[] = '</svg>';

        return implode('', $out);
    }

    private function svgModule(float $x, float $y, string $style): string
    {
        return match ($style) {
            'dots'    => sprintf('<circle cx="%s" cy="%s" r="0.46"/>', $this->num($x + 0.5), $this->num($y + 0.5)),
            'rounded' => sprintf('<rect x="%s" y="%s" width="1" height="1" rx="0.32"/>', $this->num($x), $this->num($y)),
            default   => sprintf('<rect x="%s" y="%s" width="1" height="1"/>', $this->num($x), $this->num($y)),
        };
    }

    /**
     * A finder pattern: 7×7 ring + 3×3 centre. Drawn as two shapes with the
     * background punched between them, so the ring reads correctly whatever the
     * eye style is.
     */
    private function svgEye(float $x, float $y, string $style, string $bg): string
    {
        if ($style === 'circle') {
            $cx = $this->num($x + 3.5);
            $cy = $this->num($y + 3.5);

            return sprintf('<circle cx="%s" cy="%s" r="3.5"/>', $cx, $cy)
                . sprintf('<circle cx="%s" cy="%s" r="2.5" fill="%s"/>', $cx, $cy, $this->esc($bg))
                . sprintf('<circle cx="%s" cy="%s" r="1.5"/>', $cx, $cy);
        }

        $rx = $style === 'rounded' ? 1.8 : 0;
        $rxInner = $style === 'rounded' ? 1.2 : 0;
        $rxCore = $style === 'rounded' ? 0.7 : 0;

        return sprintf('<rect x="%s" y="%s" width="7" height="7" rx="%s"/>', $this->num($x), $this->num($y), $this->num($rx))
            . sprintf(
                '<rect x="%s" y="%s" width="5" height="5" rx="%s" fill="%s"/>',
                $this->num($x + 1),
                $this->num($y + 1),
                $this->num($rxInner),
                $this->esc($bg)
            )
            . sprintf('<rect x="%s" y="%s" width="3" height="3" rx="%s"/>', $this->num($x + 2), $this->num($y + 2), $this->num($rxCore));
    }

    /* -------------------------------------------------------------------- PNG */

    private function buildPng(string $payload, array $settings): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }

        $m = $this->matrix($payload, $settings['ec']);
        $n = count($m);
        $q = $settings['quiet_zone'];
        $sideMods = $n + 2 * $q;
        $bandMods = $settings['frame'] === 'none' ? 0 : 6;

        // Snap the module size to whole pixels: fractional modules alias into
        // half-dark rows that a scanner reads as noise.
        $unit = max(1, (int) floor($settings['size'] / $sideMods));
        $w = $unit * $sideMods;
        $band = $unit * $bandMods;
        $h = $w + $band;
        $top = $settings['frame'] === 'top' ? $band : 0;

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        [$br, $bg_, $bb] = $this->rgb($settings['bg']);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, $br, $bg_, $bb));

        [$fr, $fg_, $fb] = $this->rgb($settings['fg']);
        $grad = $settings['gradient_to'] ? $this->rgb($settings['gradient_to']) : null;
        [$er, $eg, $eb] = $this->rgb($settings['eye']);
        $eyeColor = imagecolorallocate($img, $er, $eg, $eb);

        // Data modules.
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (! $m[$y][$x] || $this->inEye($x, $y, $n)) {
                    continue;
                }

                if ($grad) {
                    $t = $settings['gradient_type'] === 'radial'
                        ? min(1.0, sqrt((($x - $n / 2) ** 2) + (($y - $n / 2) ** 2)) / ($n * 0.75))
                        : (($x + $y) / max(1, 2 * ($n - 1)));
                    $color = imagecolorallocate(
                        $img,
                        (int) round($fr + ($grad[0] - $fr) * $t),
                        (int) round($fg_ + ($grad[1] - $fg_) * $t),
                        (int) round($fb + ($grad[2] - $fb) * $t)
                    );
                } else {
                    $color = imagecolorallocate($img, $fr, $fg_, $fb);
                }

                $px = ($x + $q) * $unit;
                $py = ($y + $q) * $unit + $top;
                $this->gdModule($img, $px, $py, $unit, $settings['dot_style'], $color);
            }
        }

        // Finder patterns.
        $bgColor = imagecolorallocate($img, $br, $bg_, $bb);
        foreach ([[0, 0], [$n - self::EYE, 0], [0, $n - self::EYE]] as [$ex, $ey]) {
            $this->gdEye(
                $img,
                ($ex + $q) * $unit,
                ($ey + $q) * $unit + $top,
                $unit,
                $settings['eye_style'],
                $eyeColor,
                $bgColor
            );
        }

        // Logo overlay.
        if ($box = $this->logoBox($n, $settings)) {
            [$lx, $ly, $lside] = $box;
            $x0 = ($lx + $q) * $unit;
            $y0 = ($ly + $q) * $unit + $top;
            $size = $lside * $unit;
            $pad = (int) round($unit * 0.6);
            imagefilledrectangle($img, $x0 - $pad, $y0 - $pad, $x0 + $size + $pad, $y0 + $size + $pad, $bgColor);

            if ($bytes = $this->logoBytes($settings['logo_file'])) {
                $logo = @imagecreatefromstring($bytes);
                if ($logo !== false) {
                    imagecopyresampled($img, $logo, $x0, $y0, 0, 0, $size, $size, imagesx($logo), imagesy($logo));
                    imagedestroy($logo);
                }
            }
        }

        // Frame band. GD ships no scalable font, so the label is drawn with the
        // built-in bitmap font upscaled — ASCII only. SVG and the A4 sheet render
        // the label properly; see the track's deferral note.
        if ($band) {
            $by = $settings['frame'] === 'top' ? 0 : $w;
            imagefilledrectangle($img, 0, $by, $w, $by + $band, imagecolorallocate($img, $fr, $fg_, $fb));
            if ($settings['frame_text'] && preg_match('/^[\x20-\x7e]+$/', $settings['frame_text'])) {
                $this->gdBandText($img, $settings['frame_text'], $w, $by, $band, $settings['bg'], $settings['fg']);
            }
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    private function gdModule($img, int $x, int $y, int $unit, string $style, int $color): void
    {
        if ($style === 'dots') {
            imagefilledellipse($img, $x + intdiv($unit, 2), $y + intdiv($unit, 2), max(1, $unit - 1), max(1, $unit - 1), $color);

            return;
        }

        // 'rounded' degrades to a square in the raster: at typical export sizes a
        // module is 4–12 px and a radius there is invisible noise, not a style.
        imagefilledrectangle($img, $x, $y, $x + $unit - 1, $y + $unit - 1, $color);
    }

    private function gdEye($img, int $x, int $y, int $unit, string $style, int $color, int $bg): void
    {
        $s = 7 * $unit;
        if ($style === 'circle') {
            $cx = $x + intdiv($s, 2);
            $cy = $y + intdiv($s, 2);
            imagefilledellipse($img, $cx, $cy, $s, $s, $color);
            imagefilledellipse($img, $cx, $cy, 5 * $unit, 5 * $unit, $bg);
            imagefilledellipse($img, $cx, $cy, 3 * $unit, 3 * $unit, $color);

            return;
        }

        imagefilledrectangle($img, $x, $y, $x + $s - 1, $y + $s - 1, $color);
        imagefilledrectangle($img, $x + $unit, $y + $unit, $x + 6 * $unit - 1, $y + 6 * $unit - 1, $bg);
        imagefilledrectangle($img, $x + 2 * $unit, $y + 2 * $unit, $x + 5 * $unit - 1, $y + 5 * $unit - 1, $color);
    }

    private function gdBandText($img, string $text, int $w, int $by, int $band, string $fg, string $bandBg): void
    {
        $font = 5;
        $tw = imagefontwidth($font) * strlen($text);
        $th = imagefontheight($font);
        $strip = imagecreatetruecolor($tw, $th);
        [$r1, $g1, $b1] = $this->rgb($bandBg);
        imagefilledrectangle($strip, 0, 0, $tw, $th, imagecolorallocate($strip, $r1, $g1, $b1));
        [$r2, $g2, $b2] = $this->rgb($fg);
        imagestring($strip, $font, 0, 0, $text, imagecolorallocate($strip, $r2, $g2, $b2));

        $scale = max(1.0, min($band * 0.55 / $th, $w * 0.85 / max(1, $tw)));
        $dw = (int) round($tw * $scale);
        $dh = (int) round($th * $scale);
        imagecopyresampled($img, $strip, intdiv($w - $dw, 2), $by + intdiv($band - $dh, 2), 0, 0, $dw, $dh, $tw, $th);
        imagedestroy($strip);
    }

    /* ------------------------------------------------------------------ logo */

    /** Absolute path of a media-library file, when it lives on the local disk. */
    public function logoPath(?string $filename): ?string
    {
        if (! $filename) {
            return null;
        }

        $path = public_path('storage/menu/' . basename($filename));

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function logoBytes(?string $filename): ?string
    {
        $path = $this->logoPath($filename);
        if ($path) {
            return (string) @file_get_contents($path);
        }

        // S3/BYOK installs keep the library off the local disk. Best effort — a
        // logo that cannot be read renders as the padded blank block, which still
        // scans, rather than breaking the whole render.
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $rel = 'menu/' . basename((string) $filename);

            return $disk->exists($rel) ? (string) $disk->get($rel) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function logoDataUri(?string $filename): ?string
    {
        $bytes = $this->logoBytes($filename);
        if (! $bytes) {
            return null;
        }

        $mime = 'image/png';
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bytes);
            if ($info && ! empty($info['mime'])) {
                $mime = $info['mime'];
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /* ----------------------------------------------------------------- utils */

    /** @return array{0:int,1:int,2:int} */
    public function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Short, locale-independent number formatting (never "0,5" under a comma locale). */
    private function num(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function cached(string $payload, array $settings, string $format, \Closure $build): string
    {
        $key = 'qr:' . $format . ':' . $this->fingerprint($settings) . ':' . sha1($payload);

        try {
            return Cache::remember($key, self::CACHE_TTL, $build);
        } catch (\Throwable $e) {
            return $build();
        }
    }
}
