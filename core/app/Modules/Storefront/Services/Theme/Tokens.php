<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

use App\Services\ThemeCustomizerService;

/**
 * Customizer v2 — WI-T1 (colour roles + derived shades) and WI-T3 (radius +
 * density scales): the design-token layer.
 *
 * The owner sets a handful of ROLES; this class derives the whole ramp from them
 * and hands the result to `store_templates/_partials/theme-tokens.blade.php`, which
 * renders one `:root{…}` block. Themes consume the tokens as
 * `var(--tc-primary, #3fd463)` — the literal stays in the theme's own CSS as the
 * fallback, so a stylesheet that never sees the include still renders today's look
 * (RESEARCH.md §2).
 *
 * ─── Naming scheme ────────────────────────────────────────────────────────────
 * Four prefixes, one meaning each. Nothing else may be emitted from here.
 *
 *   --tc-*   theme COLOUR
 *   --tr-*   theme RADIUS
 *   --ts-*   theme SPACING
 *   --tf-*   theme FONT stack
 *
 * Colour, for each role R ∈ {primary, background, surface, text, accent}:
 *
 *   --tc-R          the role colour exactly as resolved (hex, 6 digits)
 *   --tc-R-300      tint  — R mixed 24% toward #ffffff   (lightest)
 *   --tc-R-400      tint  — R mixed 12% toward #ffffff
 *   --tc-R-600      shade — R mixed 12% toward #000000
 *   --tc-R-700      shade — R mixed 24% toward #000000   (darkest)
 *   --tc-R-rgb      "r, g, b" so a theme can write rgba(var(--tc-R-rgb), .12)
 *
 * The ramp reads like Tailwind's: bigger number = darker, and the un-numbered
 * token is the 500 the owner actually picked. There is deliberately no 500 alias
 * — one concept, one id, which is the whole point of this run.
 *
 * Three cross-role tokens close the set:
 *
 *   --tc-text-muted   text at 55% over the background (RESEARCH §2 "muted")
 *   --tc-on-primary   #ffffff or #111111, whichever carries more contrast ON the
 *   --tc-on-accent    primary / accent fill — so a CTA label never goes illegible
 *                     when the owner picks a pale brand colour
 *
 * Radius — `layout.radius` ∈ none|soft|round|pill picks one row of RADIUS_SCALE:
 *
 *   --tr-card       panels, cards, sheets
 *   --tr-control    buttons, inputs, segmented controls
 *   --tr-pill       chips, badges, capsules
 *
 * Spacing — `layout.density` ∈ compact|cozy|comfortable picks one row of
 * DENSITY_SCALE, a four-step ramp:
 *
 *   --ts-gap-1 … --ts-gap-4   tightest → loosest
 *
 * Fonts — `fonts.pair` names an entry in config/theme-fonts.php (WI-T2), a
 * SELF-HOSTED Latin + Arabic pairing. Two stacks come out:
 *
 *   --tf-heading    headings, section titles, prices
 *   --tf-body       body copy
 *
 * Both stacks end in the pair's Arabic family before the system fallbacks, so an
 * Arabic storefront (the platform default locale) gets a designed face and not a
 * system substitute. `fonts.pair` defaults to the `none` sentinel, for which NO
 * `--tf-*` var is emitted and the @font-face partial stays silent — so the
 * control is inert until an owner uses it.
 *
 * ─── Dark scheme (Phase 3, P3-01) ─────────────────────────────────────────────
 * A theme that DECLARES `scheme.mode` (light | dark | auto) and the `colors_dark.*`
 * roles opts in; every other theme has no such fields, resolves no mode, and keeps
 * the single block above byte for byte. (Kit themes declare dark support in their
 * generated theme.json; kit/README "Core changes" 1.)
 *
 *   light  one block from `colors.*` (dark roles ignored)
 *   dark   one block carrying the dark roles under the SAME `--tc-*` names
 *   auto   the light block, plus the dark roles inside
 *          `@media (prefers-color-scheme: dark)` (see darkBlock())
 *
 * Derived tokens (ramp, -rgb, text-muted, on-*) are computed per block. Override
 * leak: when the owner changed a light role and not its dark twin, the dark block
 * must NOT inherit the light value, so every `--tc-*` the light block writes that the
 * dark block does not is written as `initial` (guaranteed-invalid), which makes the
 * theme's own dark fallback in css/tokens.css apply. Theme-only colours
 * (surface-2, muted, border …) are `--x-*` derived inside the theme CSS; the
 * ContrastGuard runs the same derivation on the owner's values.
 *
 * ─── The parity contract ──────────────────────────────────────────────────────
 * Every var is emitted at ALL times, resolved as "the owner's value if they set
 * one, otherwise this theme's own declared default". The defaults live in each
 * theme.json (via the shared `tokens` fragment, overridden per theme) and are the
 * literals already sitting in that theme's stylesheet — so a store with no
 * theme_settings row gets tokens whose values reproduce exactly what it renders
 * today. That is what makes the T4/T5 refactor (`#3fd463` → `var(--tc-primary,
 * #3fd463)`) pixel-safe, and it is guarded by ThemeTokensTest.
 *
 * A theme adopting the radius or spacing tokens MUST first confirm that its
 * `layout.radius` / `layout.density` default reproduces its current metrics — the
 * scales below are a first-pass calibration, not measured per theme.
 */
class Tokens
{
    /** The colour roles, in emission order. Ids are the canonical schema field ids. */
    public const COLOR_ROLES = ['primary', 'background', 'surface', 'text', 'accent'];

    /** P3-01: the owner's scheme choice; present only on themes that declare dark support. */
    public const SCHEME_MODES = ['light', 'dark', 'auto'];

    public const RADIUS_STEPS = ['none', 'soft', 'round', 'pill'];

    public const DENSITY_STEPS = ['compact', 'cozy', 'comfortable'];

    /** Tint/shade stops, `--tc-R-<key>` => [mix target, amount]. */
    private const SHADE_STOPS = [
        '300' => ['#ffffff', 0.24],
        '400' => ['#ffffff', 0.12],
        '600' => ['#000000', 0.12],
        '700' => ['#000000', 0.24],
    ];

    /** layout.radius => [--tr-card, --tr-control, --tr-pill] */
    private const RADIUS_SCALE = [
        'none' => ['0px', '0px', '0px'],
        'soft' => ['10px', '8px', '999px'],
        'round' => ['18px', '14px', '999px'],
        'pill' => ['26px', '999px', '999px'],
    ];

    /** layout.density => [--ts-gap-1 … --ts-gap-4] */
    private const DENSITY_SCALE = [
        'compact' => ['4px', '8px', '12px', '18px'],
        'cozy' => ['6px', '12px', '18px', '26px'],
        'comfortable' => ['8px', '16px', '24px', '36px'],
    ];

    /**
     * The schema field ids this layer owns. The tokens partial reads exactly
     * these (with literal keys, so ThemeSchemaLintTest can see the reads).
     *
     * @return array<int,string>
     */
    public static function fieldIds(): array
    {
        $ids = [];
        foreach (self::COLOR_ROLES as $role) {
            $ids[] = 'colors.'.$role;
        }

        return array_merge($ids, ['layout.radius', 'layout.density', 'fonts.pair'], self::schemeFieldIds());
    }

    /**
     * P3-01: the dark-scheme field ids (`scheme.mode` + `colors_dark.<role>`). A theme
     * declares all of them or none; legacy themes declare none and are unaffected.
     *
     * @return array<int,string>
     */
    public static function schemeFieldIds(): array
    {
        $ids = ['scheme.mode'];
        foreach (self::COLOR_ROLES as $role) {
            $ids[] = 'colors_dark.'.$role;
        }

        return $ids;
    }

    /* ───────────────────────── colour maths (RESEARCH §2) ───────────────────── */

    /**
     * Expand and validate a CSS hex colour. `#abc` → `#aabbcc`; anything that is
     * not a 3- or 6-digit hex returns null rather than a guess — an unparseable
     * colour must degrade to the theme's own literal, never to black (the shared
     * layout learned that the hard way with sscanf and `rgba(17,1,,1)`).
     */
    public static function normalizeHex($hex): ?string
    {
        if (! is_string($hex)) {
            return null;
        }
        $hex = trim($hex);
        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $hex, $m)) {
            $hex = '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : null;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $hex = self::normalizeHex($hex) ?? '#000000';
        $int = (int) hexdec(substr($hex, 1));

        return [($int >> 16) & 255, ($int >> 8) & 255, $int & 255];
    }

    /** Per-channel sRGB mix: round($a*(1-$t)+$b*$t). */
    public static function mixChannel(int $a, int $b, float $t): int
    {
        return (int) max(0, min(255, round($a * (1 - $t) + $b * $t)));
    }

    /** Mix colour $a toward colour $b by $t (0 = all $a, 1 = all $b). */
    public static function mix(string $a, string $b, float $t): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);

        return sprintf(
            '#%02x%02x%02x',
            self::mixChannel($ar, $br, $t),
            self::mixChannel($ag, $bg, $t),
            self::mixChannel($ab, $bb, $t)
        );
    }

    /** Lighter: mix toward white. */
    public static function tint(string $hex, float $t): string
    {
        return self::mix($hex, '#ffffff', $t);
    }

    /** Darker: mix toward black. */
    public static function shade(string $hex, float $t): string
    {
        return self::mix($hex, '#000000', $t);
    }

    /** WCAG relative luminance (RESEARCH §4) — also what WI-T6's contrast guard will use. */
    public static function relativeLuminance(string $hex): float
    {
        $lin = [];
        foreach (self::rgb($hex) as $channel) {
            $c = $channel / 255;
            $lin[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /** WCAG contrast ratio, lighter colour first. */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** The ink (white or near-black) that reads best ON $background. */
    public static function readableInk(string $background): string
    {
        return self::contrastRatio($background, '#ffffff') >= self::contrastRatio($background, '#111111')
            ? '#ffffff'
            : '#111111';
    }

    /* ───────────────────────────── token building ───────────────────────────── */

    /**
     * The colour part of build(): role vars, ramp, -rgb, text-muted, on-*.
     *
     * @param  array<string,mixed>  $resolved  keyed by schema field id (`colors.<role>`)
     * @return array<string,string>
     */
    public static function buildColors(array $resolved): array
    {
        $vars = [];
        $colors = [];
        foreach (self::COLOR_ROLES as $role) {
            $hex = self::normalizeHex($resolved['colors.'.$role] ?? null);
            if ($hex === null) {
                continue;
            }
            $colors[$role] = $hex;
            $vars['--tc-'.$role] = $hex;
            foreach (self::SHADE_STOPS as $stop => [$target, $amount]) {
                $vars['--tc-'.$role.'-'.$stop] = self::mix($hex, $target, $amount);
            }
            [$r, $g, $b] = self::rgb($hex);
            $vars['--tc-'.$role.'-rgb'] = "{$r}, {$g}, {$b}";
        }

        // Secondary text: the role colour at 55% over the background, per
        // RESEARCH §2 — computed, not an alpha, so it stays opaque over imagery.
        if (isset($colors['text'], $colors['background'])) {
            $vars['--tc-text-muted'] = self::mix($colors['text'], $colors['background'], 0.45);
        }
        foreach (['primary', 'accent'] as $role) {
            if (isset($colors[$role])) {
                $vars['--tc-on-'.$role] = self::readableInk($colors[$role]);
            }
        }

        return $vars;
    }

    /**
     * Build the full var map from already-resolved role values.
     *
     * @param  array<string,mixed>  $resolved  keyed by schema field id
     * @return array<string,string> keyed by CSS custom-property name
     */
    public static function build(array $resolved): array
    {
        $vars = self::buildColors($resolved);

        $radius = (string) ($resolved['layout.radius'] ?? '');
        if (isset(self::RADIUS_SCALE[$radius])) {
            [$card, $control, $pill] = self::RADIUS_SCALE[$radius];
            $vars['--tr-card'] = $card;
            $vars['--tr-control'] = $control;
            $vars['--tr-pill'] = $pill;
        }

        $density = (string) ($resolved['layout.density'] ?? '');
        if (isset(self::DENSITY_SCALE[$density])) {
            foreach (self::DENSITY_SCALE[$density] as $i => $gap) {
                $vars['--ts-gap-'.($i + 1)] = $gap;
            }
        }

        // WI-T2: a self-hosted pairing. The `none` sentinel, '' and any unknown id
        // emit nothing at all, which is what keeps the default a no-op — see FontPairs.
        $fonts = FontPairs::stacks($resolved['fonts.pair'] ?? null);
        if ($fonts !== []) {
            $vars['--tf-heading'] = $fonts['heading'];
            $vars['--tf-body'] = $fonts['body'];
        }

        return $vars;
    }

    /**
     * Resolve a template's tokens: the owner's stored value where they set one,
     * this theme's own schema default everywhere else.
     *
     * @param  array<string,mixed>  $values  stored/draft settings (nulls ignored)
     * @return array<string,string>
     */
    public static function forTemplate(string $template, array $values = []): array
    {
        // EMISSION resolves overrides ONLY: a token var is written out only for a
        // role the owner actually set. This is what preserves parity across every
        // theme. A theme consumes a token as `var(--tc-primary, <its own default>)`,
        // and several themes' own default is not a CSS literal but the store's
        // legacy `$store->color` (rendered through the shared `--theme-color-1`).
        // If the token layer emitted `--tc-primary` = the theme's schema default
        // whenever the owner set nothing, it would silently override that legacy
        // colour — flipping every ember/aurora/picnic/… store off its saved brand.
        // Emitting only overrides means an untouched store hits its existing
        // fallback chain unchanged, and the customizer wins the moment it is used.
        $resolved = self::resolve($template, $values, false);

        // P3-01: a dark-mode store writes the dark roles under the normal names.
        // (light / auto / a theme with no dark support: the block is unchanged.)
        if (self::schemeMode($template, $values) === 'dark') {
            return self::build(self::asDark($resolved));
        }

        return self::build($resolved);
    }

    /**
     * P3-01: the effective scheme of a template for these values — the owner's
     * `scheme.mode`, else the theme's declared default — or null when the theme
     * does not declare `scheme.mode` (legacy themes: single block, no dark).
     *
     * @param  array<string,mixed>  $values
     */
    public static function schemeMode(string $template, array $values = []): ?string
    {
        try {
            $schema = ThemeCustomizerService::schemaFor($template);
            if (! isset(ThemeCustomizerService::fields($schema)['scheme.mode'])) {
                return null;
            }
            $default = ThemeCustomizerService::defaults($schema)['scheme.mode'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
        $mode = $values['scheme.mode'] ?? null;
        foreach ([$mode, $default] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::SCHEME_MODES, true)) {
                return $candidate;
            }
        }

        return 'light';
    }

    /**
     * The resolved map with `colors_dark.<role>` standing in for `colors.<role>`
     * (a dark role the owner did not set is simply absent, never the light value).
     *
     * @param  array<string,mixed>  $resolved
     * @return array<string,mixed>
     */
    private static function asDark(array $resolved): array
    {
        foreach (self::COLOR_ROLES as $role) {
            $dark = $resolved['colors_dark.'.$role] ?? null;
            unset($resolved['colors.'.$role]);
            if ($dark !== null) {
                $resolved['colors.'.$role] = $dark;
            }
        }

        return $resolved;
    }

    /**
     * P3-01: the declarations for `@media (prefers-color-scheme: dark)` — only for
     * `scheme.mode = auto`; [] for every other mode and for themes without dark support.
     *
     * The dark roles (and their derived tokens) come first. Then the override-leak
     * guard: any `--tc-*` the light block writes that the dark block does not is set
     * to `initial`, so the owner's light value never leaks into the dark scheme and
     * the theme's own dark fallback applies (kit/README "Core changes" 1).
     *
     * @param  array<string,mixed>  $values
     * @return array<string,string>
     */
    public static function darkBlock(string $template, array $values = []): array
    {
        if (self::schemeMode($template, $values) !== 'auto') {
            return [];
        }
        $resolved = self::resolve($template, $values, false);
        $light = self::buildColors($resolved);
        $dark = self::buildColors(self::asDark($resolved));
        foreach (array_keys($light) as $name) {
            if (! array_key_exists($name, $dark)) {
                $dark[$name] = 'initial';
            }
        }

        return $dark;
    }

    /**
     * P3-01: the complete rule text for the token `<style>`: `:root{…}` and, for an
     * `auto` store with something to say, the dark media block. One call site shape
     * for the Blade partial and the marketplace shell.
     *
     * @param  array<string,mixed>  $values
     */
    public static function stylesheet(string $template, array $values = []): string
    {
        $base = self::css(self::forTemplate($template, $values));
        $dark = self::css(self::darkBlock($template, $values));
        $out = $base !== '' ? ':root{'.$base.'}' : '';
        if ($dark !== '') {
            $out .= '@media (prefers-color-scheme: dark){:root{'.$dark.'}}';
        }

        return $out;
    }

    /**
     * A token only resolves for a template whose schema actually DECLARES it.
     * That is what keeps the ~3,391 legacy-template stores out of the v2 code
     * path (DECISIONS K7): a stale row carrying `fonts.pair` on a template that
     * offers no such control must not start emitting fonts, and the same goes
     * for any other token a theme does not expose.
     *
     * @param  array<string,mixed>  $values
     * @param  bool  $withDefaults  fill unset roles with the schema default. FALSE
     *                              for CSS emission (overrides only, preserves parity); TRUE for analysis
     *                              that needs the EFFECTIVE value a store renders (e.g. the contrast
     *                              guard, which must judge the real text/background even when the owner
     *                              left them at the theme default).
     * @return array<string,mixed> field id => value
     */
    public static function resolve(string $template, array $values = [], bool $withDefaults = true): array
    {
        $fields = [];
        $defaults = [];
        try {
            $schema = ThemeCustomizerService::schemaFor($template);
            $fields = ThemeCustomizerService::fields($schema);
            $defaults = ThemeCustomizerService::defaults($schema);
        } catch (\Throwable $e) {
            // A missing/broken theme.json must not take a storefront down: with no
            // schema we simply emit no vars and every theme literal stands.
        }

        $out = [];
        foreach (self::fieldIds() as $id) {
            if (! isset($fields[$id])) {
                continue;
            }
            $value = $values[$id] ?? null;
            if (($value === null || $value === '') && $withDefaults) {
                $value = $defaults[$id] ?? null;
            }
            if ($value !== null && $value !== '') {
                $out[$id] = $value;
            }
        }

        return $out;
    }

    /**
     * Serialize to CSS declarations. Values are built here (normalized hex, a
     * constant out of the scales above, or a font stack FontPairs already
     * sanitized), never passed through from a request — the final filter is
     * belt-and-braces so nothing can ever break out of the declaration block.
     *
     * The value charset allows `'` and `-` for font stacks ("'IBM Plex Sans
     * Arabic', -apple-system, …"); `{`, `}`, `;`, `:`, `(` and `/` stay out, so
     * a declaration still cannot be closed early.
     *
     * @param  array<string,string>  $vars
     */
    public static function css(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) {
            if (! preg_match('/^--(tc|tr|ts|tf)-[a-z0-9-]+$/', $name)) {
                continue;
            }
            if (! preg_match("/^[#a-z0-9,' -]+$/i", (string) $value)) {
                continue;
            }
            $out .= $name.':'.$value.';';
        }

        return $out;
    }
}
