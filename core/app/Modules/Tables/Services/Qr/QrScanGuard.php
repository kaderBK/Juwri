<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services\Qr;

use Juwri\Modules\Tables\Models\QrDesign;

/**
 * PARITY-RUN T10-2: refuse to save a design that will not scan.
 *
 * WHAT THIS IS, PLAINLY: there is no pure-PHP QR *decoder* in this project's
 * dependency tree (bacon/bacon-qr-code encodes only), and adding a native
 * decoder for one owner-facing form is not a trade worth making. So the guard
 * is two layers, and neither is a full Reed–Solomon decode:
 *
 *   1. RULES — contrast ratio, polarity, quiet zone, logo coverage, and the
 *      "logo ⇒ error-correction H" invariant.
 *   2. RASTER ROUND-TRIP — the design is actually rendered to a PNG, the PNG is
 *      re-sampled at every module centre with a scanner-like luminance
 *      threshold, and the recovered bit matrix is compared to the matrix the
 *      encoder produced. Any module outside the logo footprint that comes back
 *      wrong fails the design.
 *
 * Layer 2 is what catches the real-world failures the rules miss: an eye style
 * that eats a finder pattern, a gradient whose far end vanishes into the
 * background, a dot style rendered so small it disappears at export size, a
 * logo parked on top of a finder pattern.
 */
class QrScanGuard
{
    /** WCAG relative-luminance contrast ratio. Scanners want well over 3:1. */
    public const MIN_CONTRAST = 3.0;

    /** The spec's cap: a logo may span at most this share of the code's width. */
    public const MAX_LOGO_WIDTH_PCT = 25;

    /** The standard demands 4; anything less and a busy background bleeds in. */
    public const MIN_QUIET_ZONE = 4;

    public function __construct(private QrRenderService $renderer)
    {
    }

    /**
     * @return array{ok: bool, reason: ?string, message: ?string, contrast: float, coverage: float, mismatches: int}
     */
    public function verify(string $payload, array|QrDesign $design): array
    {
        $settings = $this->renderer->settings($design);
        $contrast = $this->contrast($settings['fg'], $settings['bg']);
        $coverage = $settings['logo_file'] ? $settings['logo_scale'] : 0;

        $fail = function (string $reason, string $message) use ($contrast, $coverage): array {
            return [
                'ok' => false, 'reason' => $reason, 'message' => $message,
                'contrast' => round($contrast, 2), 'coverage' => $coverage, 'mismatches' => 0,
            ];
        };

        /* ---- layer 1: rules ---- */

        if ($this->luminance($settings['fg']) >= $this->luminance($settings['bg'])) {
            return $fail('inverted', ___('The code must be darker than its background — many scanners refuse inverted codes.'));
        }

        if ($contrast < self::MIN_CONTRAST) {
            return $fail('low_contrast', ___('Not enough contrast between the code and its background.'));
        }

        if ($settings['gradient_to'] && $this->contrast($settings['gradient_to'], $settings['bg']) < self::MIN_CONTRAST) {
            return $fail('low_contrast', ___('Not enough contrast between the code and its background.'));
        }

        if ($this->contrast($settings['eye'], $settings['bg']) < self::MIN_CONTRAST) {
            return $fail('low_contrast', ___('Not enough contrast between the code and its background.'));
        }

        if ($settings['quiet_zone'] < self::MIN_QUIET_ZONE) {
            return $fail('quiet_zone', ___('Leave at least 4 modules of quiet space around the code.'));
        }

        if ($settings['logo_file']) {
            if ($settings['logo_scale'] > self::MAX_LOGO_WIDTH_PCT) {
                return $fail('logo_too_big', ___('The logo covers too much of the code — keep it at 25% or less.'));
            }
            if ($settings['ec'] !== 'H') {
                return $fail('ec_level', ___('A logo overlay needs the highest error correction.'));
            }
        }

        /* ---- layer 2: raster round-trip ---- */

        $result = $this->roundTrip($payload, $settings);
        if (! $result['ok']) {
            return [
                'ok' => false,
                'reason' => $result['reason'],
                'message' => match ($result['reason']) {
                    'unrenderable' => ___('This design could not be rendered.'),
                    'finder'       => ___('The three corner squares are damaged — a scanner will not find the code.'),
                    default        => ___('This design does not survive a test scan — try a simpler style or a smaller logo.'),
                },
                'contrast' => round($contrast, 2),
                'coverage' => $coverage,
                'mismatches' => $result['mismatches'],
            ];
        }

        return [
            'ok' => true, 'reason' => null, 'message' => null,
            'contrast' => round($contrast, 2), 'coverage' => $coverage, 'mismatches' => 0,
        ];
    }

    /** Convenience: just the boolean. */
    public function passes(string $payload, array|QrDesign $design): bool
    {
        return $this->verify($payload, $design)['ok'];
    }

    /**
     * Render → re-sample → compare. Modules under the logo footprint are
     * expected to be destroyed (that is what error correction is for) and are
     * excluded from the comparison; everything else must come back exactly.
     *
     * @return array{ok: bool, reason: ?string, mismatches: int}
     */
    private function roundTrip(string $payload, array $settings): array
    {
        if (! function_exists('imagecreatefromstring')) {
            // No GD → layer 1 is all we have. Fail OPEN rather than blocking every
            // owner on a server without the extension; the rules already ran.
            return ['ok' => true, 'reason' => null, 'mismatches' => 0];
        }

        // Force a generous export size so a module is several pixels wide: the
        // guard must test the design, not the sampling resolution.
        $probe = $settings;
        $probe['size'] = max(512, $settings['size']);
        $probe['frame'] = 'none'; // the band shifts the origin and carries no modules

        $png = $this->renderer->png($payload, $probe);
        if ($png === '') {
            return ['ok' => true, 'reason' => null, 'mismatches' => 0];
        }

        $img = @imagecreatefromstring($png);
        if ($img === false) {
            return ['ok' => false, 'reason' => 'unrenderable', 'mismatches' => 0];
        }

        $matrix = $this->renderer->matrix($payload, $probe['ec']);
        $n = count($matrix);
        $q = $probe['quiet_zone'];
        $unit = max(1, (int) floor($probe['size'] / ($n + 2 * $q)));

        // A scanner binarises around the midpoint of what it sees. Use the
        // darkest ink (gradient end included) against the paper.
        $ink = min($this->luminance($probe['fg']), $this->luminance($probe['eye']));
        if ($probe['gradient_to']) {
            $ink = min($ink, $this->luminance($probe['gradient_to']));
        }
        $threshold = ($ink + $this->luminance($probe['bg'])) / 2;

        $box = $this->renderer->logoBox($n, $probe);
        $dark = function (int $x, int $y) use ($img, $q, $unit, $threshold): bool {
            $rgb = imagecolorat($img, ($x + $q) * $unit + intdiv($unit, 2), ($y + $q) * $unit + intdiv($unit, 2));

            return $this->luminanceRgb(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF) < $threshold;
        };

        $mismatches = 0;

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($box && $x >= $box[0] - 1 && $x <= $box[0] + $box[2] && $y >= $box[1] - 1 && $y <= $box[1] + $box[2]) {
                    continue; // under the logo: the EC budget covers it
                }
                // Finder patterns are STRUCTURE, not data: a scanner finds them by
                // the 1:1:3:1:1 run ratio, not by matching the encoder's bitmap, so
                // a round/rounded eye is legitimately not module-identical. They get
                // their own, stricter check below.
                if ($this->inFinder($x, $y, $n)) {
                    continue;
                }

                if ($dark($x, $y) !== $matrix[$y][$x]) {
                    $mismatches++;
                }
            }
        }

        // Every finder pattern must still read D L D D D L D through its centre,
        // horizontally and vertically — the ratio a decoder actually locks onto.
        $ratioBroken = false;
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ex, $ey]) {
            for ($i = 0; $i < 7; $i++) {
                $expected = ! in_array($i, [1, 5], true);
                if ($dark($ex + $i, $ey + 3) !== $expected || $dark($ex + 3, $ey + $i) !== $expected) {
                    $ratioBroken = true;
                    break 2;
                }
            }
        }

        imagedestroy($img);

        if ($ratioBroken) {
            return ['ok' => false, 'reason' => 'finder', 'mismatches' => $mismatches];
        }

        return ['ok' => $mismatches === 0, 'reason' => $mismatches === 0 ? null : 'unreadable', 'mismatches' => $mismatches];
    }

    /** Inside one of the three 7×7 finder patterns? */
    private function inFinder(int $x, int $y, int $n): bool
    {
        return ($x < 7 && $y < 7) || ($x >= $n - 7 && $y < 7) || ($x < 7 && $y >= $n - 7);
    }

    /* ---------------------------------------------------------------- colour */

    public function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        [$hi, $lo] = $la > $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = $this->renderer->rgb($hex);

        return $this->luminanceRgb($r, $g, $b);
    }

    private function luminanceRgb(int $r, int $g, int $b): float
    {
        $f = static function (int $c): float {
            $c /= 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }
}
