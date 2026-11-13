<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio;

use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;

/**
 * Theme Studio C1: the ONLY path an editor write takes into a store layout.
 * Same contract as LockedTemplates\Content::merge — the incoming document is
 * applied on top of the current one, but every structural change the owner's
 * caps (StudioCaps) do not allow is undone and reported in `rejected[]`,
 * never silently dropped:
 *
 *  - sections_add      a section id the current layout does not have
 *  - sections_remove   a current section id missing from the incoming layout
 *  - sections_reorder  a different relative order of the kept sections
 *  - blocks_edit       added / removed / re-typed / reordered blocks of an existing section
 *  - tokens / fonts    `theme.tokens` + `theme.settings` / `theme.fonts`
 *  - custom_block      adding a `custom-block` section (phase A)
 *  - ai_generate       setting / changing a section's AI CSS variant (`style`, E2);
 *                      clearing one is always allowed
 *
 * It also enforces each section's schema `max_per_page` (newest extra
 * instances are refused) and then runs the normal pipeline: normalize
 * (structure + 25/50 limits) → sanitize → validate against THIS store (pickers
 * may only name its own media / categories / items / branches). Limits and
 * schema violations fail the whole write (ValidationException → 422); cap
 * refusals do not. Per-language text in `i18n.<lang>` is kept only for the
 * store's other menu languages and text settings of sections / blocks that
 * exist, sanitized like the settings themselves (LayoutDocument::sanitizeI18n).
 */
class LayoutMerge
{
    public const CUSTOM_BLOCK_TYPE = 'custom-block';

    /** Setting types whose value is owner text (translatable, `studio_text`-wrappable). */
    public const TEXT_TYPES = LayoutDocument::TEXT_TYPES;

    /**
     * @param  array<string,mixed>  $current  the layout being edited (normalized)
     * @param  array<string,mixed>  $incoming  the editor's full layout document
     * @param  array<string,bool>  $caps  StudioCaps::forStore()
     * @return array{layout:array<string,mixed>,rejected:list<array{cap:string,path:string,message:string}>}
     *
     * @throws ValidationException on a limit or schema violation
     */
    public static function merge(array $current, array $incoming, MarketplaceTheme $theme, Store $store, array $caps): array
    {
        try {
            $current = LayoutDocument::normalize($current);
            $incoming = LayoutDocument::normalize($incoming);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['layout' => $e->getMessage()]);
        }

        $rejected = [];
        $reject = static function (string $cap, string $path, string $message) use (&$rejected): void {
            $rejected[] = ['cap' => $cap, 'path' => $path, 'message' => $message];
        };
        $can = static fn (string $cap): bool => (bool) ($caps[$cap] ?? false);

        $sections = [];

        // 1. Sections present in the incoming document.
        foreach ($incoming['sections'] as $sid => $section) {
            $existing = $current['sections'][$sid] ?? null;

            if ($existing === null) {
                if (! $can('sections_add')) {
                    $reject('sections_add', "sections.{$sid}", 'Adding sections is not allowed.');

                    continue;
                }
                if ($section['type'] === self::CUSTOM_BLOCK_TYPE && ! $can('custom_block')) {
                    $reject('custom_block', "sections.{$sid}", 'Custom blocks are not included in your plan.');

                    continue;
                }
                $sections[$sid] = $section;

                continue;
            }

            if ($section['type'] !== $existing['type']) {
                // A type swap is a remove + add of different code under one id — never allowed.
                $reject('sections_add', "sections.{$sid}.type", 'A section cannot change its type.');
                $section = $existing;
            }

            if (! $can('blocks_edit') && self::blockStructure($section) !== self::blockStructure($existing)) {
                $reject('blocks_edit', "sections.{$sid}.blocks", 'Adding, removing or reordering blocks is not allowed.');
                $section = self::withBlockStructureOf($section, $existing);
            }

            $sections[$sid] = $section;
        }

        // 2. Sections the incoming document dropped.
        foreach ($current['sections'] as $sid => $section) {
            if (! array_key_exists($sid, $incoming['sections']) && ! $can('sections_remove')) {
                $reject('sections_remove', "sections.{$sid}", 'Removing sections is not allowed.');
                $sections[$sid] = $section;
            }
        }

        // 2b. E2: a section's AI CSS variant may only be set or changed with
        //     ai_generate; removing it (reset to the default look) always saves.
        if (! $can('ai_generate')) {
            foreach ($sections as $sid => $section) {
                $before = $current['sections'][$sid]['style'] ?? null;
                $after = $section['style'] ?? null;
                if ($after === null || ($before !== null && ($before['css'] ?? null) === ($after['css'] ?? null))) {
                    continue;
                }
                $reject('ai_generate', "sections.{$sid}.style", 'AI section styles are not included in your plan.');
                if ($before === null) {
                    unset($sections[$sid]['style']);
                } else {
                    $sections[$sid]['style'] = $before;
                }
            }
        }

        // 3. max_per_page: keep the existing instances, refuse the newest extras.
        $sections = self::enforceMaxPerPage($theme, $sections, $current, $incoming['order'], $reject);

        // 4. Order.
        $order = self::mergeOrder($current['order'], $incoming['order'], array_keys($sections), $can('sections_reorder'), $reject);

        // 5. Theme-level values (tokens / fonts). Token + font edits normally go
        //    through the customizer endpoints; a layout may still carry them.
        $themePart = $incoming['theme'];
        foreach (['tokens' => 'tokens', 'settings' => 'tokens', 'fonts' => 'fonts'] as $key => $cap) {
            $before = $current['theme'][$key] ?? null;
            $after = $themePart[$key] ?? null;
            if ($before != $after && ! $can($cap)) {
                $reject($cap, "theme.{$key}", $cap === 'fonts' ? 'Changing fonts is not allowed.' : 'Changing the theme style is not allowed.');
                if ($before === null) {
                    unset($themePart[$key]);
                } else {
                    $themePart[$key] = $before;
                }
            }
        }

        $layout = [
            'schema' => LayoutDocument::SCHEMA,
            'sections' => $sections,
            'order' => $order,
            'theme' => $themePart,
            'i18n' => $incoming['i18n'],
        ];

        try {
            $layout = LayoutDocument::normalize($layout);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['layout' => $e->getMessage()]);
        }
        $layout = LayoutDocument::sanitize($theme, $layout);
        $layout['i18n'] = LayoutDocument::sanitizeI18n($theme, $layout, $store);

        $errors = LayoutDocument::validate($theme, $layout, $store);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['layout' => $layout, 'rejected' => $rejected];
    }

    /**
     * The section's schema, or [] when the type has no source.
     *
     * @return array<string,mixed>
     */
    public static function schemaFor(MarketplaceTheme $theme, string $type): array
    {
        $source = $theme->sectionSource($type);

        return $source === null ? [] : SectionedTemplate::extractSchema($source);
    }

    /**
     * @param  array<string,mixed>  $section
     * @return list<array{0:string,1:string}> block id + type, in order
     */
    protected static function blockStructure(array $section): array
    {
        $out = [];
        foreach ($section['block_order'] ?? [] as $bid) {
            $out[] = [(string) $bid, (string) ($section['blocks'][$bid]['type'] ?? '')];
        }

        return $out;
    }

    /**
     * Keep the incoming block SETTINGS for blocks that already exist, but the
     * current block ids, types and order.
     *
     * @param  array<string,mixed>  $section
     * @param  array<string,mixed>  $existing
     * @return array<string,mixed>
     */
    protected static function withBlockStructureOf(array $section, array $existing): array
    {
        $incomingBlocks = $section['blocks'] ?? [];
        unset($section['blocks'], $section['block_order']);
        if (! empty($existing['blocks'])) {
            $blocks = [];
            foreach ($existing['blocks'] as $bid => $block) {
                $sent = $incomingBlocks[$bid] ?? null;
                $blocks[$bid] = (is_array($sent) && ($sent['type'] ?? null) === $block['type'])
                    ? ['type' => $block['type'], 'settings' => $sent['settings'] ?? []]
                    : $block;
            }
            $section['blocks'] = $blocks;
            $section['block_order'] = $existing['block_order'] ?? array_keys($blocks);
        }

        return $section;
    }

    /**
     * @param  array<string,array<string,mixed>>  $sections
     * @param  array<string,mixed>  $current
     * @param  list<string>  $incomingOrder
     * @return array<string,array<string,mixed>>
     */
    protected static function enforceMaxPerPage(MarketplaceTheme $theme, array $sections, array $current, array $incomingOrder, callable $reject): array
    {
        $byType = [];
        foreach ($sections as $sid => $section) {
            $byType[$section['type']][] = (string) $sid;
        }

        foreach ($byType as $type => $sids) {
            $max = self::schemaFor($theme, $type)['max_per_page'] ?? null;
            if (! is_int($max) || $max < 1 || count($sids) <= $max) {
                continue;
            }
            // Existing instances first (current order), then new ones in the order the editor sent them.
            $existing = array_values(array_filter($current['order'], fn ($sid) => in_array($sid, $sids, true)));
            $new = array_values(array_filter($incomingOrder, fn ($sid) => in_array($sid, $sids, true) && ! in_array($sid, $existing, true)));
            $keep = array_slice(array_merge($existing, $new), 0, max($max, count($existing)));
            foreach ($sids as $sid) {
                if (! in_array($sid, $keep, true)) {
                    $reject('max_per_page', "sections.{$sid}", "Only {$max} '{$type}' section(s) allowed per page.");
                    unset($sections[$sid]);
                }
            }
        }

        return $sections;
    }

    /**
     * The final order. With `sections_reorder` the incoming order wins. Without
     * it the kept existing sections keep their current RELATIVE order and each
     * new section lands right after the existing section that preceded it in
     * the incoming order (or first).
     *
     * @param  list<string>  $currentOrder
     * @param  list<string>  $incomingOrder
     * @param  list<string>  $ids  every section id in the merged layout
     * @return list<string>
     */
    protected static function mergeOrder(array $currentOrder, array $incomingOrder, array $ids, bool $canReorder, callable $reject): array
    {
        $ids = array_map('strval', $ids);
        $incomingOrder = array_values(array_filter($incomingOrder, fn ($sid) => in_array($sid, $ids, true)));
        foreach ($ids as $sid) {
            if (! in_array($sid, $incomingOrder, true)) {
                $incomingOrder[] = $sid; // restored sections the editor no longer listed
            }
        }

        $existing = array_values(array_filter($currentOrder, fn ($sid) => in_array($sid, $ids, true)));
        $incomingExisting = array_values(array_filter($incomingOrder, fn ($sid) => in_array($sid, $existing, true)));

        if ($canReorder || $incomingExisting === $existing) {
            return $incomingOrder;
        }

        $reject('sections_reorder', 'order', 'Reordering sections is not allowed.');

        $anchored = []; // anchor sid ('' = start) => new sids
        $anchor = '';
        foreach ($incomingOrder as $sid) {
            if (in_array($sid, $existing, true)) {
                $anchor = $sid;
            } else {
                $anchored[$anchor][] = $sid;
            }
        }

        $out = $anchored[''] ?? [];
        foreach ($existing as $sid) {
            $out[] = $sid;
            foreach ($anchored[$sid] ?? [] as $new) {
                $out[] = $new;
            }
        }

        return $out;
    }
}
