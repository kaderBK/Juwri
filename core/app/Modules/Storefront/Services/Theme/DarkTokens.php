<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

/**
 * The platform's DARK TOKEN SET — the colours a Liquid storefront paints when
 * the diner picks dark mode.
 *
 * The runtime already owned the CHOICE (the account sheet's "Dark mode" row
 * stamps [data-theme="dark"] on <html> and keeps it on the device), but the
 * COLOURS were left to each theme: every colour in the section library is a
 * `var(--tc-*, <light fallback>)`, so a theme that shipped no dark palette of
 * its own simply stayed light — the toggle did nothing visible.
 *
 * So the platform emits ONE dark block per storefront, scoped to
 * `:root[data-theme="dark"]`, overriding the colour tokens for the dark
 * ground. Every theme that consumes the tokens follows into the dark for
 * free, with no theme-side work at all.
 *
 * The palette is DERIVED from the theme's own colours rather than a fixed
 * grey: the ground keeps the theme's hue (a warm theme stays warm, a cool
 * one stays cool) and the brand colours are kept, lifted only when they
 * would disappear on a dark ground. The ramps invert with the ground — on a
 * near-black surface the "darker" stops have to become the LIGHTER ones or
 * a hover state would vanish.
 *
 * A theme that paints its OWN dark palette keeps it: it declares
 * `"dark_tokens": false` in its theme.json and the platform emits nothing
 * for it (see ShellStyles).
 */
class DarkTokens
{
    /** The neutral dark palette, used for a theme that declares no colours. */
    private const FALLBACK = [
        'background' => '#14161a',
        'surface' => '#1c1f24',
        'text' => '#f2f4f7',
        'primary' => '#3fd463',
        'accent' => '#ffb02e',
    ];

    /** The semantic colours the section library paints with (its light fallbacks). */
    private const SEMANTIC = [
        '--tc-danger' => '#f08a80',
        '--tc-danger-rgb' => '240, 138, 128',
        '--tc-success' => '#6ee7a0',
        '--tc-success-700' => '#86d6a2',
        '--tc-warning' => '#f2c14e',
        '--tc-warning-700' => '#e0a800',
        '--tc-overlay-rgb' => '0, 0, 0',
    ];

    /**
     * The dark block for a resolved palette.
     *
     * @param  array<string,mixed>  $resolved  the theme's EFFECTIVE colours, keyed
     *                                         by schema field id (colors.background …)
     */
    public static function css(array $resolved): string
    {
        $vars = self::vars(self::colors($resolved));

        $out = ':root[data-theme="dark"]{';
        foreach ($vars as $name => $value) {
            if (! preg_match('/^--(tc|tr|ts|tf)-[a-z0-9-]+$/', $name)) {
                continue;
            }
            if (! preg_match("/^[#a-z0-9,' -]+$/i", (string) $value)) {
                continue;
            }
            $out .= $name.':'.$value.';';
        }

        return $out.'}';
    }

    /**
     * The theme's own colours, with the neutral fallback for anything it does
     * not declare. A theme that declares no palette at all still gets a dark
     * mode — the platform's own.
     *
     * @param  array<string,mixed>  $resolved
     * @return array<string,string>
     */
    private static function colors(array $resolved): array
    {
        $out = [];
        foreach (array_keys(self::FALLBACK) as $role) {
            $hex = Tokens::normalizeHex($resolved['colors.'.$role] ?? null);
            $out[$role] = $hex ?? self::FALLBACK[$role];
        }

        return $out;
    }

    /**
     * The dark var map.
     *
     * @param  array<string,string>  $c  the theme's colours
     * @return array<string,string>
     */
    private static function vars(array $c): array
    {
        // The ground keeps the theme's hue: a warm theme stays warm, a cool
        // one stays cool — the dark mode is the same shop, at night.
        [$h, $s] = self::hueSat($c['background']);
        $background = self::hsl($h, min($s, 0.45), 0.07);
        $surface = self::hsl($h, min($s, 0.30), 0.11);
        $text = self::hsl($h, 0.12, 0.95);

        $primary = self::lift($c['primary'], 0.55);
        $accent = self::lift($c['accent'], 0.45);

        $vars = [
            '--tc-background' => $background,
            '--tc-surface' => $surface,
            '--tc-text' => $text,
            '--tc-text-muted' => Tokens::mix($text, $background, 0.45),
            '--tc-primary' => $primary,
            '--tc-accent' => $accent,
            '--tc-on-primary' => Tokens::readableInk($primary),
            '--tc-on-accent' => Tokens::readableInk($accent),
        ];

        // The ramps + the rgb triples the library tints with. On a dark ground
        // the "darker" stops become the LIGHTER ones — a hover state that
        // darkens a near-black button is invisible.
        foreach (['primary' => $primary, 'accent' => $accent, 'background' => $background, 'surface' => $surface, 'text' => $text] as $role => $hex) {
            [$r, $g, $b] = Tokens::rgb($hex);
            $vars['--tc-'.$role.'-rgb'] = "{$r}, {$g}, {$b}";
            $vars['--tc-'.$role.'-300'] = Tokens::mix($hex, '#ffffff', 0.24);
            $vars['--tc-'.$role.'-400'] = Tokens::mix($hex, '#ffffff', 0.12);
            $vars['--tc-'.$role.'-600'] = Tokens::mix($hex, '#ffffff', 0.10);
            $vars['--tc-'.$role.'-700'] = Tokens::mix($hex, '#ffffff', 0.20);
        }

        return $vars + self::SEMANTIC;
    }

    /**
     * A colour that would disappear on a dark ground is lifted toward white;
     * one that already reads is left exactly as the brand made it.
     */
    private static function lift(string $hex, float $amount): string
    {
        if (Tokens::relativeLuminance($hex) >= 0.18) {
            return $hex;
        }

        return Tokens::mix($hex, '#ffffff', $amount);
    }

    /** @return array{0:int,1:float} the hue (degrees) and saturation (0–1) of a colour */
    private static function hueSat(string $hex): array
    {
        [$r, $g, $b] = Tokens::rgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0, 0.0];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        switch ($max) {
            case $r:
                $h = fmod(($g - $b) / $d, 6);
                break;
            case $g:
                $h = ($b - $r) / $d + 2;
                break;
            default:
                $h = ($r - $g) / $d + 4;
        }
        $h *= 60;
        if ($h < 0) {
            $h += 360;
        }

        return [(int) round($h), (float) $s];
    }

    /** HSL → hex. */
    private static function hsl(int $h, float $s, float $l): string
    {
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        if ($h < 60) {
            [$r, $g, $b] = [$c, $x, 0.0];
        } elseif ($h < 120) {
            [$r, $g, $b] = [$x, $c, 0.0];
        } elseif ($h < 180) {
            [$r, $g, $b] = [0.0, $c, $x];
        } elseif ($h < 240) {
            [$r, $g, $b] = [0.0, $x, $c];
        } elseif ($h < 300) {
            [$r, $g, $b] = [$x, 0.0, $c];
        } else {
            [$r, $g, $b] = [$c, 0.0, $x];
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255)
        );
    }
}
