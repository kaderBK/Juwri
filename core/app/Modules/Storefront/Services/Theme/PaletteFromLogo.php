<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;


/**
 * Customizer v2 — WI-S4: propose a colour palette from a store's logo.
 *
 * GD only, no external API (RESEARCH.md §5). The logo is downscaled, its opaque
 * pixels quantised into a coarse histogram, near-white / near-black / greyish
 * buckets dropped, and the busiest remaining hues mapped onto the customizer's
 * colour ROLES. The result is only ever a PROPOSAL the owner applies — nothing
 * is saved here.
 */
class PaletteFromLogo
{
    /** Sample size — small enough to be cheap, big enough to catch a secondary hue. */
    private const SAMPLE = 24;

    /**
     * @param  string  $file  an absolute path to a readable image
     * @return array{primary:string, accent:string, background:string, text:string, surface:string}|null
     *         null when the file is unreadable or yields no usable colour
     */
    public static function propose(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $img = @self::load($file);
        if ($img === null) {
            return null;
        }

        $small = imagecreatetruecolor(self::SAMPLE, self::SAMPLE);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $img, 0, 0, 0, 0, self::SAMPLE, self::SAMPLE, imagesx($img), imagesy($img));
        imagedestroy($img);

        // Quantise to 3 bits per channel and count opaque, non-grey pixels.
        $buckets = [];
        for ($y = 0; $y < self::SAMPLE; $y++) {
            for ($x = 0; $x < self::SAMPLE; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 90) {
                    continue; // effectively transparent
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                [$h, $s, $l] = self::hsl($r, $g, $b);
                // Drop near-white, near-black and washed-out greys — a logo's
                // brand colour is the saturated part, not its paper or ink.
                if ($s < 0.18 || $l < 0.08 || $l > 0.94) {
                    continue;
                }
                $key = (($r >> 5) << 6) | (($g >> 5) << 3) | ($b >> 5);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0, 's' => $s, 'l' => $l];
                }
                $buckets[$key]['count']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }
        imagedestroy($small);

        if ($buckets === []) {
            return null;
        }

        // Average each bucket back to a representative colour.
        $colors = [];
        foreach ($buckets as $b) {
            $colors[] = [
                'hex'   => self::hex((int) ($b['r'] / $b['count']), (int) ($b['g'] / $b['count']), (int) ($b['b'] / $b['count'])),
                'count' => $b['count'],
                's'     => $b['s'],
                'l'     => $b['l'],
            ];
        }

        // Primary = the most saturated frequent colour (weight count × saturation);
        // accent = the next distinct hue.
        usort($colors, static fn ($a, $c) => ($c['count'] * $c['s']) <=> ($a['count'] * $a['s']));
        $primary = $colors[0]['hex'];
        $accent = $primary;
        foreach ($colors as $c) {
            if (self::hueDistance($c['hex'], $primary) > 0.12) {
                $accent = $c['hex'];
                break;
            }
        }

        // Background/text/surface are derived so the proposal is a coherent, legible
        // set rather than raw logo pixels — a light ground with dark ink, or the
        // reverse when the brand is light, tuned for contrast against the primary.
        $primaryLight = Tokens::relativeLuminance($primary) > 0.5;
        $background = $primaryLight ? '#101114' : '#ffffff';
        $surface    = $primaryLight ? '#1b1d22' : '#f5f6f7';
        $text       = $primaryLight ? '#f4f5f7' : '#14161c';

        return [
            'primary'    => $primary,
            'accent'     => $accent,
            'background' => $background,
            'surface'    => $surface,
            'text'       => $text,
        ];
    }

    private static function load(string $file): ?\GdImage
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }
        $img = @imagecreatefromstring($data);

        return $img instanceof \GdImage ? $img : null;
    }

    /** @return array{0:float,1:float,2:float} h,s,l each 0..1 */
    private static function hsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h / 6, $s, $l];
    }

    private static function hueDistance(string $a, string $b): float
    {
        [$ha] = self::hsl(...Tokens::rgb($a));
        [$hb] = self::hsl(...Tokens::rgb($b));
        $d = abs($ha - $hb);

        return min($d, 1 - $d);
    }

    private static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
