<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Rendering;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * P6-266: ALLOWLIST sanitiser for the HTML a Liquid theme renders (replaces the P6-263 block-list cleaning).
 *
 * Liquid output is not escaped, so a theme can assemble any markup at render time; the rendered HTML is therefore
 * the load-bearing gate. The output is parsed with the WHATWG HTML5 parser (PHP 8.4 Dom\HTMLDocument — the same
 * tree a browser builds, which is what closes mutation-XSS parser differentials), walked, and re-serialised:
 *
 *  - elements: only the HTML and SVG tags listed below survive; raw-text/script-capable and foreign-content
 *    elements (script, style-in-svg, iframe, object, embed, template, noscript, math, foreignObject, ...) are
 *    removed with their content; any other unknown element is unwrapped (its children kept);
 *  - attributes: global + per-tag allowlist, aria-* and data-*; every on* handler is dropped;
 *  - URLs: http(s), mailto, tel, sms, relative and #fragment only; images may also use data:image/(raster);
 *    forms may only post to this site; SVG <use> may only reference #fragments;
 *  - CSS: inline style="" and <style> sheets keep allow-listed properties only, with values free of
 *    expression()/javascript:/behaviour/binding/@import/escapes and url() limited to the safe URL set;
 *  - comments, processing instructions and CDATA are removed.
 *
 * Trusted PLATFORM output (the {% commerce %} runtime, {% widget %} Blade partials, owner custom blocks) never goes
 * through the allowlist: the tag parks it with {@see hold()} and only a slot token carrying this render's random
 * key ("signed" for this render; unguessable by the theme) is put back after sanitising.
 */
final class ThemeOutputSanitizer
{
    /** @var list<array{key:string, frags:list<string>}> */
    private static array $frames = [];

    private const HTML_NS = 'http://www.w3.org/1999/xhtml';

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /** Removed together with everything inside. */
    private const DROP = [
        'script', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset', 'noframes', 'noembed', 'noscript',
        'template', 'xmp', 'plaintext', 'portal', 'base', 'meta', 'title', 'head', 'param', 'math', 'listing',
    ];

    private const HTML_TAGS = [
        'a', 'abbr', 'address', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'button',
        'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog',
        'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'header', 'hgroup', 'hr', 'i', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'link', 'main',
        'mark', 'menu', 'meter', 'nav', 'ol', 'optgroup', 'option', 'output', 'p', 'picture', 'pre', 'progress',
        'q', 'rp', 'rt', 'ruby', 's', 'samp', 'search', 'section', 'select', 'small', 'source', 'span', 'strong',
        'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'time', 'tr',
        'track', 'u', 'ul', 'var', 'video', 'wbr',
    ];

    /** SVG elements, lower-cased (the parser keeps camelCase localNames such as linearGradient). */
    private const SVG_TAGS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'defs', 'symbol', 'use',
        'title', 'desc', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'text', 'tspan',
        'image', 'marker',
    ];

    private const GLOBAL_ATTRS = [
        'class', 'id', 'role', 'title', 'lang', 'dir', 'hidden', 'tabindex', 'style', 'translate', 'inert',
        'itemscope', 'itemtype', 'itemprop', 'draggable', 'spellcheck', 'enterkeyhint', 'inputmode', 'part',
    ];

    /** @var array<string, list<string>> */
    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel', 'hreflang', 'type', 'download'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority', 'referrerpolicy'],
        'source' => ['src', 'srcset', 'sizes', 'type', 'media', 'width', 'height'],
        'video' => ['src', 'poster', 'controls', 'autoplay', 'muted', 'loop', 'playsinline', 'preload', 'width', 'height'],
        'audio' => ['src', 'controls', 'autoplay', 'muted', 'loop', 'preload'],
        'track' => ['src', 'kind', 'srclang', 'label', 'default'],
        'link' => ['rel', 'href', 'as', 'type', 'media', 'sizes', 'crossorigin', 'hreflang'],
        'form' => ['action', 'method', 'autocomplete', 'novalidate', 'name'],
        'input' => ['type', 'name', 'value', 'placeholder', 'required', 'checked', 'disabled', 'readonly', 'min', 'max',
            'step', 'minlength', 'maxlength', 'pattern', 'autocomplete', 'size', 'multiple', 'list', 'form', 'accept', 'autofocus'],
        'button' => ['type', 'name', 'value', 'disabled', 'form', 'popovertarget', 'popovertargetaction'],
        'select' => ['name', 'multiple', 'required', 'disabled', 'size', 'form', 'autocomplete'],
        'option' => ['value', 'selected', 'disabled', 'label'],
        'optgroup' => ['label', 'disabled'],
        'textarea' => ['name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly', 'maxlength', 'minlength', 'wrap', 'form'],
        'label' => ['for', 'form'],
        'fieldset' => ['disabled', 'name', 'form'],
        'output' => ['for', 'name', 'form'],
        'meter' => ['value', 'min', 'max', 'low', 'high', 'optimum'],
        'progress' => ['value', 'max'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol' => ['start', 'reversed', 'type'],
        'li' => ['value'],
        'time' => ['datetime'],
        'data' => ['value'],
        'del' => ['datetime', 'cite'],
        'ins' => ['datetime', 'cite'],
        'q' => ['cite'],
        'blockquote' => ['cite'],
        'details' => ['open', 'name'],
        'dialog' => ['open'],
        'style' => ['media'],
        'bdo' => ['dir'],
    ];

    private const SVG_ATTRS = [
        'viewbox', 'xmlns', 'xmlns:xlink', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx',
        'ry', 'd', 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-miterlimit', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'clip-rule', 'opacity',
        'transform', 'focusable', 'preserveaspectratio', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'clip-path', 'clippathunits', 'mask', 'maskunits', 'patternunits', 'font-size',
        'font-weight', 'font-family', 'text-anchor', 'dominant-baseline', 'dx', 'dy', 'href', 'xlink:href',
        'vector-effect', 'shape-rendering', 'color', 'display', 'visibility', 'markerwidth', 'markerheight', 'refx',
        'refy', 'orient', 'class', 'id', 'role', 'style', 'aria-hidden', 'aria-label', 'tabindex', 'lang', 'dir',
    ];

    private const URL_ATTRS = ['href', 'src', 'action', 'poster', 'cite', 'xlink:href'];

    private const LINK_RELS = ['stylesheet', 'preload', 'preconnect', 'dns-prefetch', 'icon', 'alternate', 'canonical', 'prefetch'];

    private const INPUT_TYPES = [
        'text', 'search', 'email', 'tel', 'url', 'number', 'range', 'date', 'time', 'datetime-local', 'month', 'week',
        'checkbox', 'radio', 'hidden', 'submit', 'reset', 'button', 'color',
    ];

    private const CSS_PROPERTY = '/^(--[a-z0-9_-]+|color|background(-(color|image|size|position(-x|-y)?|repeat|clip|origin|attachment|blend-mode))?'
        .'|border(-(top|right|bottom|left|block|inline)(-(start|end))?)?(-(width|style|color))?|border(-(top|bottom|start|end)-(left|right|start|end))?-radius'
        .'|border-(collapse|spacing)|outline(-(width|style|color|offset))?|(margin|padding)(-(top|right|bottom|left|block|inline)(-(start|end))?)?'
        .'|(min-|max-)?(width|height|inline-size|block-size)|display|visibility|opacity|overflow(-(x|y|wrap|anchor))?|position|top|right|bottom|left'
        .'|inset(-(block|inline)(-(start|end))?)?|z-index|float|clear|box-sizing|box-shadow|flex(-(direction|wrap|flow|grow|shrink|basis))?|order'
        .'|(row-|column-)?gap|grid(-(template(-(columns|rows|areas))?|column|row|area|auto-(flow|columns|rows))(-(start|end))?)?'
        .'|(align|justify|place)-(items|content|self)|font(-(family|size|weight|style|variant|variant-numeric|stretch|feature-settings|display|kerning|optical-sizing))?'
        .'|line-height|letter-spacing|word-spacing|text-(align|align-last|decoration(-(line|color|style|thickness))?|transform|indent|overflow|shadow|underline-offset|wrap|rendering|size-adjust)'
        .'|-webkit-text-size-adjust|white-space|word-break|word-wrap|overflow-wrap|hyphens|vertical-align|direction|unicode-bidi|writing-mode'
        .'|list-style(-(type|position))?|cursor|pointer-events|user-select|-webkit-user-select|transform(-(origin|style))?|perspective|backface-visibility'
        .'|transition(-(property|duration|timing-function|delay))?|animation(-(name|duration|timing-function|delay|iteration-count|direction|fill-mode|play-state))?'
        .'|filter|backdrop-filter|-webkit-backdrop-filter|object-(fit|position)|aspect-ratio|content|quotes|table-layout|caption-side|empty-cells'
        .'|fill|fill-opacity|fill-rule|stroke(-(width|linecap|linejoin|dasharray|dashoffset|opacity|miterlimit))?|stop-color|stop-opacity'
        .'|scroll-(behavior|margin(-(top|bottom|block|inline)(-(start|end))?)?|padding(-(top|bottom|block|inline)(-(start|end))?)?|snap-(type|align|stop))'
        .'|overscroll-behavior(-(x|y))?|isolation|mix-blend-mode|clip-path|mask(-image|-size|-repeat|-position)?|-webkit-mask(-image|-size|-repeat|-position)?'
        .'|accent-color|caret-color|columns|column-(count|width|rule)|src|unicode-range|tab-size|will-change|contain|container(-(type|name))?'
        .'|appearance|-webkit-appearance|-webkit-line-clamp|line-clamp|-webkit-box-orient|-webkit-font-smoothing|-moz-osx-font-smoothing'
        .'|counter-(reset|increment)|resize|touch-action|-webkit-tap-highlight-color|scrollbar-(width|color|gutter)|text-emphasis|color-scheme|content-visibility)$/';

    public static function begin(): void
    {
        self::$frames[] = ['key' => bin2hex(random_bytes(16)), 'frags' => []];
    }

    /** Park trusted platform HTML in a slot of the current render; outside a render it is returned unchanged. */
    public static function hold(string $html): string
    {
        $i = count(self::$frames) - 1;
        if ($i < 0 || $html === '') {
            return $html;
        }
        self::$frames[$i]['frags'][] = $html;

        return self::slot(self::$frames[$i]['key'], count(self::$frames[$i]['frags']) - 1);
    }

    /** Sanitise the theme output of the current render, then fill this render's slots. */
    public static function finish(string $html): string
    {
        $frame = array_pop(self::$frames);
        $html = self::sanitize($html);
        if ($frame === null || $frame['frags'] === []) {
            return $html;
        }

        foreach ($frame['frags'] as $i => $frag) {
            $html = str_replace(self::slot($frame['key'], $i), $frag, $html);
        }

        return $html;
    }

    /** Drop an unfinished frame (render threw). */
    public static function abort(): void
    {
        array_pop(self::$frames);
    }

    private static function slot(string $key, int $i): string
    {
        return "\u{E000}mbslot:".$key.':'.$i."\u{E001}";
    }

    public static function sanitize(string $html): string
    {
        // No markup can start without "<": plain text output (prices, labels) passes untouched.
        if (! str_contains($html, '<')) {
            return $html;
        }

        [$doc, $body] = self::parse($html);
        self::walk($body);

        return self::serializeChildren($body);
    }

    /** Parse + re-serialise with NO filtering — the browser-equivalent form of $html (snapshot comparisons). */
    public static function normalize(string $html): string
    {
        if (! str_contains($html, '<')) {
            return $html;
        }

        return self::serializeChildren(self::parse($html)[1]);
    }

    /** @return array{0: HTMLDocument, 1: Element} */
    private static function parse(string $html): array
    {
        $doc = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head></head><body>'.$html,
            LIBXML_NOERROR | LIBXML_COMPACT,
            'UTF-8',
        );

        return [$doc, $doc->body];
    }

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Empty-valued attributes that read better (and are asserted) with an explicit ="" . */
    private const KEEP_EMPTY = ['alt', 'value', 'placeholder', 'content', 'title', 'href', 'src', 'action'];

    /**
     * Own serialiser (not innerHTML): text AND attribute values escape & < > " ' (htmlspecialchars style), so no
     * markup-looking text survives anywhere for a later parser or tool to re-interpret; empty attributes print bare
     * (`hidden`, `open`, `data-x`), as the themes author them.
     */
    private static function serializeChildren(Node $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $node) {
            $out .= self::serializeNode($node, $parent instanceof Element ? strtolower($parent->localName) : '');
        }

        return $out;
    }

    private static function serializeNode(Node $node, string $parentName): string
    {
        if ($node instanceof Element) {
            $name = $node->namespaceURI === self::HTML_NS ? strtolower($node->localName) : $node->localName;
            $html = '<'.$name;
            foreach ($node->attributes as $attr) {
                $v = (string) $attr->value;
                $html .= ' '.$attr->name.($v === '' && ! in_array(strtolower($attr->name), self::KEEP_EMPTY, true)
                    ? '' : '="'.htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8', true).'"');
            }
            $html .= '>';
            if ($node->namespaceURI === self::HTML_NS && in_array($name, self::VOID, true)) {
                return $html;
            }

            return $html.self::serializeChildren($node).'</'.$name.'>';
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = (string) $node->textContent;

            // <style> content is raw text (already allow-listed; it can never contain "<").
            return $parentName === 'style' ? $text : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8', true);
        }

        return '';
    }

    private static function walk(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof Element) {
                self::element($node);
            } elseif ($node->nodeType !== XML_TEXT_NODE) {
                $node->parentNode?->removeChild($node); // comments, PIs, CDATA
            }
        }
    }

    private static function element(Element $el): void
    {
        $name = strtolower($el->localName);
        $ns = $el->namespaceURI;

        if ($ns === self::SVG_NS) {
            if (! in_array($name, self::SVG_TAGS, true)) {
                $el->remove();

                return;
            }
            self::attributes($el, $name, true);
            self::walk($el);

            return;
        }

        if ($ns !== self::HTML_NS || in_array($name, self::DROP, true)) {
            $el->remove(); // MathML / unknown namespaces / script-capable

            return;
        }

        if (! in_array($name, self::HTML_TAGS, true)) {
            self::walk($el);
            while ($el->firstChild !== null) {
                $el->parentNode?->insertBefore($el->firstChild, $el);
            }
            $el->remove();

            return;
        }

        if ($name === 'style') {
            $css = self::stylesheet((string) $el->textContent);
            if (trim($css) === '') {
                $el->remove();

                return;
            }
            $el->textContent = $css;
            self::attributes($el, $name, false);

            return;
        }

        if ($name === 'link' && (! self::linkAllowed($el) || ! self::ownHost(self::normalizeUrl((string) $el->getAttribute('href'))))) {
            $el->remove();

            return;
        }

        self::attributes($el, $name, false);
        self::walk($el);
    }

    private static function linkAllowed(Element $el): bool
    {
        $rels = preg_split('/\s+/', strtolower(trim((string) $el->getAttribute('rel')))) ?: [];
        foreach ($rels as $rel) {
            if (! in_array($rel, self::LINK_RELS, true)) {
                return false;
            }
        }

        return true; // an empty rel splits to [''], which is not allow-listed, so it already returned false
    }

    private static function attributes(Element $el, string $tag, bool $svg): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = (string) $attr->value;
            if (! self::attrAllowed($tag, $name, $value, $svg)) {
                $el->removeAttribute($attr->name);

                continue;
            }
            if ($name === 'style') {
                $clean = self::declarations($value);
                if ($clean === '') {
                    $el->removeAttribute($attr->name);
                } elseif ($clean !== $value) {
                    $el->setAttribute($attr->name, $clean);
                }
            }
        }
    }

    private static function attrAllowed(string $tag, string $name, string $value, bool $svg): bool
    {
        if (str_starts_with($name, 'on')) {
            return false;
        }

        if ($svg) {
            if (! in_array($name, self::SVG_ATTRS, true) && ! str_starts_with($name, 'data-') && ! str_starts_with($name, 'aria-')) {
                return false;
            }
            if ($name === 'href' || $name === 'xlink:href') {
                return match ($tag) {
                    'use' => str_starts_with(trim($value), '#'),
                    'image' => self::urlAllowed($value, true),
                    default => false,
                };
            }
            if (in_array($name, ['fill', 'stroke', 'clip-path', 'mask'], true)) {
                return self::cssValueSafe($value);
            }

            return true;
        }

        $allowed = in_array($name, self::GLOBAL_ATTRS, true)
            || in_array($name, self::TAG_ATTRS[$tag] ?? [], true)
            || (str_starts_with($name, 'aria-') && preg_match('/^aria-[a-z]+$/', $name))
            || (str_starts_with($name, 'data-') && preg_match('/^data-[a-z0-9_.:-]+$/', $name));
        if (! $allowed) {
            return false;
        }

        if ($tag === 'input' && $name === 'type') {
            return in_array(strtolower(trim($value)), self::INPUT_TYPES, true);
        }
        if ($name === 'srcset') {
            foreach (explode(',', $value) as $candidate) {
                $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
                if ($url !== '' && ! self::urlAllowed($url, true)) {
                    return false;
                }
            }

            return true;
        }
        if (in_array($name, self::URL_ATTRS, true)) {
            if ($tag === 'form' && $name === 'action') {
                return self::sameSite(self::normalizeUrl($value));
            }

            return self::urlAllowed($value, in_array($tag, ['img', 'source', 'video', 'audio', 'track'], true) && $name !== 'href');
        }

        return true;
    }

    /** Allowed URL schemes: relative, #fragment, http(s), mailto, tel, sms; media may use raster data:image. */
    private static function urlAllowed(string $value, bool $media): bool
    {
        $url = self::normalizeUrl($value);
        if (preg_match('/(java|vb|live)script:/', $url)) {
            return false; // never needed in a theme URL, wherever it sits (`javascript:` behind junk, in a query, ...)
        }
        if ($url === '' || ! preg_match('/^([a-z][a-z0-9+.-]*):/', $url, $m)) {
            return true; // relative path / query / fragment / protocol-relative
        }

        return match ($m[1]) {
            'http', 'https', 'mailto', 'tel', 'sms' => true,
            'data' => $media && (bool) preg_match('#^data:image/(png|jpe?g|gif|webp|avif|svg\+xml)[;,]#', $url),
            default => false,
        };
    }

    /** Entity-decoded (the parser already decoded), control/whitespace-stripped, lower-cased. */
    private static function normalizeUrl(string $value): string
    {
        return strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $value) ?? '');
    }

    /** A form may only post to this site: relative path/query/fragment, or this request's own host. */
    private static function sameSite(string $url): bool
    {
        if ($url === '' || $url[0] === '#' || $url[0] === '?') {
            return true;
        }
        $url = str_replace('\\', '/', $url);
        if ($url[0] === '/' && ! str_starts_with($url, '//')) {
            return true;
        }
        if (! preg_match('#^(https?:)?//([^/?\#:@]+)(:\d+)?([/?\#]|$)#', $url, $m)) {
            return ! preg_match('/^[a-z][a-z0-9+.-]*:/', $url) && ! str_starts_with($url, '//');
        }
        $host = strtolower((string) (app()->bound('request') ? request()->getHost() : ''));

        return $host !== '' && $m[2] === $host;
    }

    /**
     * <link> may only load from this platform (relative, this request's host, APP_URL or ASSET_URL): an external
     * stylesheet is CSS injection (attribute-selector exfiltration) the inline/<style> allowlist could not see.
     */
    private static function ownHost(string $url): bool
    {
        if ($url === '') {
            return true; // empty href loads nothing (an unset theme_asset)
        }
        if (! str_starts_with($url, '//') && ! preg_match('/^[a-z][a-z0-9+.-]*:/', $url)) {
            return true; // relative
        }
        if (! str_starts_with($url, '//') && ! preg_match('#^https?://#', $url)) {
            return false;
        }
        $host = strtolower((string) parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url, PHP_URL_HOST));
        $own = array_filter(array_map(
            fn ($u) => strtolower((string) parse_url((string) $u, PHP_URL_HOST)),
            [config('app.url'), config('app.asset_url'), config('filesystems.disks.public.url')]
        ));
        if (app()->bound('request')) {
            $own[] = strtolower(request()->getHost());
        }

        return $host !== '' && in_array($host, $own, true);
    }

    // ------------------------------------------------------------------ CSS

    /** Sanitise a declaration list (style="" or a rule body): allow-listed properties with safe values only. */
    public static function declarations(string $css, bool $fontFace = false): string
    {
        $out = [];
        foreach (self::splitTopLevel(self::stripCssComments($css), ';') as $decl) {
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            $prop = strtolower(trim(substr($decl, 0, $colon)));
            $value = trim(substr($decl, $colon + 1));
            if ($value === '' || ! preg_match(self::CSS_PROPERTY, $prop)) {
                continue;
            }
            if (($prop === 'src' || $prop === 'unicode-range') && ! $fontFace) {
                continue;
            }
            if (! self::cssValueSafe($value)) {
                continue;
            }
            $out[] = $prop.': '.$value;
        }

        return implode('; ', $out);
    }

    private static function cssValueSafe(string $value): bool
    {
        // Escapes (\6a avascript), markup and nested blocks are never needed in a theme value.
        if (preg_match('/[\\\\<>{}]|\/\*/', $value)) {
            return false;
        }
        $flat = strtolower(preg_replace('/[\x00-\x20]+/', '', $value) ?? '');
        if (preg_match('/expression\(|javascript:|vbscript:|-moz-binding|behaviou?r|@import|image-set\(|src\(|element\(|paint\(/', $flat)) {
            return false;
        }
        if (preg_match_all('/url\(([^)]*)\)/', $flat, $mm)) {
            foreach ($mm[1] as $arg) {
                $arg = trim($arg, '"\'');
                if (! self::urlAllowed($arg, true) || str_starts_with($arg, 'data:image/svg')) {
                    return false;
                }
            }
        }

        return substr_count($flat, '(') === substr_count($flat, ')');
    }

    /** Sanitise a <style> sheet: plain rules + @media/@supports/@container/@layer/@keyframes/@font-face. */
    public static function stylesheet(string $css): string
    {
        $css = self::stripCssComments($css);
        $out = '';
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $open = self::findTopLevel($css, '{', $i);
            $semi = self::findTopLevel($css, ';', $i);
            if ($open === null) {
                break; // trailing statement without a block — dropped
            }
            if ($semi !== null && $semi < $open) {
                $i = $semi + 1; // @import / @charset / stray statement — dropped

                continue;
            }
            $close = self::matchBrace($css, $open);
            if ($close === null) {
                break;
            }
            $prelude = trim(substr($css, $i, $open - $i));
            $body = substr($css, $open + 1, $close - $open - 1);
            $i = $close + 1;

            if ($prelude === '' || str_contains($prelude, '<') || str_contains($prelude, '\\')) {
                continue;
            }

            if ($prelude[0] === '@') {
                $at = strtolower((string) preg_replace('/^@([a-z-]+).*$/is', '$1', $prelude));
                if (in_array($at, ['media', 'supports', 'container', 'layer', 'keyframes', '-webkit-keyframes'], true)) {
                    $inner = self::stylesheet($body);
                    if (trim($inner) !== '') {
                        $out .= $prelude.'{'.$inner.'}';
                    }
                } elseif ($at === 'font-face') {
                    $decls = self::declarations($body, true);
                    if ($decls !== '') {
                        $out .= '@font-face{'.$decls.'}';
                    }
                }

                continue;
            }

            $decls = self::declarations($body);
            if ($decls !== '') {
                $out .= $prelude.'{'.$decls.'}';
            }
        }

        return $out;
    }

    private static function stripCssComments(string $css): string
    {
        return preg_replace('#/\*.*?(\*/|$)#s', '', $css) ?? '';
    }

    /** @return list<string> */
    private static function splitTopLevel(string $s, string $sep): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $buf = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === $sep && $depth === 0) {
                $parts[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;

        return $parts;
    }

    private static function findTopLevel(string $s, string $char, int $from): ?int
    {
        $quote = null;
        $depth = 0;
        $len = strlen($s);
        for ($i = $from; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === $char && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private static function matchBrace(string $s, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $len = strlen($s);
        for ($i = $open; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
