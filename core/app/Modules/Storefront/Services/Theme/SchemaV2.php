<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

/**
 * Customizer v2 — schema normalizer.
 *
 * Every theme.json (v1 or v2) is read through here and comes out in ONE shape,
 * so the editor, the validator and the defaults builder only ever deal with the
 * v2 structure. v1 files keep working untouched — they are upgraded in memory,
 * never on disk.
 *
 * Normalized shape:
 *   [
 *     'version'  => 2,
 *     'tokens'   => [ ...design-token declarations... ],
 *     'presets'  => [ ['id','label','thumb','values'] ],
 *     'settings' => [ ['group','icon','fields'=>[ ['id','type','label','default',
 *                                                  'options','visible_if',...] ]] ],
 *     'sections' => [ ['id','label','default','toggleable','orderable'] ],
 *     'texts'    => ['localized' => bool],
 *   ]
 *
 * A group may be written as `{"include": "splash"}` to pull in a shared fragment
 * from assets/store_templates/_shared/fragments — labels and defaults stay
 * overridable per theme, field ids do not. That is what keeps seventeen themes
 * from each hand-copying the same splash block (WI-C0-4).
 */
class SchemaV2
{
    /** Fragment cache, keyed by fragment name — schemas are read many times per request. */
    private static array $fragments = [];

    public static function fragmentPath(string $name): string
    {
        return base_path('../assets/store_templates/_shared/fragments/'.$name.'.json');
    }

    /** Load a shared fragment group. Throws on an unknown name — a typo must not fail silently. */
    public static function fragment(string $name): array
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid schema fragment name [{$name}].");
        }
        if (array_key_exists($name, self::$fragments)) {
            return self::$fragments[$name];
        }
        $path = self::fragmentPath($name);
        if (! is_file($path)) {
            throw new \RuntimeException("Unknown schema fragment [{$name}].");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Malformed schema fragment [{$name}].");
        }

        return self::$fragments[$name] = $decoded;
    }

    /** Test seam: forget cached fragments. */
    public static function flushFragments(): void
    {
        self::$fragments = [];
    }

    /**
     * Upgrade any theme.json array to the canonical v2 shape.
     *
     * @param  array  $raw  decoded theme.json (v1 or v2), or a marketplace-mapped schema
     * @param  bool  $marketplace  keep the marketplace flag the service sets
     */
    public static function normalize(array $raw, bool $marketplace = false): array
    {
        if ($raw === []) {
            return [];
        }

        $out = [
            'version' => 2,
            'tokens' => is_array($raw['tokens'] ?? null) ? $raw['tokens'] : [],
            'presets' => self::normalizePresets($raw['presets'] ?? []),
            'settings' => [],
            'sections' => [],
            'texts' => ['localized' => (bool) ($raw['texts']['localized'] ?? false)],
        ];
        if ($marketplace || ($raw['marketplace'] ?? false)) {
            $out['marketplace'] = true;
        }

        foreach ($raw['settings'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            $normalized = self::normalizeGroup($group);
            if ($normalized['fields'] !== []) {
                $out['settings'][] = $normalized;
            }
        }

        foreach ($raw['sections'] ?? [] as $section) {
            if (! is_array($section) || empty($section['id'])) {
                continue;
            }
            $out['sections'][] = [
                'id' => (string) $section['id'],
                'label' => (string) ($section['label'] ?? $section['id']),
                'default' => (bool) ($section['default'] ?? true),
                'toggleable' => (bool) ($section['toggleable'] ?? true),
                'orderable' => (bool) ($section['orderable'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Resolve one settings group: either a literal group, or `{"include": "<fragment>"}`
     * with optional per-theme `group` (label) and `fields` overrides keyed by field id.
     */
    private static function normalizeGroup(array $group): array
    {
        $overrides = [];

        if (! empty($group['include'])) {
            $fragment = self::fragment((string) $group['include']);
            // Per-theme overrides may be a map (id => [label/default/...]) — the
            // list form is reserved for literal groups.
            if (isset($group['fields']) && is_array($group['fields']) && ! array_is_list($group['fields'])) {
                $overrides = $group['fields'];
            }
            $group = [
                'group' => $group['group'] ?? $fragment['group'] ?? 'Theme',
                'icon' => $group['icon'] ?? $fragment['icon'] ?? null,
                'fields' => $fragment['fields'] ?? [],
                'from' => (string) $group['include'],
            ];
        }

        $fields = [];
        foreach ($group['fields'] ?? [] as $field) {
            if (! is_array($field) || empty($field['id'])) {
                continue;
            }
            $id = (string) $field['id'];
            if (isset($overrides[$id]) && is_array($overrides[$id])) {
                $field = array_merge($field, $overrides[$id]);
                $field['id'] = $id; // an override may never re-point the id
            }
            $fields[] = self::normalizeField($field);
        }

        return array_filter([
            'group' => (string) ($group['group'] ?? 'Theme'),
            'icon' => $group['icon'] ?? null,
            'from' => $group['from'] ?? null,
            'fields' => $fields,
        ], static fn ($v) => $v !== null);
    }

    private static function normalizeField(array $field): array
    {
        $out = [
            'id' => (string) $field['id'],
            'type' => (string) ($field['type'] ?? 'text'),
            'label' => (string) ($field['label'] ?? $field['id']),
        ];
        // CC run: `option_previews` (slug => asset-relative image path, never
        // translated) and `option_sublabels` (slug => text, localized) let any
        // select field render as a visual card grid instead of a plain select.
        foreach (['default', 'options', 'option_labels', 'option_previews', 'option_sublabels', 'min', 'max', 'step', 'help', 'placeholder', 'role', 'unit'] as $key) {
            if (array_key_exists($key, $field)) {
                $out[$key] = $field[$key];
            }
        }
        // `visible_if` is an editor-side hint only. The server still validates a
        // hidden field's value — hiding a control must never become a way past
        // schema validation (WI-L3).
        if (isset($field['visible_if']) && is_array($field['visible_if'])) {
            $out['visible_if'] = $field['visible_if'];
        }

        return $out;
    }

    /** @return array<int,array{id:string,label:string,thumb:?string,values:array}> */
    private static function normalizePresets($presets): array
    {
        if (! is_array($presets)) {
            return [];
        }
        $out = [];
        foreach ($presets as $preset) {
            if (! is_array($preset) || empty($preset['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $preset['id'],
                'label' => (string) ($preset['label'] ?? $preset['id']),
                'thumb' => isset($preset['thumb']) ? (string) $preset['thumb'] : null,
                'values' => is_array($preset['values'] ?? null) ? $preset['values'] : [],
            ];
        }

        return $out;
    }

    /**
     * Evaluate a field's `visible_if` against the current value set. Every
     * condition must hold (AND); a condition value may be a list (OR within it).
     */
    public static function isVisible(array $field, array $values): bool
    {
        foreach ($field['visible_if'] ?? [] as $key => $expected) {
            $actual = $values[$key] ?? null;
            $ok = is_array($expected)
                ? in_array($actual, $expected, false)
                : $actual == $expected; // loose: "true" from a form vs true
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
