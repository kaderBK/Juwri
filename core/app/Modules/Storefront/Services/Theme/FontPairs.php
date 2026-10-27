<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

/**
 * Customizer v2 — WI-T2: the self-hosted font-pair registry.
 *
 * `config/theme-fonts.php` is the data (one entry per curated pairing, every
 * file a WOFF2 committed to this repo); this class is the only thing allowed to
 * read it, exactly as `ThemeKeys` is the only reader of `config/theme-aliases.php`.
 *
 * Two consumers:
 *   - Juwri\Modules\Storefront\Services\Theme\Tokens — turns the selected pair into `--tf-heading`
 *     and `--tf-body`, emitted next to the colour/radius/density vars;
 *   - store_templates/_partials/theme-fonts.blade.php — emits the @font-face
 *     rules for the SELECTED pair only. Never all of them: six pairs is ~1.2 MB
 *     of woff2 and a storefront must ship one pair's worth.
 *
 * NO PAIR SELECTED IS THE DEFAULT AND MUST STAY INERT. `fonts.pair` defaults to
 * the `none` sentinel (see config/theme-fonts.php for why it is not ''); for it,
 * for '', and for any id the registry does not know, this class returns no faces
 * and no stacks, so a store that has never touched the control gets
 * byte-identical HTML to what it renders today. ThemeFontPairsTest pins that.
 *
 * Everything that reaches CSS is re-validated here even though it comes from a
 * committed config file rather than a request: a family with a `}` in it would
 * break out of the @font-face block, and the cost of the guard is one preg_match.
 */
class FontPairs
{
    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    /**
     * Saudi Riyal Symbol (U+20C1) rollout — S2.
     *
     * The always-on glyph family. Its @font-face is emitted on EVERY storefront
     * (not gated on `fonts.pair`) and it is prepended to the START of every font
     * stack. `unicode-range:U+20C1` restricts the face to the single Saudi Riyal
     * codepoint, so it is fetched and used ONLY when U+20C1 is drawn — every other
     * character still uses the theme's own type, byte-for-byte. Without this face
     * in the effective stack, U+20C1 renders as a tofu box (no system font maps it
     * yet — it is Unicode 17.0, 2025).
     */
    public const RIYAL_FAMILY = 'Saudi Riyal';

    /** weight => committed woff2 for the U+20C1 glyph (S1 assets). */
    private const RIYAL_FILES = [
        '400' => 'assets/fonts/saudi-riyal-400.woff2',
        '700' => 'assets/fonts/saudi-riyal-700.woff2',
    ];

    /**
     * AED Dirham Sign (U+20C3, Unicode 18.0) — the same shared glyph family.
     *
     * The dirham face is registered UNDER the SAME 'Saudi Riyal' family (the name
     * is historical — it is really the "new-currency-glyph" family that already
     * leads every storefront/dashboard/admin/POS font stack). Because CSS lets one
     * family carry many @font-face rules distinguished by `unicode-range`, adding a
     * U+20C3 face here makes the dirham glyph resolve everywhere the family is
     * already in the stack — zero stack edits. Its own MIT-licensed subset maps
     * ONLY U+20C3 (verified cmap), so it is inert for every other character.
     * One file covers all weights (`font-weight:100 900`). No system font maps
     * U+20C3 until Unicode 18.0 fonts ship (~Sept 2026).
     */
    private const DIRHAM_FILE = 'assets/fonts/aed-dirham-400.woff2';

    /** The stack prefix that puts the Saudi Riyal glyph first, then a comma. */
    public static function riyalStackPrefix(): string
    {
        return "'".self::RIYAL_FAMILY."', ";
    }

    /**
     * Repo-relative woff2 paths for the U+20C1 face (both weights).
     *
     * @return list<string>
     */
    public static function riyalFiles(): array
    {
        return array_merge(array_values(self::RIYAL_FILES), [self::DIRHAM_FILE]);
    }

    /**
     * The two always-on @font-face rules mapping U+20C1 to the Saudi Riyal glyph.
     * Emitted unconditionally by _partials/riyal-font.blade.php. URLs go through
     * asset() so they are same-origin (CSP) and honour the https asset gotcha.
     */
    public static function riyalFaceCss(): string
    {
        $out = '';
        foreach (self::RIYAL_FILES as $weight => $file) {
            $out .= "@font-face{font-family:'".self::RIYAL_FAMILY."';"
                .'font-style:normal;'
                .'font-weight:'.$weight.';'
                .'font-display:swap;'
                ."src:url('".asset($file)."') format('woff2');"
                .'unicode-range:U+20C1;}';
        }

        // AED Dirham (U+20C3): same shared family, its own codepoint, one design
        // file. It MUST be declared at the SAME static weights as its U+20C1
        // siblings (400 + 700), not a `font-weight:100 900` range: when a variable
        // weight range sits beside static-weight faces in one family, Chrome's
        // per-character face selection fails to pick this face for U+20C3 and
        // paints a tofu box (verified live). Static 400/700 both resolve correctly.
        foreach (['400', '700'] as $weight) {
            $out .= "@font-face{font-family:'".self::RIYAL_FAMILY."';"
                .'font-style:normal;'
                .'font-weight:'.$weight.';'
                .'font-display:swap;'
                ."src:url('".asset(self::DIRHAM_FILE)."') format('woff2');"
                .'unicode-range:U+20C3;}';
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        if (self::$config === null) {
            $config = config('theme-fonts');
            self::$config = is_array($config) ? $config : [];
        }

        return self::$config;
    }

    /** Test seam: re-read the config. */
    public static function flush(): void
    {
        self::$config = null;
    }

    /** @return array<string,array<string,mixed>> pair id => definition */
    public static function all(): array
    {
        $pairs = self::config()['pairs'] ?? [];

        return is_array($pairs) ? $pairs : [];
    }

    /** @return array<int,string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /** The family every pair must carry an Arabic face in (default locale is ar). */
    public static function arabicFamily(): string
    {
        return (string) (self::config()['arabic_family'] ?? 'IBM Plex Sans Arabic');
    }

    /** The sentinel `fonts.pair` carries for "no pair — keep the theme's own type". */
    public static function noneValue(): string
    {
        $none = self::config()['none'] ?? 'none';

        return is_string($none) && $none !== '' ? $none : 'none';
    }

    public static function has($id): bool
    {
        return is_string($id) && $id !== '' && $id !== self::noneValue() && isset(self::all()[$id]);
    }

    /** @return array<string,mixed>|null */
    public static function get($id): ?array
    {
        return self::has($id) ? self::all()[$id] : null;
    }

    /**
     * The values `fonts.pair` accepts. The `none` sentinel first — "the theme's
     * own typography", i.e. change nothing. ThemeCustomizerService::validate()
     * checks a `select` value against the schema's own `options` list, and
     * ThemeFontPairsTest asserts the fragment's list is exactly this one, so the
     * two cannot drift.
     *
     * @return array<int,string>
     */
    public static function optionValues(): array
    {
        return array_merge([self::noneValue()], self::ids());
    }

    /** @return array<string,string> pair id => human label */
    public static function optionLabels(): array
    {
        $out = [];
        foreach (self::all() as $id => $pair) {
            $out[$id] = (string) ($pair['label'] ?? $id);
        }

        return $out;
    }

    /**
     * The two CSS font stacks for a pair, or [] when no valid pair is selected.
     *
     * @return array{heading:string,body:string}|array{}
     */
    public static function stacks($id): array
    {
        $pair = self::get($id);
        if ($pair === null) {
            return [];
        }
        $heading = self::sanitizeStack((string) ($pair['heading'] ?? ''));
        $body = self::sanitizeStack((string) ($pair['body'] ?? ''));
        if ($heading === '' || $body === '') {
            return [];
        }

        // Saudi Riyal (U+20C1) rollout — S2: the glyph family leads every stack.
        // unicode-range on its @font-face confines it to U+20C1, so this is inert
        // for all other text and only supplies the riyal glyph.
        $prefix = self::riyalStackPrefix();

        return ['heading' => $prefix.$heading, 'body' => $prefix.$body];
    }

    /**
     * The @font-face descriptors for a pair, or [] when none is selected.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function faces($id): array
    {
        $pair = self::get($id);
        $faces = is_array($pair['faces'] ?? null) ? $pair['faces'] : [];

        return array_values(array_filter($faces, 'is_array'));
    }

    /** Repo-relative woff2 paths a pair references (what the disk-existence test walks). */
    public static function files($id): array
    {
        return array_values(array_unique(array_map(
            static fn (array $face) => (string) ($face['file'] ?? ''),
            self::faces($id)
        )));
    }

    /**
     * The @font-face CSS for the selected pair. Empty string when no pair is
     * selected — the partial then emits no <style> tag at all.
     *
     * URLs go through asset() so they are same-origin and respect APP_URL /
     * the https asset gotcha the dev environment has.
     */
    public static function fontFaceCss($id): string
    {
        $out = '';
        foreach (self::faces($id) as $face) {
            $family = self::sanitizeFamily((string) ($face['family'] ?? ''));
            $file = self::sanitizeFile((string) ($face['file'] ?? ''));
            $weight = self::sanitizeWeight((string) ($face['weight'] ?? '400'));
            $style = ($face['style'] ?? 'normal') === 'italic' ? 'italic' : 'normal';
            if ($family === '' || $file === '' || $weight === '') {
                continue;
            }

            $out .= "@font-face{font-family:'".$family."';"
                .'font-style:'.$style.';'
                .'font-weight:'.$weight.';'
                .'font-display:swap;'
                ."src:url('".asset($file)."') format('woff2');";

            $range = self::sanitizeUnicodeRange((string) ($face['unicode_range'] ?? ''));
            if ($range !== '') {
                $out .= 'unicode-range:'.$range.';';
            }
            $out .= '}';
        }

        return $out;
    }

    /**
     * Saudi Riyal Symbol rollout — S11: the always-on IBM Plex Sans Arabic faces
     * for the NON-storefront surfaces (dashboard, admin, POS/KDS) whose body font
     * ("Instrument Sans" / "Inter") is Latin-only. Without an Arabic-capable face
     * in the stack, Arabic currency signs (د.إ, ر.ق, ﷼) fall back to whatever
     * Arabic font the OS happens to have — often a serif that clashes, or tofu.
     *
     * unicode-range confines the face to the Arabic blocks + presentation forms
     * (which include the ﷼ ligature at U+FDFC), so Latin text is untouched. The
     * files are the plex-arabic woff2 already committed for the storefront pairs.
     */
    public static function arabicFaceCss(): string
    {
        $range = 'U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF';
        $files = ['400' => 'assets/fonts/plex-arabic-400.woff2', '700' => 'assets/fonts/plex-arabic-700.woff2'];

        $out = '';
        foreach ($files as $weight => $file) {
            $out .= "@font-face{font-family:'".self::arabicFamily()."';"
                .'font-style:normal;'
                .'font-weight:'.$weight.';'
                .'font-display:swap;'
                ."src:url('".asset($file)."') format('woff2');"
                .'unicode-range:'.$range.';}';
        }

        return $out;
    }

    /* ─────────────────────────────── guards ─────────────────────────────── */

    /** A CSS font-family name: letters, digits, spaces, hyphens. Nothing quotable-out-of. */
    public static function sanitizeFamily(string $family): string
    {
        $family = trim($family);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]*$/', $family) ? $family : '';
    }

    /** A committed asset path: no scheme, no `..`, must end in .woff2. */
    public static function sanitizeFile(string $file): string
    {
        $file = ltrim(trim($file), '/');
        if ($file === '' || str_contains($file, '..') || ! str_ends_with($file, '.woff2')) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._/\-]+$#', $file) ? $file : '';
    }

    /** `400`, or a variable range `200 800`. */
    public static function sanitizeWeight(string $weight): string
    {
        $weight = trim(preg_replace('/\s+/', ' ', $weight) ?? '');

        return preg_match('/^[1-9]00( [1-9]00)?$/', $weight) ? $weight : '';
    }

    /** A `unicode-range` list: U+…, hex, hyphens, commas, spaces. */
    public static function sanitizeUnicodeRange(string $range): string
    {
        $range = trim(preg_replace('/\s+/', ' ', $range) ?? '');

        return preg_match('/^U\+[0-9A-Fa-f]/', $range) && preg_match('/^[U+0-9A-Fa-f,\- ]+$/', $range)
            ? $range
            : '';
    }

    /**
     * A `font-family` VALUE (a stack). Families, quoted or bare, comma-separated.
     * Anything with a `;`, `}`, `(`, `:` or `/` in it is refused whole rather than
     * repaired — a stack we cannot vouch for must not reach a declaration block.
     */
    public static function sanitizeStack(string $stack): string
    {
        $stack = trim(preg_replace('/\s+/', ' ', $stack) ?? '');

        return preg_match("/^[A-Za-z0-9 ,'\-]+$/", $stack) ? $stack : '';
    }
}
