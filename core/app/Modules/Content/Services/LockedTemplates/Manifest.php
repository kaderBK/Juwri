<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;


/**
 * The compiled template manifest (stored on LockedTemplateVersion::manifest):
 *
 *   {
 *     "schema": 1,
 *     "styles": ["style.css"], "styles_rtl": ["style.rtl.css"],   // relative to assets base, or /assets/…
 *     "scripts": ["/assets/locked-templates/_runtime/kit/kit.js"],
 *     "html_class": "", "body_class": "",
 *     "settings": {"theme": {"default": "light"}},
 *     "palette": [{"key": "primary", "label": "Brand colour", "default": "#864ffe",
 *                  "vars": {"--color-primary-500": "{c}", "--color-primary-50": "color-mix(in oklab, {c} 8%, white)"}}],
 *     "sections": [{"key": "hero", "label": "Hero", "required": false, "html": "…{{slot:hero.title}}…",
 *                   "slots": [{"key": "hero.title", "type": "text", "label": "Title", "default": "…", "max": 120}]}]
 *   }
 *
 * `library` (stored apart, see LockedTemplateVersion::library) holds extra ready-made
 * sections with the same shape plus `group` (Hero, Pricing, FAQ…) and optional
 * `thumbnail`. They are never on a page by default: a user inserts one as a copy
 * ("<block>~c<id>", Items::withCopies), so it gets its own re-keyed slots.
 *
 * The manifest is superadmin-authored (trusted), but it is still validated
 * structurally so a slot placeholder can never land somewhere escaping cannot
 * protect (inside <script>/<style>, or an unquoted attribute).
 */
class Manifest
{
    /**
     * @param  array<string, mixed>  $m
     * @return array<int, array<string, mixed>>
     */
    public static function sections(array $m): array
    {
        return array_values(array_filter((array) ($m['sections'] ?? []), 'is_array'));
    }

    /**
     * Library sections (only present when the caller attached them — see
     * LockedTemplateVersion::manifestWithLibrary).
     *
     * @param  array<string, mixed>  $m
     * @return array<int, array<string, mixed>>
     */
    public static function library(array $m): array
    {
        return array_values(array_filter((array) ($m['library'] ?? []), 'is_array'));
    }

    /**
     * key => slot (with its section key under `_section`).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, array<string, mixed>>
     */
    public static function slotIndex(array $m): array
    {
        $out = [];
        foreach (self::sections($m) as $section) {
            foreach ((array) ($section['slots'] ?? []) as $slot) {
                if (is_array($slot) && isset($slot['key'])) {
                    $out[(string) $slot['key']] = $slot + ['_section' => (string) ($section['key'] ?? '')];
                }
            }
        }

        return $out;
    }

    /** Elements inside which text can't carry its own links/formatting. */
    private const PLAIN_CONTAINERS = ['a', 'button', 'option', 'select', 'textarea', 'title', 'label'];

    /**
     * Text slots that may hold inline formatting (RichText): every use is in text
     * content, none inside a link/button/form control (no nested links, no markup
     * where browsers won't render it), and the slot isn't flagged `plain` (e.g.
     * animated counters whose digits the runtime rewrites).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, true>
     */
    public static function richTextKeys(array $m): array
    {
        $rich = [];
        foreach (self::sections($m) as $section) {
            $html = self::expanded($section);
            $blocked = self::placeholdersInside($html, self::PLAIN_CONTAINERS);
            foreach ((array) ($section['slots'] ?? []) as $slot) {
                $key = (string) ($slot['key'] ?? '');
                if (($slot['type'] ?? '') !== 'text' || ! empty($slot['plain']) || isset($blocked[$key])
                    || ! str_contains($html, '{{slot:'.$key.'}}') || self::attributesUsing($html, $key) !== []) {
                    continue;
                }
                $rich[$key] = true;
            }
        }

        return $rich;
    }

    /**
     * Slot keys whose text-content placeholder sits inside one of $tags.
     *
     * @param  array<int, string>  $tags
     * @return array<string, true>
     */
    private static function placeholdersInside(string $html, array $tags): array
    {
        $depth = array_fill_keys($tags, 0);
        $out = [];
        // Start/end tags (attribute placeholders are consumed with their tag) or a
        // text placeholder.
        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*+\s*(\/?)>|\{\{slot:([a-z0-9][a-z0-9_.~-]{0,95})\}\}/', $html, $mm, PREG_SET_ORDER);
        foreach ($mm as $t) {
            if (isset($t[4])) {                 // unmatched trailing groups are omitted
                if (array_sum($depth) > 0) {
                    $out[$t[4]] = true;
                }

                continue;
            }
            $name = strtolower($t[2]);
            if (isset($depth[$name]) && $t[3] === '') {
                $depth[$name] = max(0, $depth[$name] + ($t[1] === '/' ? -1 : 1));
            }
        }

        return $out;
    }

    /**
     * The section html with every repeater placeholder replaced by its ORIGINAL
     * items' markup — what validation and context checks look at, since a slot used
     * only inside an item must obey the same placement rules as any other.
     *
     * @param  array<string, mixed>  $section
     */
    public static function expanded(array $section): string
    {
        $html = (string) ($section['html'] ?? '');
        if (empty($section['repeaters'])) {
            return $html;
        }

        return Items::expand($html, $section, []);
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, array<string, mixed>>
     */
    public static function palette(array $m): array
    {
        $out = [];
        foreach ((array) ($m['palette'] ?? []) as $p) {
            if (is_array($p) && isset($p['key'])) {
                $out[(string) $p['key']] = $p;
            }
        }

        return $out;
    }

    /**
     * Structural validation. Returns a list of problems (empty = valid).
     *
     * @param  array<string, mixed>  $m
     * @return array<int, string>
     */
    public static function validate(array $m): array
    {
        $errors = [];
        $sections = self::sections($m);
        if ($sections === []) {
            $errors[] = 'Manifest has no sections.';
        }

        $seenSections = [];
        $seenSlots = [];
        $seenRepeaters = [];
        // Library sections obey every rule a page section does, share the key spaces
        // (a copy's derived keys must never collide), and can only be main content.
        $library = self::library($m);
        foreach (array_merge($sections, $library) as $i => $section) {
            $key = (string) ($section['key'] ?? '');
            if (! preg_match(Slots::KEY_RE, $key)) {
                $errors[] = "Section #{$i} has an invalid key.";

                continue;
            }
            if ($i >= count($sections) && (! empty($section['required']) || ($section['region'] ?? 'main') !== 'main')) {
                $errors[] = "Library section '{$key}' must be an optional main-content section.";
            }
            if (isset($seenSections[$key])) {
                $errors[] = "Duplicate section key '{$key}'.";
            }
            $seenSections[$key] = true;

            $html = self::expanded($section);
            $declared = [];
            foreach ((array) ($section['slots'] ?? []) as $slot) {
                $sk = (string) ($slot['key'] ?? '');
                if (! preg_match(Slots::KEY_RE, $sk)) {
                    $errors[] = "Section '{$key}' has a slot with an invalid key.";

                    continue;
                }
                if (isset($seenSlots[$sk])) {
                    $errors[] = "Duplicate slot key '{$sk}'.";
                }
                $seenSlots[$sk] = true;
                $declared[$sk] = $slot;
                if (! in_array($slot['type'] ?? '', Slots::TYPES, true)) {
                    $errors[] = "Slot '{$sk}' has an unknown type.";
                }
            }

            preg_match_all(Slots::PLACEHOLDER_RE, $html, $mm);
            foreach (array_unique($mm[1]) as $used) {
                if (! isset($declared[$used])) {
                    $errors[] = "Section '{$key}' uses undeclared slot '{$used}'.";
                }
            }

            foreach (self::unsafePlaceholderContexts($html) as $problem) {
                $errors[] = "Section '{$key}': {$problem}";
            }
            foreach (self::repeaterProblems($section, $declared, $seenRepeaters) as $problem) {
                $errors[] = "Section '{$key}': {$problem}";
            }
            foreach ($declared as $sk => $slot) {
                if (! empty($slot['multiline']) && self::placeholderInAttribute($html, $sk)) {
                    $errors[] = "Multiline slot '{$sk}' may only be used in text content.";
                }
                // Video slots hold a URL: only ever inside an attribute (src).
                if (($slot['type'] ?? '') === 'video' && self::usedInText($html, $sk)) {
                    $errors[] = "Video slot '{$sk}' may only be used in an attribute (src).";
                }
                // Icon slots emit element markup: text context only, and their trusted
                // default markup must stay inert.
                if (($slot['type'] ?? '') === 'icon') {
                    if (self::attributesUsing($html, $sk) !== []) {
                        $errors[] = "Icon slot '{$sk}' may only be used in text content.";
                    }
                    if (preg_match('/<script\b|\son[a-z]+\s*=|javascript:|<foreignObject\b/i', (string) ($slot['default_html'] ?? ''))) {
                        $errors[] = "Icon slot '{$sk}' has unsafe default markup.";
                    }
                }
                // A URL-bearing attribute must be fed by a URL-validated slot type, or a
                // text slot could smuggle `javascript:` into an href.
                foreach (self::attributesUsing($html, $sk) as $attr) {
                    if (in_array($attr, self::URL_ATTRS, true) && ! in_array($slot['type'] ?? '', ['link', 'image', 'video'], true)) {
                        $errors[] = "Slot '{$sk}' feeds the URL attribute '{$attr}' but is not a link/image/video slot.";
                    }
                }
            }
        }

        foreach (self::palette($m) as $pk => $p) {
            if (! preg_match(Slots::KEY_RE, $pk) || Slots::cleanColor($p['default'] ?? null) === null) {
                $errors[] = "Palette entry '{$pk}' needs a valid key and a #hex default.";
            }
            foreach ((array) ($p['vars'] ?? []) as $var => $expr) {
                if (! self::safePaletteVar((string) $var, (string) $expr)) {
                    $errors[] = "Palette entry '{$pk}' has an unsafe CSS variable '{$var}'.";
                }
            }
        }

        return $errors;
    }

    /**
     * Repeaters: well-formed keys, items whose markup only uses their own declared
     * slots, one markup per instance, and each `{{repeat:key:n}}` placed exactly once,
     * in text content.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $declared  slot key => slot (this section)
     * @param  array<string, true>  $seen  repeater keys across the manifest
     * @return array<int, string>
     */
    private static function repeaterProblems(array $section, array $declared, array &$seen): array
    {
        $problems = [];
        $html = (string) ($section['html'] ?? '');
        preg_match_all(Items::PLACEHOLDER_RE, $html, $used, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $instances = [];
        foreach ($used as $u) {
            $instances[$u[1][0]][] = (int) $u[2][0];
            $before = substr($html, 0, (int) $u[0][1]);
            $lt = strrpos($before, '<');
            $gt = strrpos($before, '>');
            if ($lt !== false && ($gt === false || $lt > $gt)) {
                $problems[] = "repeater placeholder '{$u[1][0]}' inside a tag.";
            }
        }

        $keys = [];
        foreach ((array) ($section['repeaters'] ?? []) as $rep) {
            $rk = is_array($rep) ? (string) ($rep['key'] ?? '') : '';
            if (! preg_match(Slots::KEY_RE, $rk)) {
                $problems[] = 'a repeater has an invalid key.';

                continue;
            }
            if (isset($seen[$rk])) {
                $problems[] = "duplicate repeater key '{$rk}'.";
            }
            $seen[$rk] = $keys[$rk] = true;
            $items = $rep['items'] ?? null;
            if (! is_array($items) || $items === [] || count($items) > 50 || ! array_is_list($items)) {
                $problems[] = "repeater '{$rk}' needs 1–50 items.";

                continue;
            }
            $inst = $instances[$rk] ?? [];
            sort($inst);
            if ($inst !== range(0, max(0, count($inst) - 1))) {        // also catches "never placed"
                $problems[] = "repeater '{$rk}' must be placed once per instance (0, 1, …).";
            }
            foreach ($items as $n => $item) {
                $slots = array_map('strval', (array) ($item['slots'] ?? []));
                $markup = (array) ($item['html'] ?? []);
                if (count($markup) !== count($inst)) {
                    $problems[] = "repeater '{$rk}' item #{$n} needs one markup per instance.";
                }
                foreach ($slots as $sk) {
                    if (! isset($declared[$sk])) {
                        $problems[] = "repeater '{$rk}' item #{$n} lists undeclared slot '{$sk}'.";
                    }
                }
                foreach ($markup as $h) {
                    if (str_contains((string) $h, '{{repeat:')) {
                        $problems[] = "repeater '{$rk}' item #{$n} nests a repeater.";
                    }
                    preg_match_all(Slots::PLACEHOLDER_RE, (string) $h, $mm);
                    foreach (array_diff(array_unique($mm[1]), $slots) as $stray) {
                        $problems[] = "repeater '{$rk}' item #{$n} uses '{$stray}' without listing it.";
                    }
                }
            }
        }
        foreach (array_keys($instances) as $rk) {
            if (! isset($keys[$rk])) {
                $problems[] = "placeholder for unknown repeater '{$rk}'.";
            }
        }

        return $problems;
    }

    /**
     * Find placeholders inside <script>/<style> blocks or unquoted attribute values.
     *
     * @return array<int, string>
     */
    private static function unsafePlaceholderContexts(string $html): array
    {
        $problems = [];
        if (preg_match_all('#<(script|style)\b[^>]*>(.*?)</\1\s*>#is', $html, $blocks)) {
            foreach ($blocks[2] as $body) {
                if (preg_match(Slots::PLACEHOLDER_RE, $body)) {
                    $problems[] = 'slot placeholder inside a <script> or <style> block.';
                }
            }
        }
        // attr={{slot:x}} (unquoted) — escaping cannot contain an unquoted attribute.
        if (preg_match('/=\s*\{\{slot:/', $html)) {
            $problems[] = 'slot placeholder in an unquoted attribute value.';
        }
        // on*="…{{slot:x}}…" or style="…{{slot:x}}…" — value would reach a JS/CSS parser.
        if (preg_match('/\s(?:on[a-z]+|style)\s*=\s*(?:"[^"]*|\'[^\']*)\{\{slot:/i', $html)) {
            $problems[] = 'slot placeholder inside an event-handler or style attribute.';
        }

        return $problems;
    }

    /**
     * A palette entry maps a CSS custom property to an expression in which `{c}` is
     * the (hex-validated) colour. The expression is emitted into an inline style
     * attribute, so it must not be able to close the declaration or the attribute.
     */
    public static function safePaletteVar(string $var, string $expr): bool
    {
        return (bool) preg_match('/^--[a-z0-9-]{1,64}$/', $var)
            && ! preg_match('/[;{}<>"\'\\\\]|url\s*\(|expression/i', str_replace(['{c}', '{rgb}'], '', $expr));
    }

    /** Is the slot's placeholder used anywhere OUTSIDE a tag (i.e. as text content)? */
    private static function usedInText(string $html, string $slotKey): bool
    {
        $needle = '{{slot:'.$slotKey.'}}';
        for ($at = strpos($html, $needle); $at !== false; $at = strpos($html, $needle, $at + 1)) {
            $lt = strrpos(substr($html, 0, $at), '<');
            $gt = strrpos(substr($html, 0, $at), '>');
            if ($lt === false || ($gt !== false && $gt > $lt)) {
                return true;
            }
        }

        return false;
    }

    private static function placeholderInAttribute(string $html, string $slotKey): bool
    {
        return self::attributesUsing($html, $slotKey) !== [];
    }

    /** Attributes whose value is a URL (a text slot must never feed one). */
    private const URL_ATTRS = ['href', 'src', 'srcset', 'action', 'formaction', 'poster', 'data', 'xlink:href', 'background', 'cite', 'ping'];

    /**
     * Lower-cased names of the attributes whose (quoted) value contains the slot.
     *
     * @return array<int, string>
     */
    private static function attributesUsing(string $html, string $slotKey): array
    {
        $needle = preg_quote('{{slot:'.$slotKey.'}}', '/');
        preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*"[^"]*'.$needle.'|([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*\'[^\']*'.$needle.'/', $html, $m);

        return array_values(array_unique(array_map('strtolower', array_filter(array_merge($m[1], $m[2])))));
    }
}
