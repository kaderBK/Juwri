<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;

/**
 * Theme Studio phase D step 3 input: the section library as compact schemas
 * for the compose prompt — only the types a store of this vertical may use,
 * only the settings the model may choose (no pickers / links), options as
 * value lists. Also turns the model's compact section list back into a
 * layout document.
 */
class Catalogue
{
    /**
     * @param  array<string,mixed>  $ctx
     * @return list<array<string,mixed>>
     */
    public static function compact(MarketplaceTheme $theme, ProposalRules $rules, array $ctx): array
    {
        $types = $theme->library()?->sectionTypes() ?? [];
        foreach (glob($theme->path('sections').'/*.liquid') ?: [] as $file) {
            $types[] = basename($file, '.liquid');
        }
        $types = array_values(array_unique($types));
        sort($types);

        $out = [];
        foreach ($types as $type) {
            $schema = $rules->schema($type);
            if ($schema === [] || ($schema['hidden_from_gallery'] ?? false) === true || ! $rules->allowedForVertical($schema, $ctx)) {
                continue;
            }
            if (empty($ctx['ordering']) && $type === 'floating-cart') {
                continue;
            }
            $entry = [
                'type' => $type,
                'group' => (string) ($schema['group'] ?? 'content'),
                'settings' => self::settings($schema['settings'] ?? []),
            ];
            if (is_int($schema['max_per_page'] ?? null)) {
                $entry['max'] = $schema['max_per_page'];
            }
            $blocks = [];
            foreach ((array) ($schema['blocks'] ?? []) as $def) {
                if (is_array($def) && isset($def['type'])) {
                    $blocks[] = ['type' => (string) $def['type'], 'settings' => self::settings($def['settings'] ?? [])]
                        + (isset($def['limit']) ? ['limit' => (int) $def['limit']] : []);
                }
            }
            if ($blocks !== []) {
                $entry['blocks'] = $blocks;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The model's compact proposal sections → a layout document. Section ids
     * are the recipes' canonical ids (menu-grid → menu, …), numbered on
     * repeats; block ids are `<type>-<n>`.
     *
     * @param  mixed  $sections  [{type, settings?, blocks?:[{type, settings?}], disabled?}]
     * @return array<string,mixed>
     */
    public static function toLayout(mixed $sections): array
    {
        $layout = ['schema' => 1, 'sections' => [], 'order' => [], 'theme' => ['settings' => []], 'i18n' => []];
        foreach (is_array($sections) ? array_values($sections) : [] as $section) {
            if (! is_array($section) || ! is_string($section['type'] ?? null) || ! preg_match(LayoutDocument::TYPE_PATTERN, $section['type'])) {
                continue;
            }
            $base = ProposalRules::canonicalId($section['type']);
            $sid = $base;
            for ($i = 2; isset($layout['sections'][$sid]); $i++) {
                $sid = $base.'-'.$i;
            }
            $entry = [
                'type' => $section['type'],
                'settings' => is_array($section['settings'] ?? null) ? $section['settings'] : [],
            ];
            if (($section['disabled'] ?? false) === true) {
                $entry['disabled'] = true;
            }
            $n = [];
            foreach (is_array($section['blocks'] ?? null) ? array_values($section['blocks']) : [] as $block) {
                if (! is_array($block) || ! is_string($block['type'] ?? null) || ! preg_match(LayoutDocument::TYPE_PATTERN, $block['type'])) {
                    continue;
                }
                $n[$block['type']] = ($n[$block['type']] ?? 0) + 1;
                $bid = $block['type'].'-'.$n[$block['type']];
                $entry['blocks'][$bid] = ['type' => $block['type'], 'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : []];
                $entry['block_order'][] = $bid;
            }
            $layout['sections'][$sid] = $entry;
            $layout['order'][] = $sid;
        }

        return $layout;
    }

    /**
     * A layout as the compact list the model reads/writes (recipe skeletons).
     *
     * @param  array<string,mixed>  $layout
     * @return list<array<string,mixed>>
     */
    public static function fromLayout(array $layout): array
    {
        $out = [];
        foreach ($layout['order'] ?? [] as $sid) {
            $section = $layout['sections'][$sid] ?? null;
            if (! is_array($section)) {
                continue;
            }
            $entry = ['type' => $section['type']];
            if (! empty($section['settings'])) {
                $entry['settings'] = $section['settings'];
            }
            foreach ($section['block_order'] ?? [] as $bid) {
                $block = $section['blocks'][$bid] ?? null;
                if (is_array($block)) {
                    $entry['blocks'][] = ['type' => $block['type']] + (empty($block['settings']) ? [] : ['settings' => $block['settings']]);
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * "id:type" or "id:select(a|b|c)" / "id:range(min-max)" strings, pickers and links left out.
     *
     * @return list<string>
     */
    protected static function settings(mixed $defs): array
    {
        $out = [];
        foreach (is_array($defs) ? $defs : [] as $def) {
            if (! is_array($def) || empty($def['id'])) {
                continue;
            }
            $type = (string) ($def['type'] ?? 'text');
            if (in_array($type, ProposalRules::STRIPPED_TYPES, true)) {
                continue;
            }
            if ($type === 'select' && is_array($def['options'] ?? null)) {
                $values = array_map(fn ($o) => is_array($o) ? (string) ($o['value'] ?? '') : (string) $o, $def['options']);
                $out[] = $def['id'].':select('.implode('|', $values).')';
            } elseif ($type === 'range' || $type === 'number') {
                $out[] = $def['id'].':'.$type.'('.($def['min'] ?? '').'-'.($def['max'] ?? '').')';
            } elseif ($type === 'checkbox' || $type === 'toggle') {
                $out[] = $def['id'].':bool';
            } else {
                $out[] = $def['id'].':'.$type;
            }
        }

        return $out;
    }
}
