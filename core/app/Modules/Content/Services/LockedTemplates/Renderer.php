<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;

use Juwri\Modules\Content\Models\LockedTemplateVersion;
use Juwri\Modules\Content\Services\Builder\BuilderForms;
use Juwri\Modules\Content\Services\Builder\MergeTags;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;

/**
 * Renders a locked-template page: trusted template HTML with the user's validated
 * slot values filled in.
 *
 * Pure string substitution — no DOM round-trip — so the trusted markup (inline SVG
 * with camel-cased attributes, etc.) is emitted byte-for-byte. Safety comes from the
 * placeholder contract enforced by Manifest::validate() (placeholders only in text
 * or quoted non-JS/CSS attributes, URL attributes only fed by link/image slots) plus
 * Slots::clean() + Slots::render() on every value.
 *
 * Scripts/styles are external files served from /assets (no inline JS), so pages stay
 * CSP-friendly and safe to full-page cache (no per-request nonce baked in).
 */
class Renderer
{
    /**
     * @param  array<string, mixed>|null  $content
     * @param  array{lang?:string, tokens?:array<string,string>, form?:array<string,mixed>|null, editor?:bool}  $opts
     * @return array{styles: array<int,string>, head_scripts: array<int,string>, scripts: array<int,string>, html_attrs: string, body_class: string, body: string, dir: string}
     */
    public static function render(LockedTemplateVersion $version, ?array $content, array $opts = []): array
    {
        $m = $version->manifestData();
        $c = Content::normalize($content);
        if (self::usesLibrary($m, $c)) {
            $m = $version->manifestWithLibrary();
        }
        $base = $version->assetsUrl();
        $editor = (bool) ($opts['editor'] ?? false);
        // Direction follows the page CONTENT (the template's language, or the user's
        // setting once they write right-to-left), not the visitor's interface locale.
        $dir = $c['settings']['dir'] ?? ($m['settings']['dir']['default'] ?? 'ltr');
        $dir = $dir === 'rtl' ? 'rtl' : 'ltr';
        // Section copies become virtual sections; new items bring re-keyed slots.
        $em = Items::withCopies($m, $c);
        $slots = Items::slotIndex($em, $c);
        $rich = Manifest::richTextKeys($em);
        foreach ($slots as $key => $slot) {
            if (isset($slot['_origin']) && isset($rich[$slot['_origin']])) {
                $rich[$key] = true;
            }
        }
        $tokens = $editor ? array_keys((array) ($opts['tokens'] ?? [])) : [];

        // Element styles grouped by kind (re-validated here: the stored row is data).
        $byKind = [];
        foreach ($c['styles'] as $key => $props) {
            $kind = isset($slots[$key]) ? Styles::kindForSlot((string) ($slots[$key]['type'] ?? '')) : null;
            if ($kind !== null && ($clean = Styles::clean($kind, $props)) !== []) {
                $byKind[$kind][$key] = $clean;
            }
        }

        $formFields = ! empty($opts['form']) ? BuilderForms::fieldsHtml($opts['form']) : '';
        $formAction = htmlspecialchars(route('builder.form.submit'), ENT_QUOTES, 'UTF-8');

        $regions = ['before' => '', 'main' => '', 'after' => ''];
        foreach (Content::orderedSections($c, $em) as $section) {
            if (! empty($section['_hidden'])) {
                continue;
            }
            $key = (string) $section['key'];
            $html = self::sectionHook(str_replace(
                ['{{asset}}', '{{form:action}}', '{{form:fields}}'],
                [$base, $formAction, $formFields],
                Items::expand((string) ($section['html'] ?? ''), $section, $c, $editor)
            ), $key);
            $html = self::fillSlots($html, $slots, $c['slots'], $base, $editor, $rich, $tokens);
            $html = Styles::apply($html, $byKind, $key, Styles::clean('section', $c['section_styles'][$key] ?? []));
            $region = in_array($section['region'] ?? 'main', ['before', 'after'], true) ? $section['region'] : 'main';
            $regions[$region] .= $html."\n";
        }

        $mainClass = trim((string) ($m['main_class'] ?? ''));
        $body = $regions['before']
            .'<main'.($mainClass !== '' ? ' class="'.self::attr($mainClass).'"' : '').'>'."\n".$regions['main'].'</main>'."\n"
            .$regions['after'];

        if (! empty($opts['tokens'])) {
            $body = MergeTags::apply($body, $opts['tokens']);
        }

        // <html> attributes: language/direction, light|dark, palette overrides.
        $theme = $c['settings']['theme'] ?? ($m['settings']['theme']['default'] ?? 'light');
        $classes = trim(((string) ($m['html_class'] ?? '')).' '.($theme === 'dark' ? 'dark' : ''));
        $attrs = [
            'lang' => (string) ($opts['lang'] ?? app()->getLocale()),
            'dir' => $dir,
            'class' => $classes,
            // HTML-edition templates run their own theme switcher; this pins it to the
            // page's setting and hides the vendor's floating light/dark toggle.
            'data-force-theme' => $theme === 'dark' ? 'dark' : 'light',
            'style' => self::paletteStyle($m, $c).($theme === 'dark' ? 'color-scheme:dark;' : 'color-scheme:light;'),
            'data-lt-thanks' => ___('Thanks! We will be in touch.'),
            'data-lt-failed' => ___('Could not send. Please try again.'),
        ];
        if ($editor) {
            $attrs['data-lt-editor'] = '';
        }

        $styles = [];
        foreach (array_merge((array) ($m['styles'] ?? []), (array) ($m['runtime_css'] ?? []), ['/assets/locked-templates/_runtime/lt-styles.css']) as $entry) {
            if ($url = self::assetUrl((string) $entry, $version)) {
                $styles[] = $url;
            }
        }
        $headScripts = [];
        foreach ((array) ($m['head_scripts'] ?? []) as $entry) {
            if ($url = self::assetUrl((string) $entry, $version)) {
                $headScripts[] = $url;
            }
        }
        $scripts = [];
        foreach ((array) ($m['scripts'] ?? []) as $entry) {
            if ($url = self::assetUrl((string) $entry, $version)) {
                $scripts[] = $url;
            }
        }
        if ($editor && ($bridge = self::assetUrl('/assets/locked-templates/_runtime/editor-bridge.js', $version))) {
            $scripts[] = $bridge;
        }

        $attrString = '';
        foreach ($attrs as $name => $value) {
            if ($value === '' && $name !== 'data-lt-editor') {
                continue;
            }
            $attrString .= ' '.$name.($value === '' ? '' : '="'.self::attr((string) $value).'"');
        }

        return [
            'styles' => $styles,
            'head_scripts' => $headScripts,
            'scripts' => $scripts,
            'html_attrs' => ltrim($attrString),
            'body_class' => (string) ($m['body_class'] ?? ''),
            'body' => $body,
            'dir' => $dir,
        ];
    }

    /**
     * Does the page place a library section (a copy whose source isn't one of the
     * manifest's own sections)? Only then is the large library decoded.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $c
     */
    public static function usesLibrary(array $m, array $c): bool
    {
        $own = [];
        foreach (Manifest::sections($m) as $s) {
            $own[(string) ($s['key'] ?? '')] = true;
        }
        return array_any((array) ($c['sections'] ?? []), fn($entry) => is_array($entry) && preg_match(Items::COPY_RE, (string) ($entry['key'] ?? ''), $mm) && ! isset($own[$mm[1]]));
    }

    /**
     * Replace every {{slot:key}} with its escaped value (user override if valid,
     * else the template default).
     *
     * Editor mode wraps each text-context value in an inert, style-neutral span
     * (`all:unset`) the preview bridge makes contenteditable — so users type straight
     * into the page — and shows merge tags as atomic chips so an edit keeps the token.
     * Icon slots render a library SVG (by NAME — never user markup) or the template's
     * original icon, inside a `display:contents` hook span. Rich text slots output
     * RichText::clean() (re-sanitised on every render).
     *
     * @param  array<string, array<string, mixed>>  $slots
     * @param  array<string, string>  $values
     * @param  array<string, true>  $rich
     * @param  array<int, string>  $tokens  merge-tag names (editor chips)
     */
    private static function fillSlots(string $html, array $slots, array $values, string $base, bool $editor, array $rich = [], array $tokens = []): string
    {
        return (string) preg_replace_callback(
            Slots::PLACEHOLDER_RE,
            static function (array $mm) use ($html, $slots, $values, $base, $editor, $rich, $tokens): string {
                $key = $mm[1][0];
                $slot = $slots[$key] ?? null;
                if ($slot === null) {
                    return '';
                }
                $type = (string) ($slot['type'] ?? 'text');

                if ($type === 'icon') {
                    $name = isset($values[$key]) ? Slots::clean($slot, $values[$key]) : '';
                    $svg = ($name !== null && $name !== '')
                        ? Icons::svg($name, (string) ($slot['icon_class'] ?? ''), (string) ($slot['icon_width'] ?? ''), (string) ($slot['icon_height'] ?? ''))
                        : null;

                    return '<span class="lt-icon" data-lt-icon="'.self::attr($key).'">'.($svg ?? (string) ($slot['default_html'] ?? '')).'</span>';
                }

                $isRich = isset($rich[$key]);
                $out = null;
                if ($isRich && isset($values[$key])) {
                    $out = RichText::clean($values[$key], (int) ($slot['max'] ?? 300), true, ! empty($slot['multiline']));
                }
                if ($out === null) {
                    $value = ! $isRich && isset($values[$key]) ? Slots::clean($slot, $values[$key]) : null;
                    if ($value === null) {
                        $value = self::resolveDefault((string) ($slot['default'] ?? ''), $base);
                    }
                    $out = Slots::render($slot, $value);
                }

                if ($editor && $type === 'text' && ! self::insideTag($html, (int) $mm[0][1])) {
                    // Chips go in text only — never inside a (rich) link's attributes.
                    $parts = preg_split('/(<[^>]*>)/', $out, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$out];
                    foreach ($parts as $i => $part) {
                        if ($i % 2 === 0) {
                            foreach ($tokens as $token) {
                                $part = str_replace('{{'.$token.'}}', '<span data-lt-token="'.self::attr($token).'" contenteditable="false">{{'.$token.'}}</span>', $part);
                            }
                            $parts[$i] = $part;
                        }
                    }
                    $out = implode('', $parts);
                    $out = '<span data-lt-edit="'.self::attr($key).'"'.($isRich ? ' data-lt-rich' : '').' style="all:unset">'.$out.'</span>';
                }

                return $out;
            },
            $html,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );
    }

    /**
     * A slot default is trusted template data: `{{asset}}` resolves to the version's
     * asset base, and `{{qr:site}}` to a QR code (SVG data URI) of the current site's
     * own address — so a template's QR always points at the reseller's domain.
     */
    public static function resolveDefault(string $default, string $base): string
    {
        if ($default === '{{qr:site}}') {
            return self::siteQr();
        }

        return str_replace('{{asset}}', $base, $default);
    }

    /** @var array<string, string> per-URL memo */
    private static array $qr = [];

    private static function siteQr(): string
    {
        $url = rtrim(url('/'), '/').'/';
        if (! isset(self::$qr[$url])) {
            try {
                $svg = app(QrRenderService::class)->svg($url);
                self::$qr[$url] = 'data:image/svg+xml;base64,'.base64_encode($svg);
            } catch (\Throwable) {
                self::$qr[$url] = '';
            }
        }

        return self::$qr[$url];
    }

    /**
     * Make sure the section's root carries `data-lt-section` (the editor selects and
     * section styles attach through it). Converter output already has it on most
     * sections; a region wrapper that was stripped (e.g. the header) gets it on its
     * first element.
     */
    private static function sectionHook(string $html, string $key): string
    {
        if (str_contains($html, 'data-lt-section="'.$key.'"')) {
            return $html;
        }

        return (string) preg_replace('/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)/', '$1 data-lt-section="'.self::attr($key).'"', $html, 1);
    }

    /** True when $offset sits inside a tag (an attribute value), not in text content. */
    private static function insideTag(string $html, int $offset): bool
    {
        $before = substr($html, 0, $offset);
        $lt = strrpos($before, '<');
        $gt = strrpos($before, '>');

        return $lt !== false && ($gt === false || $lt > $gt);
    }

    /** "#8b3dff" → "139,61,255" (palette token {rgb}, for rgba(var(--x-rgb), a)). */
    private static function rgbTriplet(string $hex): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        return hexdec(substr($h, 0, 2)).','.hexdec(substr($h, 2, 2)).','.hexdec(substr($h, 4, 2));
    }

    /**
     * Inline CSS custom properties for palette overrides. Only emitted for colours the
     * user actually changed, so an untouched page uses the template CSS verbatim.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $c
     */
    private static function paletteStyle(array $m, array $c): string
    {
        $css = '';
        foreach (Manifest::palette($m) as $key => $entry) {
            $color = Slots::cleanColor($c['palette'][$key] ?? null);
            if ($color === null || $color === Slots::cleanColor($entry['default'] ?? null)) {
                continue;
            }
            foreach ((array) ($entry['vars'] ?? []) as $var => $expr) {
                if (! Manifest::safePaletteVar((string) $var, (string) $expr)) {
                    continue;
                }
                $css .= $var.':'.str_replace(['{c}', '{rgb}'], [$color, self::rgbTriplet($color)], (string) $expr).';';
            }
        }

        return $css;
    }

    /**
     * Resolve a manifest asset entry to a URL (+ filemtime cache-bust). Absolute
     * entries must live under /assets/; relative ones resolve against the version's
     * assets base. Anything else (schemes, `..`) is refused.
     */
    private static function assetUrl(string $entry, LockedTemplateVersion $version): ?string
    {
        if ($entry === '' || str_contains($entry, '..') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $entry) || str_starts_with($entry, '//')) {
            return null;
        }
        if (str_starts_with($entry, '/')) {
            $rel = ltrim($entry, '/');
            if (! str_starts_with($rel, 'assets/')) {
                return null;
            }
        } else {
            $rel = trim($version->assets_base, '/').'/'.$entry;
        }
        $mtime = @filemtime(public_path($rel));

        return asset($rel).($mtime ? '?v='.$mtime : '');
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
