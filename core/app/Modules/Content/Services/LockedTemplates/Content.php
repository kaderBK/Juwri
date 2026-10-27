<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;


/**
 * A page's locked-template content — the ONLY thing a user ever edits:
 *
 *   {
 *     "slots":    {"hero.title": "…", "hero.image": "/storage/…"},   // overrides only
 *     "styles":   {"hero.title": {"size": 56, "color": "#112233"}},  // per-element styles
 *     "section_styles": {"hero": {"bg": "#f5f5f5", "pt": 120}},
 *     "palette":  {"primary": "#0e7c66"},
 *     "settings": {"theme": "dark"},
 *     "sections": [{"key": "hero", "hidden": false}, …]              // order + visibility
 *     "items":    {"services.r1": ["0", "nk3f9a:1", "2"]}              // repeated items (Items)
 *   }
 *
 * A section entry may also be a COPY of a section ("hero~c7x2", see Items::withCopies).
 *
 * Text slots that allow formatting (Manifest::richTextKeys) store sanitised inline
 * HTML (RichText); every other text slot stores plain text.
 *
 * merge() applies an incoming edit on top of the current content, keeping ONLY the
 * parts the user's capabilities allow and whose values validate. Everything else is
 * silently kept as it was and reported in `rejected` — the server is the lock, the
 * editor UI merely mirrors it. A value re-sent unchanged is never a violation (so a
 * user without a capability can still save a page someone else styled).
 */
class Content
{
    /** @return array{slots: array<string,string>, styles: array<string, array<string, mixed>>, section_styles: array<string, array<string, mixed>>, palette: array<string,string>, settings: array<string,string>, sections: array<int, array{key:string, hidden:bool}>, items: array<string, array<int, string>>} */
    public static function empty(): array
    {
        return ['slots' => [], 'styles' => [], 'section_styles' => [], 'palette' => [], 'settings' => [], 'sections' => [], 'items' => []];
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array{slots: array<string,string>, styles: array<string, array<string, mixed>>, section_styles: array<string, array<string, mixed>>, palette: array<string,string>, settings: array<string,string>, sections: array<int, array{key:string, hidden:bool}>, items: array<string, array<int, string>>}
     */
    public static function normalize(?array $content): array
    {
        $c = self::empty();
        if (! $content) {
            return $c;
        }
        foreach ((array) ($content['slots'] ?? []) as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $c['slots'][$k] = $v;
            }
        }
        foreach (['styles', 'section_styles'] as $map) {
            foreach ((array) ($content[$map] ?? []) as $k => $props) {
                if (is_string($k) && is_array($props) && $props !== []) {
                    $c[$map][$k] = array_filter($props, fn ($v, $p) => is_string($p) && (is_string($v) || is_int($v) || is_float($v)), ARRAY_FILTER_USE_BOTH);
                }
            }
        }
        foreach ((array) ($content['palette'] ?? []) as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $c['palette'][$k] = $v;
            }
        }
        foreach ((array) ($content['settings'] ?? []) as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $c['settings'][$k] = $v;
            }
        }
        foreach ((array) ($content['sections'] ?? []) as $s) {
            if (is_array($s) && is_string($s['key'] ?? null)) {
                $c['sections'][] = ['key' => $s['key'], 'hidden' => (bool) ($s['hidden'] ?? false)];
            }
        }
        foreach ((array) ($content['items'] ?? []) as $k => $refs) {
            if (is_string($k) && is_array($refs) && array_is_list($refs)) {
                $c['items'][$k] = array_values(array_filter($refs, 'is_string'));
            }
        }

        return $c;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $manifest
     * @param  array<string, bool>  $caps
     * @return array{content: array<string, mixed>, rejected: array<int, string>}
     */
    public static function merge(?array $current, array $incoming, array $manifest, array $caps): array
    {
        $c = self::normalize($current);
        $rejected = [];

        // Structure first — sections (order, visibility, copies), then repeated items —
        // so the slots / styles below are checked against the page as it will be.
        if (isset($incoming['sections']) && is_array($incoming['sections'])) {
            [$c['sections'], $sectionRejects, $removed] = self::mergeSections($c['sections'], $incoming['sections'], $manifest, $caps);
            $rejected = array_merge($rejected, $sectionRejects);
            foreach ($removed as $cid) {
                $c = self::forget($c, $cid);
            }
        }
        $em = Items::withCopies($manifest, $c);
        if (isset($incoming['items']) && is_array($incoming['items'])) {
            $reps = Items::repeaters($em);
            foreach ($incoming['items'] as $key => $list) {
                $key = (string) $key;
                $rep = $reps[$key] ?? null;
                $want = $rep !== null ? Items::cleanRefs($list, $rep) : null;
                $have = $rep !== null ? Items::refs($c, $rep) : [];
                if ($want !== null && $want === $have) {
                    continue;
                }
                if ($rep === null || $want === null || empty($caps['items'])) {
                    $rejected[] = "items.{$key}";

                    continue;
                }
                if ($want === Items::defaultRefs($rep)) {
                    unset($c['items'][$key]);
                } else {
                    $c['items'][$key] = $want;
                }
                foreach (array_diff($have, $want) as $gone) {
                    $p = Items::parseRef($gone, count((array) $rep['items']));
                    if ($p !== null && $p['id'] !== null) {
                        $c = self::forget($c, $p['id']);
                    }
                }
            }
        }

        $slots = Items::slotIndex($em, $c);
        $rich = Manifest::richTextKeys($em);

        // Slots: only known keys, only types the user may edit, only valid values.
        foreach ((array) ($incoming['slots'] ?? []) as $key => $value) {
            $key = (string) $key;
            $slot = $slots[$key] ?? null;
            if ($slot !== null && ($c['slots'][$key] ?? null) === $value) {
                continue;                          // unchanged (null = default, already)
            }
            if ($slot === null || empty($caps[Slots::capFor((string) ($slot['type'] ?? 'text'))])) {
                $rejected[] = "slots.{$key}";

                continue;
            }
            if ($value === null) {
                unset($c['slots'][$key]);          // explicit "back to the template default"

                continue;
            }
            $default = (string) ($slot['default'] ?? '');
            if (isset($rich[$key]) || isset($rich[(string) ($slot['_origin'] ?? '')])) {
                $clean = RichText::clean($value, (int) ($slot['max'] ?? 300), ! empty($caps['text_format']), ! empty($slot['multiline']));
                $default = RichText::fromPlain($default);
            } else {
                $clean = Slots::clean($slot, $value);
            }
            if ($clean === null) {
                $rejected[] = "slots.{$key}";

                continue;
            }
            if ($clean === $default) {
                unset($c['slots'][$key]);          // back to the template default
            } else {
                $c['slots'][$key] = $clean;
            }
        }

        // Element styles (text / image / icon) and section styles. When present, the
        // incoming map is the full desired state: each property that differs from the
        // stored one is applied only if its capability allows and it validates.
        if (array_key_exists('styles', $incoming) && is_array($incoming['styles'] ?? [])) {
            $kinds = [];
            foreach ($slots as $key => $slot) {
                if ($kind = Styles::kindForSlot((string) ($slot['type'] ?? ''))) {
                    $kinds[$key] = $kind;
                }
            }
            [$c['styles'], $styleRejects] = self::mergeStyles($c['styles'], (array) ($incoming['styles'] ?? []), $kinds, $caps, 'styles');
            $rejected = array_merge($rejected, $styleRejects);
        }
        if (array_key_exists('section_styles', $incoming) && is_array($incoming['section_styles'] ?? [])) {
            $kinds = array_fill_keys(array_map(fn ($s) => (string) ($s['key'] ?? ''), Manifest::sections($em)), 'section');
            [$c['section_styles'], $styleRejects] = self::mergeStyles($c['section_styles'], (array) ($incoming['section_styles'] ?? []), $kinds, $caps, 'section_styles');
            $rejected = array_merge($rejected, $styleRejects);
        }

        // Palette colours.
        $palette = Manifest::palette($manifest);
        foreach ((array) ($incoming['palette'] ?? []) as $key => $value) {
            $key = (string) $key;
            $clean = Slots::cleanColor($value);
            if (! isset($palette[$key]) || empty($caps['color']) || $clean === null) {
                $rejected[] = "palette.{$key}";

                continue;
            }
            $c['palette'][$key] = $clean;
        }

        // Light / dark.
        if (array_key_exists('theme', (array) ($incoming['settings'] ?? []))) {
            $theme = $incoming['settings']['theme'];
            if (! empty($caps['theme']) && in_array($theme, ['light', 'dark'], true)) {
                $c['settings']['theme'] = $theme;
            } else {
                $rejected[] = 'settings.theme';
            }
        }

        // Text direction of the page content (Arabic content → right-to-left).
        if (array_key_exists('dir', (array) ($incoming['settings'] ?? []))) {
            $dir = $incoming['settings']['dir'];
            if (($c['settings']['dir'] ?? null) === $dir) {
                // unchanged
            } elseif (! empty($caps['theme']) && in_array($dir, ['ltr', 'rtl'], true)) {
                $c['settings']['dir'] = $dir;
            } else {
                $rejected[] = 'settings.dir';
            }
        }

        return ['content' => $c, 'rejected' => $rejected];
    }

    /**
     * Drop everything a removed new item / section copy owned (keys carrying its
     * "~id" suffix): values, styles, section styles and nested item lists.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private static function forget(array $c, string $id): array
    {
        foreach (['slots', 'styles', 'section_styles', 'items'] as $map) {
            $c[$map] = Items::forget((array) ($c[$map] ?? []), $id);
        }

        return $c;
    }

    /**
     * The manifest's sections in page order, each with its effective `hidden` flag.
     * Unknown keys in content are dropped; manifest sections missing from content
     * are appended (visible unless the manifest marks them `hidden_by_default`).
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    public static function orderedSections(array $content, array $manifest): array
    {
        $byKey = [];
        foreach (Manifest::sections($manifest) as $s) {
            $byKey[(string) $s['key']] = $s;
        }

        $out = [];
        foreach ((array) ($content['sections'] ?? []) as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if (isset($byKey[$key])) {
                $out[] = $byKey[$key] + ['_hidden' => ! empty($entry['hidden']) && empty($byKey[$key]['required'])];
                unset($byKey[$key]);
            }
        }
        foreach ($byKey as $s) {
            $out[] = $s + ['_hidden' => ! empty($s['hidden_by_default'])];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $current  key => props
     * @param  array<mixed>  $incoming  key => props (full desired state)
     * @param  array<string, string>  $kinds  valid key => style kind
     * @param  array<string, bool>  $caps
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>}
     */
    private static function mergeStyles(array $current, array $incoming, array $kinds, array $caps, string $prefix): array
    {
        $rejected = [];
        $out = [];
        $keys = array_unique(array_merge(array_keys($current), array_map('strval', array_keys($incoming))));
        foreach ($keys as $key) {
            $key = (string) $key;
            $kind = $kinds[$key] ?? null;
            $have = $kind !== null ? Styles::clean($kind, $current[$key] ?? []) : [];
            if ($kind === null) {
                if (array_key_exists($key, $incoming) && $incoming[$key] !== null && $incoming[$key] !== []) {
                    $rejected[] = "{$prefix}.{$key}";
                }

                continue;                          // unknown key: dropped
            }
            $want = is_array($incoming[$key] ?? null) ? $incoming[$key] : [];
            $next = $have;
            foreach (array_unique(array_merge(array_keys($have), array_map('strval', array_keys($want)))) as $prop) {
                $prop = (string) $prop;
                if (! Styles::known($kind, $prop)) {
                    $rejected[] = "{$prefix}.{$key}.{$prop}";

                    continue;
                }
                $value = $want[$prop] ?? null;
                $clean = $value === null ? null : Styles::cleanProp($kind, $prop, $value);
                if ($value !== null && $clean === null) {
                    $rejected[] = "{$prefix}.{$key}.{$prop}";

                    continue;
                }
                if ($clean !== null && Styles::isNeutral($kind, $prop, $clean)) {
                    $clean = null;
                }
                if ($clean === ($have[$prop] ?? null)) {
                    continue;                      // unchanged
                }
                if (empty($caps[Styles::capFor($kind, $prop)])) {
                    $rejected[] = "{$prefix}.{$key}.{$prop}";

                    continue;
                }
                if ($clean === null) {
                    unset($next[$prop]);
                } else {
                    $next[$prop] = $clean;
                }
            }
            if ($next !== []) {
                ksort($next);
                $out[$key] = $next;
            }
        }

        return [$out, $rejected];
    }

    /**
     * Order / visibility / copies. The incoming list is the full desired state: a
     * copy key not on the page yet is a new copy (placed where the list puts it),
     * an existing copy missing from the list is deleted — both need
     * `sections_duplicate` (copy of a page section) or `sections_add` (a library
     * section); visibility needs `sections_toggle`, any other order change
     * `sections_reorder`.
     *
     * @param  array<int, array{key:string, hidden:bool}>  $current
     * @param  array<int, mixed>  $incoming
     * @param  array<string, mixed>  $manifest
     * @param  array<string, bool>  $caps
     * @return array{0: array<int, array{key:string, hidden:bool}>, 1: array<int, string>, 2: array<int, string>}
     */
    private static function mergeSections(array $current, array $incoming, array $manifest, array $caps): array
    {
        $rejected = [];
        $removed = [];
        $em = Items::withCopies($manifest, ['sections' => $current]);
        $state = [];
        foreach (self::orderedSections(['sections' => $current], $em) as $s) {
            $state[(string) $s['key']] = ['hidden' => (bool) $s['_hidden'], 'required' => ! empty($s['required']), 'copy' => isset($s['_copy_of']), 'library' => ! empty($s['_library'])];
        }
        $bases = [];
        foreach (Manifest::sections($manifest) as $s) {
            $bases[(string) ($s['key'] ?? '')] = $s;
        }
        foreach (Manifest::library($manifest) as $s) {
            $bases[(string) ($s['key'] ?? '')] ??= $s + ['_library' => true];
        }
        // Copies of page sections need `sections_duplicate`; library sections `sections_add`.
        $capFor = fn (bool $library): bool => ! empty($caps[$library ? 'sections_add' : 'sections_duplicate']);

        // New copies go where the incoming list puts them (right after their source).
        $incomingKeys = [];
        $prev = null;
        foreach ($incoming as $entry) {
            $key = is_array($entry) ? (string) ($entry['key'] ?? '') : '';
            if ($key === '' || isset($incomingKeys[$key])) {
                continue;
            }
            $incomingKeys[$key] = true;
            if (! isset($state[$key]) && preg_match(Items::COPY_RE, $key, $mm)) {
                $copies = count(array_filter($state, fn ($st) => $st['copy']));
                $base = $bases[$mm[1]] ?? null;
                if ($base === null || ! $capFor(! empty($base['_library'])) || ! Items::copyable($base) || $copies >= Items::MAX_COPIES) {
                    $rejected[] = "sections.{$key}";

                    continue;
                }
                $new = [$key => ['hidden' => false, 'required' => false, 'copy' => true, 'library' => ! empty($base['_library'])]];
                $at = $prev !== null ? array_search($prev, array_keys($state), true) : false;
                $state = $at === false ? $state + $new : array_slice($state, 0, $at + 1, true) + $new + array_slice($state, $at + 1, null, true);
            }
            if (isset($state[$key])) {
                $prev = $key;
            }
        }
        foreach ($state as $key => $st) {
            if ($st['copy'] && ! isset($incomingKeys[$key])) {
                if (! $capFor($st['library'])) {
                    $rejected[] = "sections.{$key}";
                } else {
                    unset($state[$key]);
                    $removed[] = substr((string) $key, (int) strrpos((string) $key, '~') + 1);
                }
            }
        }

        $wantOrder = [];
        foreach ($incoming as $entry) {
            if (! is_array($entry) || ! isset($state[(string) ($entry['key'] ?? '')])) {
                continue;
            }
            $key = (string) $entry['key'];
            $wantOrder[] = $key;
            $wantHidden = (bool) ($entry['hidden'] ?? false);
            if ($wantHidden !== $state[$key]['hidden']) {
                if (empty($caps['sections_toggle']) || ($wantHidden && $state[$key]['required'])) {
                    $rejected[] = "sections.{$key}.hidden";
                } else {
                    $state[$key]['hidden'] = $wantHidden;
                }
            }
        }

        $order = array_keys($state);
        $wantOrder = array_values(array_unique($wantOrder));
        if ($wantOrder !== [] && $wantOrder !== array_slice($order, 0, count($wantOrder))) {
            if (empty($caps['sections_reorder'])) {
                $rejected[] = 'sections.order';
            } else {
                $order = array_values(array_unique(array_merge($wantOrder, $order)));
            }
        }

        $out = [];
        foreach ($order as $key) {
            $out[] = ['key' => (string) $key, 'hidden' => $state[$key]['hidden']];
        }

        return [$out, $rejected, $removed];
    }
}
