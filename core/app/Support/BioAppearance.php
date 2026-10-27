<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Services\Theme\Tokens as ThemeTokens;

/**
 * bio-t15 Lane B (SPEC §3–§6): the ONE place that turns a store's raw,
 * owner-submitted `bio_settings.appearance` JSON into (a) a safe CSS custom-
 * property token map merged over the preset's own tokens, (b) the curated
 * Google-Fonts family list to append to the page, and (c) the inline
 * background-image style for a PRO image background.
 *
 * SECURITY (SPEC §3 "Sanitizer rules" — do not skip): `bio_settings` is
 * saved verbatim through the EXISTING `biolink.settings.save` endpoint (a
 * deep-merge write, see BioLinkService::saveSettings) with NO validation of
 * the `appearance` sub-tree — so everything arriving at {@see self::sanitize()}
 * must be treated as attacker-controlled. This class is the ONLY gate: every
 * value is whitelisted-key, enum-checked, regex-checked or numeric-clamped
 * before it can become a CSS token or an inline `style=""` attribute value.
 * Raw user text NEVER reaches a token untouched. PRO-only keys are stripped
 * unless `$planFlags` says the store's plan covers them — the UI lock (Lane
 * C) is cosmetic; THIS is the real gate (SPEC §8).
 */
class BioAppearance
{
    /** SPEC §4 — the frozen curated list. Change only via a SPEC contract change. */
    public const CURATED_FONTS = [
        'Inter' => ['weights' => [400, 600, 700], 'stack' => 'sans-serif', 'rtl' => false],
        'Poppins' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Montserrat' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Playfair Display' => ['weights' => [400, 700, 900], 'stack' => 'serif', 'rtl' => false],
        'Lora' => ['weights' => [400, 600, 700], 'stack' => 'serif', 'rtl' => false],
        'Merriweather' => ['weights' => [400, 700, 900], 'stack' => 'serif', 'rtl' => false],
        'Space Grotesk' => ['weights' => [400, 600, 700], 'stack' => 'sans-serif', 'rtl' => false],
        'DM Sans' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'DM Serif Display' => ['weights' => [400], 'stack' => 'serif', 'rtl' => false],
        'Manrope' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Nunito' => ['weights' => [400, 700, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Work Sans' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Fraunces' => ['weights' => [400, 600, 900], 'stack' => 'serif', 'rtl' => false],
        'Bricolage Grotesque' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Sora' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Rubik' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => false],
        'Cairo' => ['weights' => [400, 600, 800], 'stack' => 'sans-serif', 'rtl' => true],
        'Tajawal' => ['weights' => [400, 500, 700], 'stack' => 'sans-serif', 'rtl' => true],
        'IBM Plex Sans' => ['weights' => [400, 600, 700], 'stack' => 'sans-serif', 'rtl' => false],
        'Bebas Neue' => ['weights' => [400], 'stack' => 'sans-serif', 'rtl' => false],
    ];

    public const BTN_STYLES = ['fill', 'outline', 'soft'];

    public const BTN_RADII = ['sharp', 'round', 'pill'];

    public const BTN_ALIGNS = ['left', 'center'];

    public const AVATAR_SHAPES = ['circle', 'rounded', 'square'];

    public const BG_TYPES = ['preset', 'solid', 'gradient', 'pattern', 'image', 'video'];

    public const GRADIENT_TYPES = ['linear', 'radial'];

    public const PATTERN_SLUGS = ['kraft', 'dots', 'arches', 'grid', 'waves', 'confetti', 'zellige', 'topo'];

    /** SPEC §9 — deferred: accepted into the schema, never acted on. No provisioning. */
    public const DEFERRED_BG_TYPE = 'video';

    private const COLOR_RE = '/^#[0-9a-fA-F]{6}$/';

    /** WCAG AA for normal-size text (shared with the storefront customizer's guard). */
    public const CONTRAST_AA = 4.5;

    /**
     * SPEC §5 — 8 tileable SVG data-URI patterns, each `[base color, svg markup]`.
     * The svg is percent-encoded (never raw-embedded) because the master layout
     * strips `<>{};` and newlines out of every token VALUE before emitting the
     * one nonced `<style>` (bio.layout — CSP hardening) — a literal `<svg>...`
     * would be mangled into garbage. An explicit pixel width/height on the root
     * makes the background tile at that natural size (no `background-size` needed).
     */
    private const PATTERNS = [
        'kraft' => [
            '#e8d9bd',
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><rect width="48" height="48" fill="#e8d9bd"/><path d="M-4 8 L52 44 M-4 24 L52 60 M-4 -8 L52 28 M12 -4 L68 32" stroke="#d3bd93" stroke-width="1.4" opacity=".55"/></svg>',
        ],
        'dots' => [
            '#f6f5f2',
            '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"><rect width="22" height="22" fill="#f6f5f2"/><circle cx="11" cy="11" r="2" fill="#d7d3c8"/></svg>',
        ],
        'arches' => [
            '#f2e9de',
            '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="34"><rect width="60" height="34" fill="#f2e9de"/><path d="M0 34 A15 15 0 0 1 30 34 A15 15 0 0 1 60 34" fill="none" stroke="#c9a878" stroke-width="2"/></svg>',
        ],
        'grid' => [
            '#fafaf9',
            '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26"><rect width="26" height="26" fill="#fafaf9"/><path d="M26 0H0V26" fill="none" stroke="#e4e2dc" stroke-width="1"/></svg>',
        ],
        'waves' => [
            '#eef3f2',
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="18"><rect width="64" height="18" fill="#eef3f2"/><path d="M0 9 Q16 -3 32 9 T64 9" fill="none" stroke="#9fc2ba" stroke-width="1.6"/></svg>',
        ],
        'confetti' => [
            '#fff8ec',
            '<svg xmlns="http://www.w3.org/2000/svg" width="54" height="54"><rect width="54" height="54" fill="#fff8ec"/><circle cx="8" cy="10" r="2.4" fill="#e59a7b"/><rect x="30" y="6" width="5" height="5" fill="#7fb6a6" transform="rotate(20 32 8)"/><circle cx="42" cy="34" r="2" fill="#e3c15a"/><rect x="12" y="36" width="4" height="4" fill="#8896d6" transform="rotate(-15 14 38)"/></svg>',
        ],
        'zellige' => [
            '#f1ddd0',
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><rect width="48" height="48" fill="#f1ddd0"/><path d="M24 4 L34 14 L44 24 L34 34 L24 44 L14 34 L4 24 L14 14 Z" fill="none" stroke="#c17a56" stroke-width="1.5"/><path d="M24 14 L34 24 L24 34 L14 24 Z" fill="none" stroke="#c17a56" stroke-width="1.2"/></svg>',
        ],
        'topo' => [
            '#eef1e8',
            '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#eef1e8"/><circle cx="40" cy="40" r="34" fill="none" stroke="#a9b99a" stroke-width="1.2"/><circle cx="40" cy="40" r="22" fill="none" stroke="#a9b99a" stroke-width="1.2"/><circle cx="40" cy="40" r="10" fill="none" stroke="#a9b99a" stroke-width="1.2"/></svg>',
        ],
    ];

    /**
     * The whitelisted, validated, plan-gated copy of a raw `appearance` blob.
     * Every other public method here calls this first — it is the single
     * sanitize pass; nothing downstream re-trusts raw input.
     *
     * @param  array<string,mixed>  $raw
     * @param  array<string,bool>  $planFlags  e.g. ['bio_pro' => bool, 'bio_analytics_pro' => bool]
     * @return array<string,mixed>
     */
    public static function sanitize(array $raw, array $planFlags = []): array
    {
        $proAllowed = ! empty($planFlags['bio_pro']);

        $out = [];

        foreach (['accent', 'accent_ink', 'ink', 'muted', 'btn_bg', 'btn_fg', 'bg_solid'] as $key) {
            $v = self::color($raw[$key] ?? null);
            if ($v !== null) {
                $out[$key] = $v;
            }
        }

        $out['btn_style'] = self::enum($raw['btn_style'] ?? null, self::BTN_STYLES);
        $out['btn_radius'] = self::enum($raw['btn_radius'] ?? null, self::BTN_RADII);
        $out['btn_align'] = self::enum($raw['btn_align'] ?? null, self::BTN_ALIGNS);
        $out['show_icons'] = self::bool($raw['show_icons'] ?? null);
        $out['show_arrows'] = self::bool($raw['show_arrows'] ?? null);
        $out['avatar_shape'] = self::enum($raw['avatar_shape'] ?? null, self::AVATAR_SHAPES);
        $out['show_cover'] = self::bool($raw['show_cover'] ?? null);

        // -- profile header (drives .bio-name / .bio-profile) --
        // The visible display name, tagline and avatar. FREE (not PRO-gated):
        // basic identity, not a premium surface. display_name/tagline are plain
        // text (Blade-escaped where they render); avatar_image is a bare library
        // filename (same contract as cover_image/bg_image), resolved for THIS
        // store in BioPublicController::bioProfile(). Before this there was no
        // field for either — the page always fell back to the store's own name
        // and logo, with no way to override.
        $out['display_name'] = self::text($raw['display_name'] ?? null, 100);
        $out['tagline'] = self::text($raw['tagline'] ?? null, 160);
        $out['avatar_image'] = self::filename($raw['avatar_image'] ?? null);

        // -- background --
        $bgType = self::enum($raw['bg_type'] ?? null, self::BG_TYPES);
        if ($bgType === 'image' && ! $proAllowed) {
            // SPEC §8: image background is PRO. Server-side strip, not a UI-only lock.
            $bgType = null;
        }
        if ($bgType === self::DEFERRED_BG_TYPE) {
            // SPEC §9: video background is a deferred stub. Gate off, fall back
            // to whatever the preset already renders (no override emitted).
            $bgType = null;
        }
        $out['bg_type'] = $bgType;

        if (is_array($raw['bg_gradient'] ?? null)) {
            $g = $raw['bg_gradient'];
            $out['bg_gradient'] = [
                'from' => self::color($g['from'] ?? null) ?? '#0f7d5a',
                'to' => self::color($g['to'] ?? null) ?? '#123c32',
                'angle' => self::clampInt($g['angle'] ?? 175, 0, 360, 175),
                'type' => self::enum($g['type'] ?? null, self::GRADIENT_TYPES) ?? 'linear',
            ];
        }

        $out['bg_pattern'] = self::enum($raw['bg_pattern'] ?? null, self::PATTERN_SLUGS);
        $out['bg_scrim'] = self::clampInt($raw['bg_scrim'] ?? 0, 0, 100, 0);

        // bg_image round-trips as a bare library filename (the media-library /
        // image-studio picker stores the file and hands back its filename);
        // resolved for THIS store in backgroundImageStyle(), never a raw URL. PRO.
        $bgImage = self::filename($raw['bg_image'] ?? null);
        $out['bg_image'] = ($bgImage !== null && $proAllowed) ? $bgImage : null;

        // Deferred stubs (SPEC §9): the key round-trips (so the editor can store
        // the owner's intent) but is NEVER read by toTokens()/selectedFonts() —
        // there is no rendering path for either, by design, until the owner
        // infra follow-up lands.
        $out['bg_video'] = self::filename($raw['bg_video'] ?? null);
        $out['font_upload'] = null; // no upload pipeline exists — always dropped
        $out['custom_domain'] = is_string($raw['custom_domain'] ?? null)
            ? trim(mb_substr((string) $raw['custom_domain'], 0, 253))
            : null;

        // -- fonts (SPEC §8: "font pairing beyond N" is PRO) --
        $out['font_display'] = self::font($raw['font_display'] ?? null);
        $out['font_body'] = self::font($raw['font_body'] ?? null);
        // N = 1: a free store may customize ONE curated family (display OR
        // body). Pairing two DIFFERENT curated families is the PRO surface —
        // without bio_pro, drop the body override and let the preset's own
        // body font stand, keeping only the display customization.
        if (! $proAllowed && $out['font_display'] !== null && $out['font_body'] !== null
            && $out['font_display'] !== $out['font_body']) {
            $out['font_body'] = null;
        }

        // -- header hide toggles --
        $hide = is_array($raw['hide'] ?? null) ? $raw['hide'] : [];
        $out['hide'] = [
            'title' => self::bool($hide['title'] ?? null) ?? false,
            'tagline' => self::bool($hide['tagline'] ?? null) ?? false,
            'status' => self::bool($hide['status'] ?? null) ?? false,
            'verified' => self::bool($hide['verified'] ?? null) ?? false,
        ];

        $out['cover_image'] = self::filename($raw['cover_image'] ?? null);

        // -- OG / SEO metadata (SPEC §6) --
        $og = is_array($raw['og'] ?? null) ? $raw['og'] : [];
        $out['og'] = [
            'title' => is_string($og['title'] ?? null) ? trim(mb_substr($og['title'], 0, 70)) : '',
            'description' => is_string($og['description'] ?? null) ? trim(mb_substr($og['description'], 0, 200)) : '',
            'image' => self::filename($og['image'] ?? null),
        ];

        // -- contrast guard (SPEC §10.3): nudge an illegible ink toward the
        // effective background rather than silently ship unreadable text.
        if (isset($out['ink'])) {
            $effectiveBg = self::effectiveSolidBg($out);
            if ($effectiveBg !== null && ! self::passesAA($out['ink'], $effectiveBg)) {
                $out['ink'] = ThemeTokens::readableInk($effectiveBg);
            }
        }

        return $out;
    }

    /**
     * SPEC §3 token-mapping table → the CSS custom-property map merged over
     * the preset's own tokens in BioPublicController (~line 102). Never
     * includes a bare `--bg` entry for `bg_type:image` — that path is an
     * inline `style=""` on `.bio-body` instead (see backgroundImageStyle()).
     *
     * @param  array<string,mixed>  $appearance
     * @param  array<string,bool>  $planFlags
     * @return array<string,string>
     */
    public static function toTokens(array $appearance, array $planFlags = []): array
    {
        $a = self::sanitize($appearance, $planFlags);
        $tokens = [];

        foreach ([
            'accent' => '--accent', 'accent_ink' => '--accent-ink',
            'ink' => '--ink', 'muted' => '--muted',
        ] as $key => $token) {
            if (isset($a[$key])) {
                $tokens[$token] = $a[$key];
            }
        }

        if (isset($a['btn_bg'])) {
            $tokens['--btn-bg'] = $a['btn_bg'];
            $tokens['--card'] = $a['btn_bg'];
        }
        if (isset($a['btn_fg'])) {
            $tokens['--btn-fg'] = $a['btn_fg'];
        }

        switch ($a['btn_style'] ?? null) {
            case 'fill':
                $tokens['--btn-border-width'] = '0px';
                $tokens['--btn-border'] = 'transparent';
                $tokens['--shadow'] = '0 2px 10px rgba(0,0,0,.10)';
                break;
            case 'outline':
                $tokens['--btn-border-width'] = '1.5px';
                $tokens['--btn-border'] = 'var(--accent,#0f7d5a)';
                $tokens['--shadow'] = 'none';
                break;
            case 'soft':
                $tokens['--btn-border-width'] = '0px';
                $tokens['--btn-border'] = 'transparent';
                $tokens['--shadow'] = 'none';
                break;
        }

        $radiusMap = ['sharp' => '0px', 'round' => '14px', 'pill' => '999px'];
        if (isset($a['btn_radius'], $radiusMap[$a['btn_radius']])) {
            $tokens['--radius'] = $radiusMap[$a['btn_radius']];
        }

        if (($a['btn_align'] ?? null) === 'center') {
            $tokens['--link-justify'] = 'center';
            $tokens['--link-text-align'] = 'center';
        } elseif (($a['btn_align'] ?? null) === 'left') {
            $tokens['--link-justify'] = 'flex-start';
            $tokens['--link-text-align'] = 'start';
        }

        if (isset($a['show_icons'])) {
            $tokens['--icon-display'] = $a['show_icons'] ? 'grid' : 'none';
        }
        if (isset($a['show_arrows'])) {
            $tokens['--arrow-display'] = $a['show_arrows'] ? 'block' : 'none';
        }

        $avatarMap = ['circle' => '50%', 'rounded' => '18px', 'square' => '0px'];
        if (isset($a['avatar_shape'], $avatarMap[$a['avatar_shape']])) {
            $tokens['--avatar-radius'] = $avatarMap[$a['avatar_shape']];
        }

        switch ($a['bg_type'] ?? null) {
            case 'solid':
                if (isset($a['bg_solid'])) {
                    $tokens['--bg'] = $a['bg_solid'];
                }
                break;
            case 'gradient':
                if (isset($a['bg_gradient'])) {
                    $g = $a['bg_gradient'];
                    $tokens['--bg'] = $g['type'] === 'radial'
                        ? 'radial-gradient(circle at center, '.$g['from'].', '.$g['to'].')'
                        : 'linear-gradient('.$g['angle'].'deg, '.$g['from'].', '.$g['to'].')';
                }
                break;
            case 'pattern':
                $uri = self::patternCssValue($a['bg_pattern'] ?? null);
                if ($uri !== null) {
                    $tokens['--bg'] = $uri;
                }
                break;
            case 'image':
                // NOT a token — inline style on .bio-body (backgroundImageStyle()).
                // --bg-scrim still rides the token layer so appearance.css's
                // overlay rule (loaded before the token block) can read it.
                $tokens['--bg-scrim'] = (string) round(($a['bg_scrim'] ?? 0) / 100, 2);
                break;
        }

        if (isset($a['font_display'])) {
            $tokens['--font-display'] = self::fontFamilyCss((string) $a['font_display']);
        }
        if (isset($a['font_body'])) {
            $tokens['--font-body'] = self::fontFamilyCss((string) $a['font_body']);
        }

        return $tokens;
    }

    /**
     * SPEC §4: the chosen curated families, appended to the preset's own
     * `fonts[]` so the master layout's Google-Fonts `<link>` builder
     * (bio/layout.blade.php ~line 64) loads them. De-dupes by family name —
     * call with the preset's existing fonts already known to the caller.
     *
     * @param  array<string,mixed>  $appearance
     * @param  array<int,array{family:string,weights?:array<int,int>}>  $existing
     * @param  array<string,bool>  $planFlags
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public static function selectedFonts(array $appearance, array $existing = [], array $planFlags = []): array
    {
        $a = self::sanitize($appearance, $planFlags);
        $known = [];
        foreach ($existing as $f) {
            $fam = trim((string) $f['family']);
            if ($fam !== '') {
                $known[$fam] = true;
            }
        }

        $add = [];
        foreach (['font_display', 'font_body'] as $key) {
            $fam = $a[$key] ?? null;
            if ($fam === null || isset($known[$fam])) {
                continue;
            }
            $known[$fam] = true;
            $add[] = ['family' => $fam, 'weights' => self::CURATED_FONTS[$fam]['weights']];
        }

        return $add;
    }

    /**
     * SPEC §5 image background (PRO): resolves `bg_image` through `media_url()`
     * for THIS post only (never an arbitrary URL/path — the sanitizer already
     * reduced it to a bare filename) and returns the inline `style=""` value
     * for `.bio-body`, or '' when there is nothing to render (wrong bg_type,
     * no image, or the store's plan does not carry `bio_pro`).
     *
     * @param  array<string,mixed>  $appearance
     * @param  array<string,bool>  $planFlags
     */
    public static function backgroundImageStyle(array $appearance, int $storeId, array $planFlags = []): string
    {
        $a = self::sanitize($appearance, $planFlags);
        if (($a['bg_type'] ?? null) !== 'image' || empty($a['bg_image'])) {
            return '';
        }

        // The media-library / image-studio picker stores the file under the
        // store's media path (storage/menu — see MediaAsset::getUrlAttribute)
        // and the sanitizer kept only its bare filename; resolve it for THIS
        // store. addslashes hardens the inline url('…') (filename() already
        // rejected quotes and traversal).
        $url = media_url('storage/menu/', (string) $a['bg_image'], $storeId);

        return "background-image:url('".addslashes($url)."')";
    }

    /**
     * SPEC §6 OG/SEO: the sanitized `og` block plus the post/profile fallback
     * chain (`og.title||site title||post title`, `og.description`, `og:image`
     * → cover → logo → platform default). Escaping already-`e()`-safe output
     * is the CALLER's job (Blade escapes on output); this just resolves values.
     *
     * @param  array<string,mixed>  $appearance
     * @param  array<string,bool>  $planFlags
     * @return array{title:string,description:string,image:string}
     */
    public static function ogMeta(array $appearance, string $fallbackTitle, string $fallbackDescription, string $fallbackImage, array $planFlags = []): array
    {
        $a = self::sanitize($appearance, $planFlags);
        $og = $a['og'] ?? ['title' => '', 'description' => '', 'image' => null];

        return [
            'title' => $og['title'] !== '' ? $og['title'] : $fallbackTitle,
            'description' => $og['description'] !== '' ? $og['description'] : $fallbackDescription,
            'image' => $og['image'] !== null ? $og['image'] : $fallbackImage,
        ];
    }

    // ── contrast guard (SPEC §10.3) ─────────────────────────────────────────

    /** WCAG contrast ratio between two `#rrggbb` colors. Delegates to the customizer's own math — no duplicate implementation. */
    public static function contrastRatio(string $hexA, string $hexB): float
    {
        return ThemeTokens::contrastRatio($hexA, $hexB);
    }

    /** Does $ink read at WCAG AA (>= 4.5:1) on $bg? */
    public static function passesAA(string $ink, string $bg): bool
    {
        return self::contrastRatio($ink, $bg) >= self::CONTRAST_AA;
    }

    /**
     * A single representative solid color for the (already-sanitized) background,
     * or null when one can't be derived (gradient/pattern/image/preset).
     *
     * @param  array<string,mixed>  $sanitized
     */
    private static function effectiveSolidBg(array $sanitized): ?string
    {
        return match ($sanitized['bg_type'] ?? null) {
            'solid' => $sanitized['bg_solid'] ?? null,
            'gradient' => $sanitized['bg_gradient']['from'] ?? null,
            default => null,
        };
    }

    // ── validators ───────────────────────────────────────────────────────

    private static function color(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return preg_match(self::COLOR_RE, $v) === 1 ? strtolower($v) : null;
    }

    /** @param array<int,string> $allowed */
    private static function enum(mixed $v, array $allowed): ?string
    {
        return (is_string($v) && in_array($v, $allowed, true)) ? $v : null;
    }

    private static function bool(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    private static function clampInt(mixed $v, int $min, int $max, int $default): int
    {
        if (! is_numeric($v)) {
            return $default;
        }

        return (int) max($min, min($max, (int) $v));
    }

    /** A bare filename only — no path traversal, no scheme, no query. Matches the existing bio block image convention (see resources/views/bio/_blocks/image.blade.php). */
    private static function filename(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || Str::contains($v, ['..', '/', '\\', ':', '?', '#'])) {
            return null;
        }

        return mb_substr($v, 0, 190);
    }

    /** A single-line header text value — control chars stripped, trimmed, length-capped. Blade escapes it on output. */
    private static function text(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }
        $v = preg_replace('/[\x00-\x1f\x7f]/u', '', $v) ?? '';

        return trim(mb_substr($v, 0, $max));
    }

    private static function font(mixed $v): ?string
    {
        return (is_string($v) && isset(self::CURATED_FONTS[$v])) ? $v : null;
    }

    private static function fontFamilyCss(string $family): string
    {
        $stack = self::CURATED_FONTS[$family]['stack'] ?? 'sans-serif';

        return '"'.str_replace('"', '', $family).'", '.$stack;
    }

    /** `url('data:image/svg+xml,...') #base` — CSP-safe (percent-encoded, zero network). */
    private static function patternCssValue(?string $slug): ?string
    {
        if ($slug === null || ! isset(self::PATTERNS[$slug])) {
            return null;
        }
        [$base, $svg] = self::PATTERNS[$slug];

        return "url('data:image/svg+xml,".rawurlencode($svg)."') ".$base;
    }
}
