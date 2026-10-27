<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Juwri\Modules\Storefront\Services\Theme\Tokens;

/**
 * Theme Studio phase D steps 4 + 5: design tokens and the font pair.
 *
 * Palette — the proposed colour roles are completed from a seed (the recipe,
 * over which the logo palette and the owner's reference colours lie), then
 * AUTO-FIXED to WCAG AA with ContrastGuard: every failing pair's foreground
 * role is moved to the guard's nearest passing shade (a lightness shift of
 * the same hue); when even black/white cannot pass on that background the
 * background itself is reset to white/near-black. A palette that still fails
 * after that is replaced by the seed — never shipped.
 *
 * Fonts — only FontPairs ids; when a menu language is Arabic only pairs with
 * an Arabic face qualify.
 */
class Palette
{
    public const ROLES = ['primary', 'background', 'surface', 'text', 'accent'];

    public const SAFE = [
        'colors.primary' => '#1f5f8b',
        'colors.background' => '#ffffff',
        'colors.surface' => '#f5f6f7',
        'colors.text' => '#16181d',
        'colors.accent' => '#8a4b00',
        'layout.radius' => 'soft',
        'layout.density' => 'cozy',
    ];

    /**
     * @param  array<string,mixed>  $proposed  model output (may be partial / invalid)
     * @param  array<string,string>  $seed  a complete, known-good token set
     * @return array<string,string> colors.* + layout.radius + layout.density, AA-clean
     */
    public static function tokens(array $proposed, array $seed, string $template): array
    {
        $seed = self::complete($seed, self::SAFE);
        $tokens = self::complete($proposed, $seed);

        $fixed = self::fixContrast($tokens, $template);
        if ($fixed !== null) {
            return $fixed;
        }
        $fixed = self::fixContrast($seed, $template);

        return $fixed ?? self::SAFE;
    }

    /**
     * The seed palette: recipe tokens, then the logo palette, then the
     * owner's reference colours (primary, accent, background in that order).
     *
     * @param  array<string,string>  $recipeTokens
     * @param  array<string,string>|null  $logo  PaletteFromLogo::propose()
     * @param  list<string>  $ownerColors
     * @return array<string,string>
     */
    public static function seed(array $recipeTokens, ?array $logo, array $ownerColors): array
    {
        $seed = self::complete($recipeTokens, self::SAFE);
        if (is_array($logo)) {
            foreach (self::ROLES as $role) {
                if (($hex = Tokens::normalizeHex($logo[$role] ?? null)) !== null) {
                    $seed['colors.'.$role] = $hex;
                }
            }
        }
        foreach ($ownerColors as $i => $color) {
            $role = ['colors.primary', 'colors.accent', 'colors.background'][$i] ?? null;
            if ($role !== null && ($hex = Tokens::normalizeHex($color)) !== null) {
                $seed[$role] = $hex;
            }
        }

        return $seed;
    }

    /**
     * Is every checked pair AA?
     *
     * @param  array<string,mixed>  $tokens
     */
    public static function passes(array $tokens, string $template): bool
    {
        return ContrastGuard::warnings($template, $tokens) === [];
    }

    /** The font pair: the proposed one when allowed, else the fallback, else the first allowed. */
    public static function fontPair(mixed $proposed, ?string $fallback, bool $arabic): string
    {
        $allowed = self::allowedFonts($arabic);
        foreach ([$proposed, $fallback] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return $allowed[0] ?? FontPairs::noneValue();
    }

    /** @return list<string> */
    public static function allowedFonts(bool $arabic): array
    {
        $out = [];
        foreach (FontPairs::all() as $id => $pair) {
            if (! $arabic || self::hasArabicFace($pair)) {
                $out[] = (string) $id;
            }
        }

        return $out;
    }

    /** @param  array<string,mixed>  $pair */
    public static function hasArabicFace(array $pair): bool
    {
        return array_any((array) ($pair['faces'] ?? []), fn($face) => is_array($face) && ($face['script'] ?? null) === 'arabic');
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  array<string,string>  $fallback
     * @return array<string,string>
     */
    protected static function complete(array $values, array $fallback): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            $key = 'colors.'.$role;
            $out[$key] = Tokens::normalizeHex($values[$key] ?? null) ?? Tokens::normalizeHex($fallback[$key] ?? null) ?? self::SAFE[$key];
        }
        $radius = $values['layout.radius'] ?? null;
        $out['layout.radius'] = in_array($radius, Tokens::RADIUS_STEPS, true) ? $radius : ($fallback['layout.radius'] ?? self::SAFE['layout.radius']);
        $density = $values['layout.density'] ?? null;
        $out['layout.density'] = in_array($density, Tokens::DENSITY_STEPS, true) ? $density : ($fallback['layout.density'] ?? self::SAFE['layout.density']);

        return $out;
    }

    /**
     * @param  array<string,string>  $tokens
     * @return array<string,string>|null AA-clean tokens, or null when unfixable
     */
    protected static function fixContrast(array $tokens, string $template): ?array
    {
        for ($pass = 0; $pass < 6; $pass++) {
            $warnings = ContrastGuard::warnings($template, $tokens);
            if ($warnings === []) {
                return $tokens;
            }
            foreach ($warnings as $warning) {
                $field = (string) $warning['field'];
                if (! empty($warning['suggestion'])) {
                    $tokens[$field] = (string) $warning['suggestion'];
                } elseif ($field === 'colors.primary') {
                    // A primary no label can sit on: darken it toward readable ink.
                    $tokens[$field] = Tokens::shade($tokens[$field], 0.35);
                } else {
                    // Mid-tone background: repaint the surface the text sits on.
                    $dark = Tokens::relativeLuminance($tokens['colors.text']) > 0.5;
                    $tokens['colors.background'] = $dark ? '#111317' : '#ffffff';
                    $tokens['colors.surface'] = $dark ? '#1b1d22' : '#f5f6f7';
                }
            }
        }

        return ContrastGuard::warnings($template, $tokens) === [] ? $tokens : null;
    }
}
