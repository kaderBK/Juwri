<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;


/**
 * Repeated items (cards, list entries, menu links, review slides…) and section
 * copies — the structural half of the BuilderJS-style editor.
 *
 * A repeater in a section's manifest lists its ORIGINAL items (slot keys + markup
 * per instance; the desktop and mobile menu are two instances of one repeater). The
 * section html holds `{{repeat:<key>:<instance>}}`. The page content lists which
 * items to show, in order:
 *
 *   "items": {"services.r1": ["0", "2", "nk3f9a:1", "1"]}
 *
 * "2" is original item #2; "nk3f9a:1" is a NEW item rendered from original #1's
 * markup, with that item's slot keys suffixed "~nk3f9a" (its own values and styles).
 *
 * A section copy ("hero~c7x2" in content.sections) is the whole section re-keyed the
 * same way: every slot, repeater and element id gets the "~c7x2" suffix.
 *
 * Either way the markup is ALWAYS the superadmin's — users only choose which trusted
 * pieces appear and in what order, and only through validated refs.
 */
class Items
{
    public const REF_RE = '/^(?:(\d{1,2})|(n[a-z0-9]{1,8}):(\d{1,2}))$/';

    public const PLACEHOLDER_RE = '/\{\{repeat:([a-z0-9][a-z0-9_.~-]{0,95}):(\d{1,2})\}\}/';

    public const COPY_RE = '/^([a-z0-9][a-z0-9_.-]{0,63})~(c[a-z0-9]{1,8})$/';

    public const MAX_COPIES = 30;   // duplicates + library sections, per page

    /**
     * Repeaters of every section, by key (each with `_section`).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, array<string, mixed>>
     */
    public static function repeaters(array $m): array
    {
        $out = [];
        foreach (Manifest::sections($m) as $section) {
            foreach ((array) ($section['repeaters'] ?? []) as $rep) {
                if (is_array($rep) && isset($rep['key']) && is_array($rep['items'] ?? null) && $rep['items'] !== []) {
                    $out[(string) $rep['key']] = $rep + ['_section' => (string) ($section['key'] ?? '')];
                }
            }
        }

        return $out;
    }

    /**
     * @return array{src: int, id: string|null}|null
     */
    public static function parseRef(mixed $ref, int $count): ?array
    {
        if (! is_string($ref) || ! preg_match(self::REF_RE, $ref, $mm)) {
            return null;
        }
        $src = (int) ($mm[1] !== '' ? $mm[1] : $mm[3]);
        if ($src >= $count) {
            return null;
        }

        return ['src' => $src, 'id' => ($mm[2] ?? '') !== '' ? $mm[2] : null];
    }

    public static function derivedKey(string $key, string $id): string
    {
        return $key.'~'.$id;
    }

    /**
     * The item refs to show, in order (template order when not customised).
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $rep
     * @return array<int, string>
     */
    public static function refs(array $content, array $rep): array
    {
        $want = self::cleanRefs($content['items'][(string) $rep['key']] ?? null, $rep);

        return $want ?? self::defaultRefs($rep);
    }

    /**
     * @param  array<string, mixed>  $rep
     * @return array<int, string>
     */
    public static function defaultRefs(array $rep): array
    {
        return array_map('strval', array_keys(array_values((array) $rep['items'])));
    }

    /**
     * A valid ref list (known originals / well-formed new items, no duplicates,
     * within min..max), or null.
     *
     * @param  array<string, mixed>  $rep
     * @return array<int, string>|null
     */
    public static function cleanRefs(mixed $list, array $rep): ?array
    {
        if (! is_array($list) || ! array_is_list($list)) {
            return null;
        }
        $count = count((array) $rep['items']);
        $min = max(0, (int) ($rep['min'] ?? 1));
        $max = min(50, max($count, (int) ($rep['max'] ?? $count)));
        if (count($list) < $min || count($list) > $max || count(array_unique($list, SORT_REGULAR)) !== count($list)) {
            return null;
        }
        $ids = [];
        foreach ($list as $ref) {
            $p = self::parseRef($ref, $count);
            if ($p === null || ($p['id'] !== null && isset($ids[$p['id']]))) {
                return null;
            }
            if ($p['id'] !== null) {
                $ids[$p['id']] = true;
            }
        }

        return array_map('strval', $list);
    }

    /**
     * Slot schemas of new items: each original item slot re-keyed with the item's
     * suffix (`_origin` = the template slot it was cloned from).
     *
     * @param  array<string, mixed>  $m  effective manifest (see withCopies)
     * @param  array<string, mixed>  $content
     * @return array<string, array<string, mixed>>
     */
    public static function slotIndex(array $m, array $content): array
    {
        $index = Manifest::slotIndex($m);
        foreach (self::repeaters($m) as $rep) {
            $items = array_values((array) $rep['items']);
            foreach (self::refs($content, $rep) as $ref) {
                $p = self::parseRef($ref, count($items));
                if ($p === null || $p['id'] === null) {
                    continue;
                }
                foreach ((array) ($items[$p['src']]['slots'] ?? []) as $k) {
                    if (isset($index[$k])) {
                        $d = self::derivedKey((string) $k, $p['id']);
                        $index[$d] = ['key' => $d, '_origin' => $index[$k]['_origin'] ?? $k] + $index[$k];
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Replace `{{repeat:key:n}}` with the items' markup, in the page's order. In the
     * editor each item root is tagged `data-lt-item="key|ref"` for selection.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $content
     */
    public static function expand(string $html, array $section, array $content, bool $editor = false): string
    {
        $reps = [];
        foreach ((array) ($section['repeaters'] ?? []) as $rep) {
            if (is_array($rep) && isset($rep['key'])) {
                $reps[(string) $rep['key']] = $rep;
            }
        }
        if ($reps === []) {
            return $html;
        }

        return (string) preg_replace_callback(self::PLACEHOLDER_RE, static function (array $mm) use ($reps, $content, $editor): string {
            $rep = $reps[$mm[1]] ?? null;
            if ($rep === null) {
                return '';
            }
            $items = array_values((array) $rep['items']);
            $out = '';
            foreach (self::refs($content, $rep) as $ref) {
                $p = self::parseRef($ref, count($items));
                if ($p === null) {
                    continue;
                }
                $item = $items[$p['src']];
                $html = (string) (((array) ($item['html'] ?? []))[(int) $mm[2]] ?? '');
                if ($p['id'] !== null) {
                    $html = self::rekey($html, (array) ($item['slots'] ?? []), [], $p['id'], self::idsIn(...array_map('strval', (array) ($item['html'] ?? []))));
                }
                if ($editor) {
                    $html = (string) preg_replace('/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)/', '$1 data-lt-item="'.htmlspecialchars($rep['key'].'|'.$ref, ENT_QUOTES).'"', $html, 1);
                }
                $out .= $html;
            }

            return $out;
        }, $html);
    }

    /**
     * Re-key trusted markup for a clone: slot placeholders and the data-lt-* hooks of
     * $slots, repeater placeholders of $repeaters, and element ids (plus the
     * attributes that point at them) get the "~$id" / "-$id" suffix.
     *
     * $idNames: the element ids of the WHOLE clone (a reference in one instance's
     * markup may point at an id defined in another); defaults to the ids in $html.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, mixed>  $repeaters
     * @param  array<int, string>|null  $idNames
     */
    public static function rekey(string $html, array $slots, array $repeaters, string $id, ?array $idNames = null): string
    {
        $map = [];
        foreach ($slots as $k) {
            $map[(string) $k] = self::derivedKey((string) $k, $id);
        }
        $reps = array_fill_keys(array_map('strval', $repeaters), true);

        $html = (string) preg_replace_callback(Slots::PLACEHOLDER_RE, fn (array $m) => isset($map[$m[1]]) ? '{{slot:'.$map[$m[1]].'}}' : $m[0], $html);
        $html = (string) preg_replace_callback(self::PLACEHOLDER_RE, fn (array $m) => isset($reps[$m[1]]) ? '{{repeat:'.self::derivedKey($m[1], $id).':'.$m[2].'}}' : $m[0], $html);
        $html = (string) preg_replace_callback('/(\sdata-lt-(?:t|img|icon|a|ph|video)=")([^"]*)(")/', function (array $m) use ($map): string {
            $keys = preg_split('/\s+/', trim($m[2])) ?: [];

            return $m[1].implode(' ', array_map(fn ($k) => $map[$k] ?? $k, $keys)).$m[3];
        }, $html);

        // Element ids must stay unique; references inside the clone follow.
        $ids = [];
        foreach ($idNames ?? self::idsIn($html) as $old) {
            $ids[$old] = $old.'-'.$id;
        }
        if ($ids !== []) {
            $html = (string) preg_replace_callback('/(\s(?:id|for|aria-controls|aria-labelledby|aria-describedby|aria-owns)=")([^"]*)(")/', function (array $m) use ($ids): string {
                $tokens = preg_split('/\s+/', trim($m[2])) ?: [];

                return $m[1].implode(' ', array_map(fn ($t) => $ids[$t] ?? $t, $tokens)).$m[3];
            }, $html);
            $html = (string) preg_replace_callback('/(\shref="#)([^"]+)(")/', fn (array $m) => $m[1].($ids[$m[2]] ?? $m[2]).$m[3], $html);
        }

        return $html;
    }

    /**
     * Element ids defined in the given markup.
     *
     * @return array<int, string>
     */
    public static function idsIn(string ...$htmls): array
    {
        $ids = [];
        foreach ($htmls as $html) {
            preg_match_all('/\sid="([^"]+)"/', $html, $found);
            $ids = array_merge($ids, $found[1]);
        }

        return array_values(array_unique($ids));
    }

    /**
     * The manifest with the page's section copies added as virtual sections (fully
     * re-keyed clones), so every other part of the system treats a copy like any
     * section. Only non-required `main` sections can be copied.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function withCopies(array $m, array $content): array
    {
        $byKey = [];
        foreach (Manifest::sections($m) as $s) {
            $byKey[(string) ($s['key'] ?? '')] = $s;
        }
        // Library sections (when attached) are only ever placed as copies.
        foreach (Manifest::library($m) as $s) {
            $byKey[(string) ($s['key'] ?? '')] ??= $s + ['_library' => true];
        }
        $copies = [];
        foreach ((array) ($content['sections'] ?? []) as $entry) {
            $key = is_array($entry) ? (string) ($entry['key'] ?? '') : '';
            if (count($copies) >= self::MAX_COPIES || isset($copies[$key]) || ! preg_match(self::COPY_RE, $key, $mm)) {
                continue;
            }
            $base = $byKey[$mm[1]] ?? null;
            if ($base !== null && self::copyable($base)) {
                $copies[$key] = self::copySection($base, $mm[2]);
            }
        }
        if ($copies === []) {
            return $m;
        }
        $m['sections'] = array_merge(Manifest::sections($m), array_values($copies));

        return $m;
    }

    /** @param  array<string, mixed>  $section */
    public static function copyable(array $section): bool
    {
        return empty($section['required']) && ($section['region'] ?? 'main') === 'main';
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    public static function copySection(array $section, string $cid): array
    {
        $slotKeys = array_map(fn ($s) => (string) ($s['key'] ?? ''), (array) ($section['slots'] ?? []));
        $repKeys = array_map(fn ($r) => (string) ($r['key'] ?? ''), (array) ($section['repeaters'] ?? []));
        $key = self::derivedKey((string) $section['key'], $cid);
        $all = [(string) ($section['html'] ?? '')];
        foreach ((array) ($section['repeaters'] ?? []) as $rep) {
            foreach ((array) ($rep['items'] ?? []) as $item) {
                $all = array_merge($all, array_map('strval', (array) ($item['html'] ?? [])));
            }
        }
        // Library sections keep their element ids: the template's own script finds
        // controls by id (price toggle, sliders…), and a block is normally placed once.
        // Duplicates of a page section get suffixed ids (the original is on the page).
        $ids = ! empty($section['_library']) ? [] : self::idsIn(...$all);

        $copy = $section;
        $copy['key'] = $key;
        $copy['_copy_of'] = (string) $section['key'];
        $copy['html'] = str_replace('data-lt-section="'.$section['key'].'"', 'data-lt-section="'.$key.'"', self::rekey((string) ($section['html'] ?? ''), $slotKeys, $repKeys, $cid, $ids));
        $copy['slots'] = array_map(fn ($s) => ['key' => self::derivedKey((string) $s['key'], $cid), '_origin' => $s['_origin'] ?? $s['key']] + $s, (array) ($section['slots'] ?? []));
        $copy['repeaters'] = array_map(function ($rep) use ($slotKeys, $cid, $ids) {
            $rep['key'] = self::derivedKey((string) $rep['key'], $cid);
            $rep['items'] = array_map(fn ($item) => [
                'slots' => array_map(fn ($k) => self::derivedKey((string) $k, $cid), (array) ($item['slots'] ?? [])),
                'html' => array_map(fn ($h) => self::rekey((string) $h, $slotKeys, [], $cid, $ids), (array) ($item['html'] ?? [])),
            ], (array) $rep['items']);

            return $rep;
        }, (array) ($section['repeaters'] ?? []));

        return $copy;
    }

    /**
     * Keys owned by a removed new item / section copy (its suffix), so their stored
     * values and styles can be dropped with it.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    public static function forget(array $map, string $suffix): array
    {
        foreach (array_keys($map) as $k) {
            if (str_contains((string) $k, '~'.$suffix)) {
                unset($map[$k]);
            }
        }

        return $map;
    }
}
