<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;


/**
 * Per-element style overrides — the BuilderJS-style controls of the locked editor:
 * typography for text, crop / focus / effects / size for images, size and colour for
 * icons, background and spacing for sections.
 *
 * Every property is typed and range-checked; nothing free-form reaches the page. The
 * values are emitted as CSS custom properties in the element's own style ATTRIBUTE
 * (CSP allows style attributes, while <style> elements are nonce-only and a nonce
 * can't survive the full-page cache), and the static rules in
 * /assets/locked-templates/_runtime/lt-styles.css apply them — each property is
 * switched on by a token in the element's `data-lt-sty` marker list.
 */
class Styles
{
    public const KINDS = ['text', 'image', 'icon', 'section'];

    /**
     * kind => prop => [type, …args]. int/num clamp to [min, max] (num rounds to
     * the given decimals); enum/hex/ratio/text are exact.
     */
    private const SPEC = [
        'text' => [
            'size' => ['int', 8, 200],
            'weight' => ['enum', [100, 200, 300, 400, 500, 600, 700, 800, 900]],
            'color' => ['hex'],
            'color_dark' => ['hex'],
            'align' => ['enum', ['left', 'center', 'right', 'justify']],
            'line' => ['num', 0.8, 3, 2],
            'tracking' => ['num', -5, 40, 1],
        ],
        'image' => [
            'alt' => ['text', 200],
            'ratio' => ['ratio'],
            'zoom' => ['num', 1, 4, 2],
            'fx' => ['int', 0, 100],
            'fy' => ['int', 0, 100],
            'grayscale' => ['int', 0, 100],
            'sepia' => ['int', 0, 100],
            'invert' => ['int', 0, 100],
            'blur' => ['num', 0, 20, 1],
            'brightness' => ['int', 0, 200],
            'contrast' => ['int', 0, 200],
            'saturate' => ['int', 0, 300],
            'hue' => ['int', 0, 360],
            'opacity' => ['int', 0, 100],
            'width' => ['int', 10, 100],
            'align' => ['enum', ['left', 'center', 'right']],
            'radius' => ['int', 0, 200],
        ],
        'icon' => [
            'size' => ['int', 8, 200],
            'color' => ['hex'],
            'color_dark' => ['hex'],
        ],
        'section' => [
            'bg' => ['hex'],
            'bg_dark' => ['hex'],
            'pt' => ['int', 0, 400],
            'pb' => ['int', 0, 400],
        ],
    ];

    /** A value equal to its neutral is the same as no override (not stored). */
    private const NEUTRAL = [
        'image' => ['zoom' => 1, 'fx' => 50, 'fy' => 50, 'grayscale' => 0, 'sepia' => 0, 'invert' => 0, 'blur' => 0, 'brightness' => 100, 'contrast' => 100, 'saturate' => 100, 'hue' => 0, 'opacity' => 100],
    ];

    /** Image filters, in the order they are composed. */
    private const FILTERS = [
        'grayscale' => 'grayscale(%s%%)', 'sepia' => 'sepia(%s%%)', 'invert' => 'invert(%s%%)', 'blur' => 'blur(%spx)',
        'brightness' => 'brightness(%s%%)', 'contrast' => 'contrast(%s%%)', 'saturate' => 'saturate(%s%%)',
        'hue' => 'hue-rotate(%sdeg)', 'opacity' => 'opacity(%s%%)',
    ];

    /** Style kind of a slot type (links have no element style of their own). */
    public static function kindForSlot(string $type): ?string
    {
        return match ($type) {
            'text' => 'text',
            'image' => 'image',
            'icon' => 'icon',
            default => null,
        };
    }

    /** Which permission cap governs a style property. */
    public static function capFor(string $kind, string $prop): string
    {
        return match ($kind) {
            'image' => $prop === 'alt' ? 'image' : 'image_style',
            'section' => 'section_style',
            default => 'text_style',
        };
    }

    public static function known(string $kind, string $prop): bool
    {
        return isset(self::SPEC[$kind][$prop]);
    }

    /**
     * Validate + normalise one property value. null = not acceptable.
     */
    public static function cleanProp(string $kind, string $prop, mixed $value): int|float|string|null
    {
        $spec = self::SPEC[$kind][$prop] ?? null;
        if ($spec === null || $value === null || is_bool($value) || is_array($value)) {
            return null;
        }

        switch ($spec[0]) {
            case 'int':
                return is_numeric($value) ? (int) max($spec[1], min($spec[2], (int) round((float) $value))) : null;
            case 'num':
                if (! is_numeric($value)) {
                    return null;
                }
                $n = round(max($spec[1], min($spec[2], (float) $value)), $spec[3]);

                return $n == (int) $n ? (int) $n : $n;
            case 'enum':
                foreach ($spec[1] as $option) {
                    if ((string) $option === (string) $value) {
                        return $option;
                    }
                }

                return null;
            case 'hex':
                $hex = Slots::cleanColor($value);
                if ($hex !== null && strlen($hex) === 4) {
                    $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
                }

                return $hex;
            case 'ratio':
                if (is_string($value) && preg_match('/^(\d{1,2}):(\d{1,2})$/', $value, $m) && (int) $m[1] >= 1 && (int) $m[2] >= 1 && (int) $m[1] <= 50 && (int) $m[2] <= 50) {
                    return ((int) $m[1]).':'.((int) $m[2]);
                }

                return null;
            default:                              // 'text'
                return is_string($value) ? Slots::cleanText($value, $spec[1]) : null;
        }
    }

    /**
     * Keep only valid, non-neutral properties.
     *
     * @return array<string, int|float|string>
     */
    public static function clean(string $kind, mixed $props): array
    {
        $out = [];
        foreach (is_array($props) ? $props : [] as $prop => $value) {
            $clean = self::cleanProp($kind, (string) $prop, $value);
            if ($clean !== null && ! self::isNeutral($kind, (string) $prop, $clean)) {
                $out[(string) $prop] = $clean;
            }
        }
        ksort($out);

        return $out;
    }

    public static function isNeutral(string $kind, string $prop, int|float|string $value): bool
    {
        return array_key_exists($prop, self::NEUTRAL[$kind] ?? []) && (float) self::NEUTRAL[$kind][$prop] === (float) $value;
    }

    /**
     * CSS custom properties + marker tokens for one element.
     *
     * @param  array<string, mixed>  $props  (cleaned)
     * @return array{css: array<int, string>, markers: array<int, string>}
     */
    public static function vars(string $kind, array $props): array
    {
        $css = [];
        $mk = [];
        $set = function (string $var, string $value, string $marker) use (&$css, &$mk): void {
            $css[] = $var.':'.$value;
            $mk[] = $marker;
        };

        if ($kind === 'text') {
            if (isset($props['size'])) {
                $set('--lt-fs', self::fluidSize((int) $props['size']), 'fs');
            }
            if (isset($props['weight'])) {
                $set('--lt-fw', (string) $props['weight'], 'fw');
            }
            if (isset($props['color'])) {
                $set('--lt-c', (string) $props['color'], 'c');
            }
            if (isset($props['color_dark'])) {
                $set('--lt-cd', (string) $props['color_dark'], 'cd');
            }
            if (isset($props['align'])) {
                $set('--lt-ta', (string) $props['align'], 'ta');
            }
            if (isset($props['line'])) {
                $set('--lt-lh', (string) $props['line'], 'lh');
            }
            if (isset($props['tracking'])) {
                $set('--lt-ls', $props['tracking'].'px', 'ls');
            }
        } elseif ($kind === 'image') {
            $fx = (int) ($props['fx'] ?? 50);
            $fy = (int) ($props['fy'] ?? 50);
            $zoom = (float) ($props['zoom'] ?? 1);
            if (isset($props['ratio']) || $zoom > 1 || isset($props['fx']) || isset($props['fy'])) {
                $css[] = '--lt-op:'.$fx.'% '.$fy.'%';
            }
            if (isset($props['ratio'])) {
                [$w, $h] = explode(':', (string) $props['ratio']);
                $set('--lt-ar', $w.' / '.$h, 'crop');
            }
            if ($zoom > 1) {
                // Scale around the focus point, then clip back to the element box so
                // the layout footprint never changes (no wrapper element needed).
                $k = 1 - 1 / $zoom;
                $pct = fn (float $v): string => rtrim(rtrim(number_format($v * $k, 3, '.', ''), '0'), '.').'%';
                $css[] = '--lt-z:'.$zoom;
                $set('--lt-clip', 'inset('.$pct($fy).' '.$pct(100 - $fx).' '.$pct(100 - $fy).' '.$pct($fx).')', 'zoom');
            }
            $filters = [];
            foreach (self::FILTERS as $prop => $fmt) {
                if (isset($props[$prop])) {
                    $filters[] = sprintf($fmt, (string) $props[$prop]);
                }
            }
            if ($filters !== []) {
                $set('--lt-filter', implode(' ', $filters), 'filter');
            }
            if (isset($props['width'])) {
                $set('--lt-w', $props['width'].'%', 'w');
            }
            if (isset($props['align'])) {
                [$ml, $mr] = match ($props['align']) {
                    'left' => ['0', 'auto'],
                    'right' => ['auto', '0'],
                    default => ['auto', 'auto'],
                };
                $css[] = '--lt-ml:'.$ml;
                $set('--lt-mr', $mr, 'al');
            }
            if (isset($props['radius'])) {
                $set('--lt-rad', $props['radius'].'px', 'rad');
            }
        } elseif ($kind === 'icon') {
            if (isset($props['size'])) {
                $set('--lt-isz', $props['size'].'px', 'isz');
            }
            if (isset($props['color'])) {
                $set('--lt-ic', (string) $props['color'], 'ic');
            }
            if (isset($props['color_dark'])) {
                $set('--lt-icd', (string) $props['color_dark'], 'icd');
            }
        } elseif ($kind === 'section') {
            if (isset($props['bg'])) {
                $set('--lt-bg', (string) $props['bg'], 'bg');
            }
            if (isset($props['bg_dark'])) {
                $set('--lt-bgd', (string) $props['bg_dark'], 'bgd');
            }
            if (isset($props['pt'])) {
                $set('--lt-pt', $props['pt'].'px', 'pt');
            }
            if (isset($props['pb'])) {
                $set('--lt-pb', $props['pb'].'px', 'pb');
            }
        }

        return ['css' => $css, 'markers' => array_values(array_unique($mk))];
    }

    /**
     * Exactly the chosen size from 1024px wide up; narrower screens scale down with
     * the viewport (never below ~60%, never below 16px) so a big heading fits a phone.
     */
    public static function fluidSize(int $px): string
    {
        if ($px <= 20) {
            return $px.'px';
        }
        $min = max(16, (int) round($px * 0.6));
        $vw = rtrim(rtrim(number_format($px / 10.24, 3, '.', ''), '0'), '.');

        return 'clamp('.$min.'px, '.$vw.'vw, '.$px.'px)';
    }

    /** Start tags (quoted attribute values may contain '>'). */
    private const TAG_RE = '/<([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*+)\s*(\/?)>/';

    /**
     * Put the styles onto the elements that carry the matching hooks:
     * data-lt-t (text host, space-separated keys), data-lt-img, data-lt-icon and the
     * section root (data-lt-section). Tags without a styled hook are left byte-for-byte.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $byKind  kind => key => cleaned props
     * @param  array<string, mixed>  $sectionProps  cleaned props for $sectionKey
     */
    public static function apply(string $html, array $byKind, string $sectionKey = '', array $sectionProps = []): string
    {
        $vars = [];
        foreach (['text', 'image', 'icon'] as $kind) {
            foreach ($byKind[$kind] ?? [] as $key => $props) {
                if ($props !== []) {
                    $vars[$kind][(string) $key] = self::vars($kind, $props) + ['alt' => $kind === 'image' && array_key_exists('alt', $props) ? (string) $props['alt'] : null];
                }
            }
        }
        $section = $sectionProps !== [] ? self::vars('section', $sectionProps) : null;
        if ($vars === [] && $section === null) {
            return $html;
        }

        return (string) preg_replace_callback(self::TAG_RE, static function (array $m) use ($vars, $section, $sectionKey): string {
            $attrs = $m[2];
            if (! str_contains($attrs, 'data-lt-')) {
                return $m[0];
            }
            $css = [];
            $markers = [];
            $alt = null;
            $take = static function (array $v) use (&$css, &$markers): void {
                $css = array_merge($css, $v['css']);
                $markers = array_merge($markers, $v['markers']);
            };

            if (isset($vars['text']) && preg_match('/\sdata-lt-t="([^"]*)"/', $attrs, $a)) {
                foreach (preg_split('/\s+/', trim($a[1])) ?: [] as $k) {
                    if (isset($vars['text'][$k])) {
                        $take($vars['text'][$k]);
                    }
                }
            }
            if (isset($vars['image']) && preg_match('/\sdata-lt-img="([^"]*)"/', $attrs, $a) && isset($vars['image'][$a[1]])) {
                $take($vars['image'][$a[1]]);
                $alt = $vars['image'][$a[1]]['alt'];
            }
            if (isset($vars['icon']) && preg_match('/\sdata-lt-icon="([^"]*)"/', $attrs, $a) && isset($vars['icon'][$a[1]])) {
                $take($vars['icon'][$a[1]]);
            }
            if ($section !== null && preg_match('/\sdata-lt-section="([^"]*)"/', $attrs, $a) && $a[1] === $sectionKey) {
                $take($section);
            }
            if ($css === [] && $alt === null) {
                return $m[0];
            }

            if ($alt !== null) {
                $altAttr = 'alt="'.self::attr($alt).'"';
                $attrs = preg_match('/\salt\s*=\s*"[^"]*"/', $attrs)
                    ? (string) preg_replace('/\salt\s*=\s*"[^"]*"/', ' '.addcslashes($altAttr, '\\$'), $attrs, 1)
                    : $attrs.' '.$altAttr;
            }
            if ($css !== []) {
                $decl = self::attr(implode(';', $css));
                if (preg_match('/\sstyle\s*=\s*"([^"]*)"/', $attrs, $s)) {
                    $merged = rtrim(trim($s[1]), ';');
                    $merged = ($merged === '' ? '' : $merged.';').$decl;
                    $attrs = (string) preg_replace('/\sstyle\s*=\s*"[^"]*"/', ' style="'.addcslashes($merged, '\\$').'"', $attrs, 1);
                } else {
                    $attrs .= ' style="'.$decl.'"';
                }
                $attrs .= ' data-lt-sty="'.implode(' ', array_unique($markers)).'"';
            }

            return '<'.$m[1].$attrs.($m[3] !== '' ? ' /' : '').'>';
        }, $html);
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
