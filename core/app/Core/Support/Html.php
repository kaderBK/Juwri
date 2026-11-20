<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * The single output-time sanitizer choke point.
 *
 * Any Blade `{!! ... !!}` that echoes user/DB-authored HTML MUST pass through
 * here (directly or via the @clean directive). Raw echoes of model fields are
 * banned outside this class — see rev/platform-v2/GUARDRAILS.md #2.
 */
class Html
{
    /**
     * Sanitize untrusted HTML down to a safe whitelist subset.
     *
     * @param  string  $profile  'default' (inline formatting only), 'richtext' (adds headings + images),
     *                           or 'page' (full-page builder HTML: layout + inline CSS, still XSS-safe)
     */
    public static function clean(?string $html, string $profile = 'default'): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return app('purify')->config($profile)->clean($html);
    }

    /**
     * Sanitize FULL-PAGE builder HTML (Builder.js / reseller html-mode pages).
     *
     * HTMLPurifier is unusable here — it natively supports only a small CSS subset
     * and silently drops modern layout properties (display/flex/grid/position/
     * transform…), which would break every drag-and-drop design. So this is an
     * allowlist DOM sanitizer that PRESERVES arbitrary inline-CSS layout while
     * removing the execution vectors:
     *   - only allowlisted elements survive (script/iframe/object/embed/
     *     form controls/link/meta/base and svg <script>/<foreignObject> are dropped),
     *   - every on* event-handler attribute is stripped,
     *   - href/src/srcset/xlink:href with javascript:/vbscript:/data: are neutralised,
     *   - only allowlisted attributes are kept (style/class/id survive; unknown drop).
     *
     * <style> BLOCKS are the one exception to the tag allowlist: they carry the
     * things inline style="" cannot — :hover, @media breakpoints, @keyframes — so
     * a builder page can be responsive + interactive-looking. Each surviving
     * <style> has its CSS SANITISED and SCOPED (see sanitizeCss): every selector
     * is confined under a `.mb-doc` wrapper this method then wraps the output in,
     * so one page's CSS cannot restyle the surrounding dashboard/storefront chrome;
     *
     * `@import`/`@charset`/expression()/behavior/-moz-binding/javascript: urls and any
     * unknown at-rule are dropped. The wrapper is added ONLY when a <style>
     * survives, so style-less pages serialise byte-identically to before.
     *
     * Threat model: reseller operators are semi-trusted (authenticated tenants
     * editing their OWN white-label pages), not anonymous end-users. This blocks
     * script execution + handler injection; CSS-only tricks on one's own page are
     * out of scope. Owner may swap in a stricter policy later.
     */
    public static function cleanPage(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $allowedTags = array_flip([
            'a', 'abbr', 'address', 'area', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'br',
            'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'dd', 'del', 'details', 'dfn', 'div',
            'dl', 'dt', 'em', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'header', 'hgroup', 'hr', 'i', 'img', 'ins', 'kbd', 'label', 'legend', 'li', 'main', 'map',
            'mark', 'nav', 'ol', 'p', 'picture', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section',
            'small', 'source', 'span', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot',
            'th', 'thead', 'time', 'tr', 'u', 'ul', 'var', 'video', 'wbr',
            // svg (icons/shapes) — <script>/<foreignObject> inside are NOT listed → dropped
            'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs',
            'use', 'symbol', 'lineargradient', 'radialgradient', 'stop', 'text', 'tspan', 'clippath', 'mask', 'pattern',
            // curated form controls — hardened below (action forced server-side, input
            // types restricted, no file/password); and <iframe> — kept ONLY for an
            // allow-listed embed host (see the per-tag hardening after the attr scrub).
            'form', 'fieldset', 'input', 'textarea', 'select', 'option', 'optgroup', 'button', 'datalist', 'output', 'iframe',
        ]);
        $allowedAttrs = array_flip([
            'id', 'class', 'style', 'title', 'dir', 'lang', 'role', 'alt', 'src', 'srcset', 'sizes',
            'href', 'target', 'rel', 'width', 'height', 'colspan', 'rowspan', 'span', 'datetime', 'cite',
            'type', 'start', 'reversed', 'value', 'loading', 'decoding', 'poster', 'controls', 'muted',
            'playsinline', 'loop', 'preload', 'name',
            // svg
            'd', 'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'points', 'transform',
            'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'preserveaspectratio',
            'xmlns', 'fill-rule', 'clip-rule', 'opacity', 'fill-opacity',
            // form controls (NOTE: action/method/target/formaction/formmethod are
            // deliberately NOT here — a builder form is forced to POST to the platform
            // submit route at render, never an operator-chosen action).
            'placeholder', 'required', 'checked', 'min', 'max', 'step', 'maxlength', 'minlength',
            'rows', 'cols', 'for', 'selected', 'disabled', 'multiple', 'pattern', 'inputmode',
            'autocomplete', 'readonly', 'wrap', 'list',
            // iframe embed (sandbox/loading/referrerpolicy are FORCED below regardless)
            'sandbox', 'allow', 'allowfullscreen', 'referrerpolicy', 'frameborder',
        ]);
        $urlAttrs = ['href', 'src', 'srcset', 'poster', 'xlink:href'];

        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // Wrap so a full/partial document parses; force UTF-8.
        $doc->loadHTML(
            '<?xml encoding="utf-8"><!DOCTYPE html><html><body><div id="__mbroot__">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__mbroot__');
        if (! $root) {
            return '';
        }

        // Walk every element depth-first; snapshot the node list first (we mutate).
        // <style> CSS is pulled out to placeholders here (so DOM serialisation
        // can't entity-escape combinators like `>`), sanitised+scoped, and spliced
        // back after serialise.
        $styles = [];
        $styleSeq = 0;
        // Placeholder tokens carry a per-call random salt: the splice below is a
        // plain string replace over the serialised HTML, so a token the author can
        // predict (typed into an attribute or text) would get the raw <style>
        // markup spliced there and break out of the attribute quote.
        $styleSalt = bin2hex(random_bytes(12));
        // Storefront + dashboard CSP are nonce-based for style-src (no unsafe-inline
        // for <style> elements), so a kept <style> MUST carry the live request nonce
        // or the browser blocks it under enforce. cleanPage is the echo choke point,
        // so the nonce stamped here is the current request's. Resolves to a throwaway
        // value at save-time (stripped + re-stamped on the echo pass) and to '' with
        // no bound generator (CLI/tests) — harmless.
        $nonceAttr = '';
        try {
            $nonce = app()->bound('csp-nonce') ? (string) app('csp-nonce') : '';
            if ($nonce !== '') {
                $nonceAttr = ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"';
            }
        } catch (\Throwable) {
            $nonceAttr = '';
        }
        $all = iterator_to_array($doc->getElementsByTagName('*'));
        foreach ($all as $el) {
            $tag = strtolower($el->tagName);
            if ($tag === 'html' || $tag === 'body' || ($el === $root)) {
                continue;
            }
            if ($tag === 'style') {
                // Inside <svg>/<math> a browser parses <style> content as MARKUP
                // (libxml treats it as text), so `<svg><style>…<img onerror>` would
                // smuggle an element past this sanitiser. Never keep those.
                for ($a = $el->parentNode; $a instanceof \DOMElement; $a = $a->parentNode) {
                    if (in_array(strtolower($a->tagName), ['svg', 'math'], true)) {
                        $el->parentNode->removeChild($el);

                        continue 2;
                    }
                }
                $safe = self::sanitizeCss($el->textContent, '.'.self::CSS_SCOPE);
                if ($safe === '') {
                    if ($el->parentNode) {
                        $el->parentNode->removeChild($el);
                    }

                    continue;
                }
                $media = $el->getAttribute('media');
                if ($media !== '' && ! preg_match('#^[a-z0-9 ,():/.\-]+$#i', $media)) {
                    $media = '';
                }
                $token = "\u{FFFC}mbstyle{$styleSalt}x{$styleSeq}\u{FFFC}";
                $styleSeq++;
                $styles[$token] = '<style'.$nonceAttr.($media !== '' ? ' media="'.htmlspecialchars($media, ENT_QUOTES).'"' : '').'>'.$safe.'</style>';
                if ($el->parentNode) {
                    $el->parentNode->replaceChild($doc->createTextNode($token), $el);
                }

                continue;
            }
            if (! isset($allowedTags[$tag])) {
                // Drop the element AND its subtree (unsafe container: script/iframe/svg script…).
                if ($el->parentNode) {
                    $el->parentNode->removeChild($el);
                }

                continue;
            }
            // Scrub attributes.
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                if (str_starts_with($name, 'on') || (! isset($allowedAttrs[$name]) && ! str_starts_with($name, 'aria-') && ! str_starts_with($name, 'data-'))) {
                    $el->removeAttribute($attr->nodeName);

                    continue;
                }
                if (in_array($name, $urlAttrs, true) && self::hasDangerousUri($attr->nodeValue)) {
                    $el->removeAttribute($attr->nodeName);
                }
            }

            // Per-tag hardening for the two curated widgets (runs after the generic
            // attr scrub, so on*/unknown attrs — including action/formaction — are
            // already gone).
            if ($tag === 'iframe') {
                // Keep ONLY an allow-listed embed host; force a tight sandbox.
                if (! self::iframeHostAllowed($el->getAttribute('src'))) {
                    if ($el->parentNode) {
                        $el->parentNode->removeChild($el);
                    }

                    continue;
                }
                $el->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-presentation');
                $el->setAttribute('loading', 'lazy');
                $el->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            } elseif ($tag === 'input') {
                $type = strtolower(trim($el->getAttribute('type') ?: 'text'));
                // Never allow credential/file/coordinate inputs on a builder page.
                if (in_array($type, ['file', 'password', 'image'], true)) {
                    if ($el->parentNode) {
                        $el->parentNode->removeChild($el);
                    }

                    continue;
                }
                if (! in_array($type, self::SAFE_INPUT_TYPES, true)) {
                    $el->setAttribute('type', 'text');
                }
            } elseif ($tag === 'button') {
                $type = strtolower(trim($el->getAttribute('type') ?: ''));
                if (! in_array($type, ['submit', 'button', 'reset'], true)) {
                    $el->setAttribute('type', 'button');
                }
            } elseif ($tag === 'form') {
                // action/method were stripped (not allow-listed). Mark it so the render
                // pipeline (Juwri\Modules\Content\Services\Builder\BuilderForms) can wire it to the
                // platform submit route with CSRF + honeypot + a signed context.
                $el->setAttribute('data-mb-form', '1');
            }
        }

        // Serialise the root's inner HTML.
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim($out);

        if ($styles !== []) {
            // Splice the sanitised CSS back in, then confine the whole document
            // under the `.mb-doc` scope the selectors were rewritten against.
            // cleanPage runs both at save AND at echo, so this must be idempotent:
            // skip the wrapper when the doc is already a single .mb-doc wrapper
            // (selector re-scoping is made idempotent in scopeSelectorList).
            $out = strtr($out, $styles);
            $wrapped = str_starts_with($out, '<div class="'.self::CSS_SCOPE.'">') && str_ends_with($out, '</div>');
            if ($out !== '' && ! $wrapped) {
                $out = '<div class="'.self::CSS_SCOPE.'">'.$out.'</div>';
            }
        }

        return $out;
    }

    /** Wrapper class every kept <style>'s selectors are scoped under. */
    private const CSS_SCOPE = 'mb-doc';

    /** <input type> values a builder form may use (file/password/image are dropped). */
    private const SAFE_INPUT_TYPES = [
        'text', 'email', 'tel', 'url', 'number', 'checkbox', 'radio', 'hidden', 'search',
        'date', 'time', 'datetime-local', 'month', 'week', 'color', 'range', 'submit',
    ];

    /** Registrable-domain suffixes an <iframe src> may point at (else the iframe is dropped). */
    private const IFRAME_HOSTS = [
        'youtube.com', 'youtube-nocookie.com', 'youtu.be',
        'vimeo.com', 'player.vimeo.com',
        'google.com', 'maps.google.com',
        'open.spotify.com', 'w.soundcloud.com',
    ];

    /** True when an iframe src host is on the embed allow-list (exact or a subdomain). */
    private static function iframeHostAllowed(?string $src): bool
    {
        $src = trim((string) $src);
        // Require an absolute or protocol-relative URL with an https/(scheme-less) host.
        $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));
        if ($scheme !== '' && $scheme !== 'https') {
            return false;
        }
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return array_any(self::IFRAME_HOSTS, fn ($allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed));
    }

    /** True when a URL attribute value carries an executable/opaque scheme. */
    private static function hasDangerousUri(?string $value): bool
    {
        $v = strtolower(html_entity_decode((string) $value, ENT_QUOTES));
        // strip whitespace/control chars browsers ignore inside the scheme
        $v = preg_replace('/[\s\x00-\x1f]+/', '', $v) ?? '';

        return str_starts_with($v, 'javascript:')
            || str_starts_with($v, 'vbscript:')
            || (str_starts_with($v, 'data:') && ! str_starts_with($v, 'data:image/'));
    }

    /**
     * Sanitise a <style> block's CSS and scope every rule under $scope (e.g. `.mb-doc`).
     *
     * Strips comments and the import/charset/namespace statements, then re-emits only
     * rules it understands: plain style rules (selectors scoped); the media, supports,
     * container, layer and document groups (recursed, inner rules scoped); keyframes
     * (declarations sanitised, keyframe selectors kept); and font-face, page, property
     * and counter-style (declarations sanitised, no selector). Any other at-rule is
     * dropped. Declarations whose value carries expression()/behavior/-moz-binding/
     * javascript:/vbscript: or a non-image data: / script url() are dropped. Never
     * executes CSS; purely textual.
     */
    private static function sanitizeCss(?string $css, string $scope): string
    {
        $css = (string) $css;
        // CSS never needs '<' (the child combinator is '>'); a '<' can only be an
        // attempt to end the <style> element or start markup — drop the block.
        if ($css === '' || strlen($css) > 200000 || str_contains($css, '<')) {
            return '';
        }
        // Drop comments and standalone statement at-rules first.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $css = preg_replace('/@(?:import|charset|namespace)\b[^;{}]*;/i', '', $css) ?? '';

        return trim(self::scopeCssRules($css, $scope));
    }

    /** Walk top-level rules of a CSS string; scope selectors, recurse into grouping at-rules. */
    private static function scopeCssRules(string $css, string $scope): string
    {
        $out = '';
        foreach (self::splitCssBlocks($css) as [$prelude, $block]) {
            $prelude = trim($prelude);
            if ($prelude === '') {
                continue;
            }
            if ($prelude[0] === '@') {
                $name = strtolower((string) (preg_split('/[\s({]/', ltrim($prelude, '@'))[0] ?? ''));
                if (in_array($name, ['media', 'supports', 'container', 'layer', 'document', '-moz-document'], true)) {
                    $inner = self::scopeCssRules($block, $scope);
                    $out .= trim($inner) === '' ? '' : $prelude.'{'.$inner.'}';
                } elseif (in_array($name, ['keyframes', '-webkit-keyframes', '-moz-keyframes', '-o-keyframes'], true)) {
                    $inner = self::renderKeyframes($block);
                    $out .= trim($inner) === '' ? '' : $prelude.'{'.$inner.'}';
                } elseif (in_array($name, ['font-face', 'page', 'property', 'counter-style', 'font-feature-values'], true)) {
                    $decls = self::sanitizeDecls($block);
                    $out .= $decls === '' ? '' : $prelude.'{'.$decls.'}';
                }

                // else: unknown at-rule → drop.
                continue;
            }
            $sel = self::scopeSelectorList($prelude, $scope);
            $decls = self::sanitizeDecls($block);
            if ($sel !== '' && $decls !== '') {
                $out .= $sel.'{'.$decls.'}';
            }
        }

        return $out;
    }

    /** @keyframes inner rules: keep the from/to/NN% selector, sanitise the declarations. */
    private static function renderKeyframes(string $css): string
    {
        $out = '';
        foreach (self::splitCssBlocks($css) as [$prelude, $block]) {
            $prelude = trim($prelude);
            $decls = self::sanitizeDecls($block);
            if ($prelude !== '' && $decls !== '' && preg_match('/^(?:from|to|[0-9.]+%)(?:\s*,\s*(?:from|to|[0-9.]+%))*$/i', $prelude)) {
                $out .= $prelude.'{'.$decls.'}';
            }
        }

        return $out;
    }

    /**
     * Split CSS into [prelude, block] pairs at top-level braces, respecting nesting,
     * quotes and parens (so `[data-x="{"]` and `url(a{b)` don't confuse the walker).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function splitCssBlocks(string $css): array
    {
        $rules = [];
        $buf = '';
        $len = strlen($css);
        $depth = 0;
        $quote = '';
        $paren = 0;
        $block = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            if ($quote !== '') {
                if ($block === null) {
                    $buf .= $c;
                } else {
                    $block .= $c;
                }
                if ($c === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $quote = '';
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                if ($block === null) {
                    $buf .= $c;
                } else {
                    $block .= $c;
                }

                continue;
            }
            if ($block === null) {
                if ($c === '(') {
                    $paren++;
                }
                if ($c === ')' && $paren > 0) {
                    $paren--;
                }
                if ($c === '{' && $paren === 0) {
                    $depth = 1;
                    $block = '';

                    continue;
                }
                if ($c === '}' && $paren === 0) {
                    // Stray close outside any block — reset the prelude.
                    $buf = '';

                    continue;
                }
                $buf .= $c;

                continue;
            }
            // Inside a block: track nested braces.
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $rules[] = [$buf, $block];
                    $buf = '';
                    $block = null;

                    continue;
                }
            }
            $block .= $c;
        }

        return $rules;
    }

    /** Prefix each selector in a comma list with $scope; map html/body/:root onto the scope itself. */
    private static function scopeSelectorList(string $selectors, string $scope): string
    {
        $out = [];
        foreach (self::splitTopLevel($selectors, ',') as $sel) {
            $sel = trim($sel);
            if ($sel === '' || stripos($sel, 'javascript:') !== false || str_contains($sel, '{') || str_contains($sel, '}')) {
                continue;
            }
            // Idempotent: a selector already scoped (prior cleanPage pass) is left as-is.
            if (preg_match('/^'.preg_quote($scope, '/').'(?:\s|$|[>+~])/', $sel)) {
                $out[] = $sel;

                continue;
            }
            if (preg_match('/^(?:html|body|:root)\b\s*/i', $sel)) {
                $rest = preg_replace('/^(?:html|body|:root)\b\s*/i', '', $sel) ?? '';
                $sel = $rest === '' ? $scope : $scope.' '.$rest;
            } else {
                $sel = $scope.' '.$sel;
            }
            $out[] = $sel;
        }

        return implode(',', $out);
    }

    /** Keep only safe `prop:value` declarations from a rule body. */
    private static function sanitizeDecls(string $block): string
    {
        $safe = [];
        foreach (self::splitTopLevel($block, ';') as $decl) {
            $decl = trim($decl);
            if ($decl === '') {
                continue;
            }
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            $prop = trim(substr($decl, 0, $colon));
            $value = trim(substr($decl, $colon + 1));
            // Accept a normal/vendor property (-webkit-…) OR a CSS custom property (--foo).
            if ($prop === '' || $value === '' || ! preg_match('/^(?:--[a-z0-9_-]+|-?[a-z][a-z0-9-]*)$/i', $prop)) {
                continue;
            }
            // Dangerous PROPERTIES: IE htc behavior + -moz-binding (both load code via url()).
            if (preg_match('/^(?:-[a-z]+-)?(?:behaviou?r|binding)$/i', $prop)) {
                continue;
            }
            if (preg_match('/expression\s*\(|javascript:|vbscript:|-moz-binding|behaviou?r\s*:|@import/i', $value)) {
                continue;
            }
            if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $value, $m)) {
                $bad = array_any($m[2], fn ($u) => self::hasDangerousUri(trim($u)));
                if ($bad) {
                    continue;
                }
            }
            $safe[] = $prop.':'.$value;
        }

        return implode(';', $safe);
    }

    /**
     * Split a string on a single-char delimiter at top level (outside quotes/parens).
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $s, string $delim): array
    {
        $res = [];
        $buf = '';
        $quote = '';
        $paren = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== '') {
                $buf .= $c;
                if ($c === $quote && ($i === 0 || $s[$i - 1] !== '\\')) {
                    $quote = '';
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;

                continue;
            }
            if ($c === '(') {
                $paren++;
            } elseif ($c === ')' && $paren > 0) {
                $paren--;
            }
            if ($c === $delim && $paren === 0) {
                $res[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $res[] = $buf;
        }

        return $res;
    }
}
