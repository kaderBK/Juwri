<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;


/**
 * Customizer v2 — WI-T6: the contrast guard (DECISIONS K4, WARN-ONLY).
 *
 * When the owner publishes, the pairs that a shopper actually has to read —
 * body text on the background, text on a card, a button label on the primary
 * fill — are checked against WCAG AA (4.5:1 for normal text). A failing pair is
 * never blocked; it comes back as a warning naming the pair, its ratio, and the
 * nearest shade of the SAME colour that would pass, so the owner can fix it in
 * one click or publish anyway.
 *
 * It reasons over the resolved role values (owner's choice else theme default),
 * so a store that changed nothing is judged against what it actually renders.
 *
 * ─── Phase 3 (P3-01): dark scheme + derived colours ───────────────────────────
 * A theme that declares `scheme.mode` / `colors_dark.*` (the kit themes) is held to
 * the kit's contract §8 in BOTH scheme blocks, on the owner's own colours: the five
 * roles, the theme-only colours the theme CSS derives from them (surface-2, muted,
 * border, border-strong; css/tokens.css `color-mix()`), the fixed status colours and
 * the on-primary / on-accent inks. failures() is the check; refusals() is what the
 * owner save uses, and it REFUSES (nothing saved) where warnings() only warns. Themes
 * without dark support keep the warn-only behaviour above, untouched (DECISIONS K4).
 *
 * REVIEW (Opus): everything from DERIVED to refusals() is a PHP port of the kit's
 * tools/themes/tools/check_derived.py + build_theme_css.py (same formulas, same
 * pairs, same thresholds: WCAG AA text 4.5:1, large text 3:1, UI 3:1). Parity with
 * the Python is pinned by tests/Fixtures/theme/contrast_parity.json, generated once
 * by tools/themes/tools/make_contrast_fixture.py. Note `kitMix()`: Python's round()
 * is banker's rounding, PHP's is half-away-from-zero; the port rounds half to even
 * so a channel on an exact .5 cannot differ from the proof.
 */
class ContrastGuard
{
    /** WCAG AA for normal-size text. */
    public const AA_NORMAL = 4.5;

    /**
     * @param  string  $template
     * @param  array<string,mixed>  $values  the values about to be published
     * @return array<int,array{
     *     pair:string, label:string, foreground:string, background:string,
     *     ratio:float, field:string, suggestion:?string, suggestionRatio:?float
     * }>  one entry per FAILING pair; empty when everything passes
     */
    public static function warnings(string $template, array $values): array
    {
        // Judge the EFFECTIVE palette a shopper sees — owner overrides layered
        // over the theme's own defaults — not just the handful of roles the owner
        // happened to touch. (Emission uses overrides-only; analysis needs the
        // full picture.)
        $resolved = Tokens::resolve($template, $values, true);

        $primary    = Tokens::normalizeHex($resolved['colors.primary'] ?? null);
        $background  = Tokens::normalizeHex($resolved['colors.background'] ?? null);
        $surface    = Tokens::normalizeHex($resolved['colors.surface'] ?? null);
        $text       = Tokens::normalizeHex($resolved['colors.text'] ?? null);
        $accent     = Tokens::normalizeHex($resolved['colors.accent'] ?? null);

        // Each pair: a label, the foreground being read, the surface behind it,
        // and the FIELD to nudge when it fails (the foreground role — moving the
        // text/label is what fixes legibility, not repainting the whole page).
        $candidates = [
            ['Body text on background',    $text,           $background, 'colors.text'],
            ['Body text on cards',         $text,           $surface,    'colors.text'],
            ['Button label on primary',    Tokens::readableInk((string) $primary), $primary, 'colors.primary'],
            ['Accent label on background', $accent,         $background, 'colors.accent'],
        ];

        $warnings = [];
        foreach ($candidates as [$label, $fg, $bg, $field]) {
            if ($fg === null || $bg === null) {
                continue;
            }
            $ratio = Tokens::contrastRatio($fg, $bg);
            if ($ratio >= self::AA_NORMAL) {
                continue;
            }

            $fix = self::nearestPassingShade($fg, $bg);
            $warnings[] = [
                'pair'            => $label,
                'label'          => $label,
                'foreground'     => $fg,
                'background'     => $bg,
                'ratio'          => round($ratio, 2),
                'field'          => $field,
                'suggestion'     => $fix['hex'],
                'suggestionRatio' => $fix['ratio'],
            ];
        }

        return $warnings;
    }

    /* ───────────────── P3-01: dark scheme + derived colours (port of check_derived.py) ───────────────── */

    /** WCAG AA for large text (>= 24px, or >= 18.66px bold). Not used by a pair today; named for the contract. */
    public const AA_LARGE = 3.0;

    /** WCAG 1.4.11 non-text contrast (input borders, focus ring, selected chip). */
    public const AA_UI = 3.0;

    /**
     * Theme-only colours the theme CSS derives with color-mix(): scheme => token =>
     * [mix role, % of that role, into base role]. kit tools/themes/tools/build_theme_css.py FORMULAS.
     */
    private const DERIVED = [
        'light' => [
            'surface_2' => ['text', 4, 'background'],
            'muted' => ['text', 72, 'background'],
            'border' => ['text', 10, 'background'],
            'border_strong' => ['text', 56, 'surface'],
        ],
        'dark' => [
            'surface_2' => ['text', 8, 'surface'],
            'muted' => ['text', 70, 'background'],
            'border' => ['text', 14, 'background'],
            'border_strong' => ['text', 45, 'surface'],
        ],
    ];

    /** Fixed status colours per scheme (identical in every kit theme and preset). */
    private const STATUS = [
        'light' => ['danger' => '#b42318', 'success' => '#1a6b3c', 'warning' => '#8a5a00'],
        'dark' => ['danger' => '#ff8a7a', 'success' => '#5fcb8c', 'warning' => '#f2c14e'],
    ];

    /**
     * The pairs checked per scheme: [foreground, background, needed ratio, label].
     * Same order and thresholds as check_derived.py CHECKS (text 4.5, UI 3.0).
     */
    private const CHECKS = [
        ['text', 'background', 4.5, 'Body text on background'],
        ['text', 'surface', 4.5, 'Body text on cards'],
        ['muted', 'background', 4.5, 'Secondary text on background'],
        ['muted', 'surface', 4.5, 'Secondary text on cards'],
        ['on_primary', 'primary', 4.5, 'Button label on primary'],
        ['on_accent', 'accent', 4.5, 'Label on accent colour'],
        ['primary', 'background', 3.0, 'Brand colour on background'],
        ['primary', 'surface', 3.0, 'Brand colour on cards'],
        ['border_strong', 'surface', 3.0, 'Input borders on cards'],
        ['danger', 'surface', 4.5, 'Error text on cards'],
        ['success', 'surface', 4.5, 'Success text on cards'],
        ['text', 'surface_2', 4.5, 'Body text on tinted panels'],
        ['muted', 'surface_2', 4.5, 'Secondary text on tinted panels'],
        ['success', 'surface_2', 4.5, 'Success text on tinted panels'],
        ['danger', 'surface_2', 4.5, 'Error text on tinted panels'],
        ['border_strong', 'surface_2', 3.0, 'Input borders on tinted panels'],
        ['border_strong', 'background', 3.0, 'Input borders on background'],
        ['warning', 'surface', 4.5, 'Warning text on cards'],
        ['warning', 'surface_2', 4.5, 'Warning text on tinted panels'],
    ];

    /**
     * CSS color-mix(in srgb, $a $pct%, $b) as the kit's Python computes it:
     * per channel round(a*pct/100 + b*(100-pct)/100), rounding .5 to the EVEN integer.
     */
    public static function kitMix(string $a, string $b, int $pct): string
    {
        $ca = Tokens::rgb($a);
        $cb = Tokens::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $v = $ca[$i] * $pct / 100 + $cb[$i] * (100 - $pct) / 100;
            $out .= sprintf('%02x', self::roundHalfEven($v));
        }

        return $out;
    }

    private static function roundHalfEven(float $v): int
    {
        $floor = (int) floor($v);
        $diff = $v - $floor;
        if ($diff < 0.5) {
            return $floor;
        }
        if ($diff > 0.5) {
            return $floor + 1;
        }

        return $floor % 2 === 0 ? $floor : $floor + 1;
    }

    /**
     * The theme-only colours for one scheme, from the five roles (hex).
     *
     * @param  array<string,string>  $roles  primary, background, surface, text, accent
     * @return array<string,string>  surface_2, muted, border, border_strong
     */
    public static function derive(array $roles, string $scheme): array
    {
        $out = [];
        foreach (self::DERIVED[$scheme] as $token => [$mixRole, $pct, $baseRole]) {
            $out[$token] = self::kitMix($roles[$mixRole], $roles[$baseRole], $pct);
        }

        return $out;
    }

    /**
     * Every colour the theme CSS ends up with in one scheme: the roles, the derived
     * tokens, the two on-* inks and the fixed status colours.
     *
     * @param  array<string,string>  $roles
     * @return array<string,string>
     */
    public static function palette(array $roles, string $scheme): array
    {
        $palette = $roles + self::derive($roles, $scheme) + self::STATUS[$scheme];
        $palette['on_primary'] = Tokens::readableInk($roles['primary']);
        $palette['on_accent'] = Tokens::readableInk($roles['accent']);

        return $palette;
    }

    /**
     * Run every pair on one scheme's roles.
     *
     * @param  array<string,string>  $roles
     * @return array<int,array{fg:string,bg:string,ratio:float,need:float,pass:bool,label:string,foreground:string,background:string}>
     */
    public static function evaluate(array $roles, string $scheme): array
    {
        $palette = self::palette($roles, $scheme);
        $out = [];
        foreach (self::CHECKS as [$fg, $bg, $need, $label]) {
            $ratio = Tokens::contrastRatio($palette[$fg], $palette[$bg]);
            $out[] = [
                'fg' => $fg, 'bg' => $bg, 'ratio' => $ratio, 'need' => $need, 'pass' => $ratio >= $need,
                'label' => $label, 'foreground' => $palette[$fg], 'background' => $palette[$bg],
            ];
        }

        return $out;
    }

    /**
     * The five roles of one scheme block as normalized hex, or null when any is
     * missing or unparseable (nothing to judge then).
     *
     * @param  array<string,mixed>  $resolved  Tokens::resolve(..., true)
     * @return array<string,string>|null
     */
    private static function rolesOf(array $resolved, string $prefix): ?array
    {
        $roles = [];
        foreach (Tokens::COLOR_ROLES as $role) {
            $hex = Tokens::normalizeHex($resolved[$prefix . '.' . $role] ?? null);
            if ($hex === null) {
                return null;
            }
            $roles[$role] = $hex;
        }

        return $roles;
    }

    /** The owner-editable role whose value to change to fix a failing pair. */
    private static function blameRole(string $fg, string $bg): string
    {
        if (in_array($fg, ['on_primary', 'primary'], true)) {
            return 'primary';
        }
        if ($fg === 'on_accent') {
            return 'accent';
        }
        if (in_array($fg, ['danger', 'success', 'warning'], true)) {
            return $bg === 'background' ? 'background' : 'surface';
        }

        return 'text'; // text, muted, border_strong: all follow the text role
    }

    /**
     * Every failing pair of a dark-capable theme, in BOTH scheme blocks, judged on the
     * effective palette (owner value else theme default). [] for a theme that does not
     * declare `scheme.mode` (they keep warnings() only).
     *
     * Same shape as warnings() plus `scheme` (light|dark), `need` and `key`
     * ("scheme:fg/bg", stable across edits).
     *
     * @param  array<string,mixed>  $values
     * @return array<int,array<string,mixed>>
     */
    public static function failures(string $template, array $values): array
    {
        if (Tokens::schemeMode($template, $values) === null) {
            return [];
        }
        $resolved = Tokens::resolve($template, $values, true);
        $out = [];
        foreach (['light' => 'colors', 'dark' => 'colors_dark'] as $scheme => $prefix) {
            $roles = self::rolesOf($resolved, $prefix);
            if ($roles === null) {
                continue;
            }
            $results = self::evaluate($roles, $scheme);
            foreach ($results as $r) {
                if ($r['pass']) {
                    continue;
                }
                $role = self::blameRole($r['fg'], $r['bg']);
                $fix = self::suggest($roles, $scheme, $role, $r['fg'], $r['bg'], count(array_filter($results, fn ($x) => ! $x['pass'])));
                $out[] = [
                    'pair' => $r['label'],
                    'label' => $r['label'],
                    'foreground' => $r['foreground'],
                    'background' => $r['background'],
                    'ratio' => round($r['ratio'], 2),
                    'need' => $r['need'],
                    'field' => $prefix . '.' . $role,
                    'suggestion' => $fix['hex'],
                    'suggestionRatio' => $fix['ratio'],
                    'scheme' => $scheme,
                    'key' => $scheme . ':' . $r['fg'] . '/' . $r['bg'],
                ];
            }
        }

        return $out;
    }

    /**
     * Walk the blamed role toward black or white in 5% steps until the failing pair
     * passes without making any other pair fail. Returns the first such shade, else null.
     *
     * @param  array<string,string>  $roles
     * @return array{hex:?string, ratio:?float}
     */
    private static function suggest(array $roles, string $scheme, string $role, string $fg, string $bg, int $failingNow): array
    {
        foreach (['#000000', '#ffffff'] as $target) {
            for ($step = 1; $step <= 20; $step++) {
                $candidate = $roles;
                $candidate[$role] = Tokens::mix($roles[$role], $target, $step * 0.05);
                $results = self::evaluate($candidate, $scheme);
                $mine = null;
                $failing = 0;
                foreach ($results as $r) {
                    $failing += $r['pass'] ? 0 : 1;
                    if ($r['fg'] === $fg && $r['bg'] === $bg) {
                        $mine = $r;
                    }
                }
                if ($mine !== null && $mine['pass'] && $failing < $failingNow) {
                    return ['hex' => $candidate[$role], 'ratio' => round($mine['ratio'], 2)];
                }
            }
        }

        return ['hex' => null, 'ratio' => null];
    }

    /**
     * What the owner save must refuse: failing pairs that the change INTRODUCES.
     * A pair that already failed before the change (an older save, a stale preset) is
     * not charged to this save, so an owner is never locked out of an unrelated edit.
     * `$before` = the values in force; `$after` = those with the change applied.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<int,array<string,mixed>>  failures() entries, one per introduced pair
     */
    public static function refusals(string $template, array $before, array $after): array
    {
        $existing = array_column(self::failures($template, $before), 'key');

        return array_values(array_filter(
            self::failures($template, $after),
            static fn (array $f): bool => ! in_array($f['key'], $existing, true)
        ));
    }

    /**
     * Walk $foreground toward whichever of black/white it is already closer to,
     * in 5% mix steps, until it clears AA against $background. Returns the first
     * passing shade, or null if even pure black/white cannot pass (a background
     * so mid-toned nothing reads on it — then repainting the background is the
     * only real fix, which we do not guess at).
     *
     * @return array{hex:?string, ratio:?float}
     */
    private static function nearestPassingShade(string $foreground, string $background): array
    {
        // Push toward the end that increases contrast with the background.
        $target = Tokens::relativeLuminance($background) > 0.5 ? '#000000' : '#ffffff';

        for ($step = 1; $step <= 20; $step++) {
            $candidate = Tokens::mix($foreground, $target, $step * 0.05);
            $ratio = Tokens::contrastRatio($candidate, $background);
            if ($ratio >= self::AA_NORMAL) {
                return ['hex' => $candidate, 'ratio' => round($ratio, 2)];
            }
        }

        return ['hex' => null, 'ratio' => null];
    }
}
