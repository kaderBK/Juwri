<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutMerge;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;

/**
 * Theme Studio phase D: what a generated layout must satisfy on top of the
 * section schemas (spec §4 D2 step 3), plus the deterministic repairs the
 * builder applies to model output and to recipe skeletons.
 *
 * Context (`$ctx`): group (food|goods|services|stays), store_type, ordering
 * (bool), priority (effective: owner's, else the recipe's), max_sections.
 *
 * Rules:
 *  - library/theme section types only, available to the store's vertical
 *    (schema `verticals` ∋ all | group | store type), never hidden ones
 *    (custom-block), `max_per_page` respected;
 *  - the vertical's catalogue section is present and enabled
 *    (food → menu-grid, goods → product-grid, services → service-list, stays → room-list);
 *  - order CTAs only when the store takes orders (no floating cart, no
 *    hero CTA / tab bar tab targeting the cart);
 *  - ≤ max_sections (14) sections;
 *  - a hero-group section is the first non-chrome section unless the
 *    priority is fast_ordering;
 *  - every section/block setting passes its schema (LayoutDocument::validate,
 *    store-scoped when there is a store).
 *
 * The model may only choose types, select/checkbox/range options and text:
 * picker/url settings (image, category, items, branch, links) are stripped
 * before validation, so a proposal can never point at another tenant's rows
 * or an external URL.
 */
class ProposalRules
{
    public const CATALOGUE = [
        'food' => 'menu-grid',
        'goods' => 'product-grid',
        'services' => 'service-list',
        'stays' => 'room-list',
    ];

    /** Setting types the model may not set (store rows / links). */
    public const STRIPPED_TYPES = ['image_picker', 'category', 'item_list', 'branch', 'url', 'collection', 'product', 'link_list', 'video_url'];

    /**
     * Section types dropped first when a layout is over the section cap
     * (least load-bearing first). Anything not listed is dropped from the
     * end of the page, never a protected type.
     */
    public const TRIM_ORDER = [
        'stories-rail', 'search-bar', 'store-status', 'dietary-filters', 'banners-slider', 'brand-row',
        'gallery', 'testimonials', 'faq', 'image-with-text', 'rich-text', 'offers-strip', 'announcement-bar',
        'loyalty-card', 'branch-picker', 'catering-cta', 'location', 'hours', 'about', 'reviews',
        'featured-rail', 'bottom-tabbar', 'category-nav',
    ];

    /** Never trimmed. */
    public const PROTECTED = ['header', 'hero-cover', 'footer', 'floating-cart', 'pharmacy-notice', 'fitment-search', 'reservation-cta'];

    /** @var array<string,array<string,mixed>> */
    protected array $schemaMemo = [];

    public function __construct(protected MarketplaceTheme $theme) {}

    public function theme(): MarketplaceTheme
    {
        return $this->theme;
    }

    /**
     * Every rule violation of a (normalized) layout; [] = valid.
     *
     * @param  array<string,mixed>  $layout
     * @param  array<string,mixed>  $ctx
     * @return array<string,string> path → message
     */
    public function check(array $layout, array $ctx, ?Store $store = null): array
    {
        try {
            $layout = LayoutDocument::normalize($layout);
        } catch (\InvalidArgumentException $e) {
            return ['layout' => $e->getMessage()];
        }
        $layout = LayoutDocument::sanitize($this->theme, $layout);
        $errors = LayoutDocument::validate($this->theme, $layout, $store);

        $counts = [];
        foreach ($layout['sections'] as $sid => $section) {
            $schema = $this->schema($section['type']);
            if ($schema === []) {
                continue; // validate() already reported the unknown type
            }
            if (! $this->allowedForVertical($schema, $ctx)) {
                $errors["sections.{$sid}.type"] = "'{$section['type']}' is not available for a {$ctx['store_type']} store.";
            }
            if (($schema['hidden_from_gallery'] ?? false) === true) {
                $errors["sections.{$sid}.type"] = "'{$section['type']}' cannot be used.";
            }
            $counts[$section['type']] = ($counts[$section['type']] ?? 0) + 1;
            $max = $schema['max_per_page'] ?? null;
            if (is_int($max) && $counts[$section['type']] > $max) {
                $errors["sections.{$sid}.type"] = "only {$max} '{$section['type']}' section(s) per page.";
            }
        }

        $catalogue = self::CATALOGUE[$ctx['group']] ?? null;
        if ($catalogue !== null && ! $this->hasEnabled($layout, $catalogue)) {
            $errors['sections'] = "the page must include an enabled '{$catalogue}' section.";
        }

        if (empty($ctx['ordering'])) {
            foreach ($this->cartTargets($layout) as $path) {
                $errors[$path] = 'this store does not take orders — no cart / order buttons.';
            }
        }

        $max = (int) ($ctx['max_sections'] ?? 14);
        if (count($layout['sections']) > $max) {
            $errors['order'] = count($layout['sections'])." sections (max {$max}).";
        }

        if (($ctx['priority'] ?? null) !== 'fast_ordering') {
            $first = $this->firstContentSection($layout);
            if ($first === null || ($this->schema($layout['sections'][$first]['type'])['group'] ?? '') !== 'hero') {
                $errors['order.hero'] = 'the first section after the header must be a hero (hero-cover or brand-row).';
            }
        }

        return $errors;
    }

    /**
     * Deterministic repair: drop what can't be used, strip picker/url
     * settings, drop invalid setting keys (schema default applies), enforce
     * max_per_page, remove order CTAs without ordering, ensure the catalogue
     * section + hero, trim to the cap and point catalogue anchors at the
     * catalogue section. The result passes check() for any sane input.
     *
     * @param  array<string,mixed>  $layout
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed> normalized + sanitized layout
     */
    public function repair(array $layout, array $ctx, ?Store $store = null): array
    {
        try {
            $layout = LayoutDocument::normalize($layout);
        } catch (\InvalidArgumentException $e) {
            // Over the engine limits: keep the first 25 sections / 50 blocks each.
            $layout['sections'] = array_slice(is_array($layout['sections'] ?? null) ? $layout['sections'] : [], 0, 25, true);
            foreach ($layout['sections'] as $sid => $section) {
                if (is_array($section['blocks'] ?? null)) {
                    $layout['sections'][$sid]['blocks'] = array_slice($section['blocks'], 0, 50, true);
                }
            }
            $layout = LayoutDocument::normalize($layout);
        }

        // 1. Unusable types (unknown, other vertical, hidden, over max_per_page).
        $counts = [];
        foreach ($layout['order'] as $sid) {
            $section = $layout['sections'][$sid];
            $schema = $this->schema($section['type']);
            $counts[$section['type']] = ($counts[$section['type']] ?? 0) + 1;
            $max = $schema['max_per_page'] ?? null;
            if ($schema === [] || ! $this->allowedForVertical($schema, $ctx)
                || ($schema['hidden_from_gallery'] ?? false) === true
                || (is_int($max) && $counts[$section['type']] > $max)) {
                $this->remove($layout, $sid);
            }
        }

        // 2. Settings the model may not choose + unknown block types.
        $layout = $this->stripPickers($layout);

        // 3. Invalid values → dropped (the schema default applies).
        $layout = LayoutDocument::sanitize($this->theme, $layout);
        foreach (LayoutDocument::validate($this->theme, $layout, $store) as $path => $message) {
            $parts = explode('.', $path);
            if ($parts[0] !== 'sections' || ! isset($parts[1], $layout['sections'][$parts[1]])) {
                continue;
            }
            $sid = $parts[1];
            if (($parts[2] ?? '') === 'blocks' && isset($parts[3])) {
                $bid = $parts[3];
                if (($parts[4] ?? '') === 'type' || ! isset($parts[4])) {
                    unset($layout['sections'][$sid]['blocks'][$bid]);
                    $layout['sections'][$sid]['block_order'] = array_values(array_diff($layout['sections'][$sid]['block_order'] ?? [], [$bid]));
                } else {
                    unset($layout['sections'][$sid]['blocks'][$bid]['settings'][$parts[4]]);
                }
            } elseif (($parts[2] ?? '') === 'type') {
                $this->remove($layout, $sid);
            } elseif (isset($parts[2])) {
                unset($layout['sections'][$sid]['settings'][$parts[2]]);
            }
        }

        // 4. No order CTAs without ordering.
        if (empty($ctx['ordering'])) {
            foreach ($layout['sections'] as $sid => $section) {
                if ($section['type'] === 'floating-cart') {
                    $this->remove($layout, $sid);
                }
            }
            foreach (array_filter($this->cartTargets($layout), fn ($p) => substr_count($p, '.') === 3) as $path) {
                [, $sid, , $bid] = explode('.', $path);
                unset($layout['sections'][$sid]['blocks'][$bid]);
                $layout['sections'][$sid]['block_order'] = array_values(array_diff($layout['sections'][$sid]['block_order'] ?? [], [$bid]));
            }
        }

        // 5. The catalogue section.
        $catalogue = self::CATALOGUE[$ctx['group']] ?? null;
        if ($catalogue !== null && ! $this->hasEnabled($layout, $catalogue)) {
            $existing = $this->firstOfType($layout, $catalogue);
            if ($existing !== null) {
                unset($layout['sections'][$existing]['disabled']);
            } elseif ($this->schema($catalogue) !== []) {
                $sid = $this->freeId($layout, self::canonicalId($catalogue));
                $layout['sections'][$sid] = ['type' => $catalogue, 'settings' => []];
                // Before the footer (or last).
                $footer = $this->firstOfType($layout, 'footer');
                $at = $footer !== null ? (int) array_search($footer, $layout['order'], true) : count($layout['order']);
                array_splice($layout['order'], $at, 0, [$sid]);
            }
        }

        // 6. Hero first (unless fast ordering).
        if (($ctx['priority'] ?? null) !== 'fast_ordering') {
            $first = $this->firstContentSection($layout);
            $firstIsHero = $first !== null && ($this->schema($layout['sections'][$first]['type'])['group'] ?? '') === 'hero';
            if (! $firstIsHero) {
                $hero = $this->firstOfType($layout, 'hero-cover') ?? $this->firstOfType($layout, 'brand-row');
                if ($hero === null) {
                    $hero = $this->freeId($layout, 'hero');
                    $layout['sections'][$hero] = ['type' => 'hero-cover', 'settings' => []];
                } else {
                    unset($layout['sections'][$hero]['disabled']);
                    $layout['order'] = array_values(array_diff($layout['order'], [$hero]));
                }
                array_splice($layout['order'], $this->leadingChromeCount($layout), 0, [$hero]);
            }
        }

        // 7. The section cap.
        $max = (int) ($ctx['max_sections'] ?? 14);
        $protected = array_merge(self::PROTECTED, $catalogue !== null ? [$catalogue] : []);
        foreach (self::TRIM_ORDER as $type) {
            while (count($layout['sections']) > $max && ($sid = $this->lastOfType($layout, $type)) !== null) {
                $this->remove($layout, $sid);
            }
        }
        for ($i = count($layout['order']) - 1; $i >= 0 && count($layout['sections']) > $max; $i--) {
            $sid = $layout['order'][$i];
            if (! in_array($layout['sections'][$sid]['type'] ?? '', $protected, true)) {
                $this->remove($layout, $sid);
            }
        }

        // 8. Catalogue anchors point at the catalogue section.
        $catalogueSid = $catalogue !== null ? $this->firstOfType($layout, $catalogue) : null;
        foreach ($layout['sections'] as $sid => $section) {
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                if (in_array($block['type'], ['cta', 'tab'], true) && ($block['settings']['target'] ?? null) === 'catalog') {
                    if ($catalogueSid !== null) {
                        $layout['sections'][$sid]['blocks'][$bid]['settings']['anchor'] = $catalogueSid;
                    } else {
                        unset($layout['sections'][$sid]['blocks'][$bid]['settings']['anchor']);
                    }
                }
            }
        }

        $layout['order'] = array_values(array_filter($layout['order'], fn ($sid) => isset($layout['sections'][$sid])));
        $layout = LayoutDocument::normalize($layout);

        return LayoutDocument::sanitize($this->theme, $layout);
    }

    /**
     * Remove every setting the model may not choose (pickers, links), in
     * sections and blocks.
     *
     * @param  array<string,mixed>  $layout
     * @return array<string,mixed>
     */
    public function stripPickers(array $layout): array
    {
        foreach ($layout['sections'] ?? [] as $sid => $section) {
            $schema = $this->schema((string) $section['type']);
            $layout['sections'][$sid]['settings'] = $this->stripSettings($schema['settings'] ?? [], $section['settings'] ?? []);
            $blockDefs = [];
            foreach ($schema['blocks'] ?? [] as $def) {
                if (is_array($def) && isset($def['type'])) {
                    $blockDefs[(string) $def['type']] = $def;
                }
            }
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                $def = $blockDefs[$block['type']] ?? null;
                if ($def === null) {
                    unset($layout['sections'][$sid]['blocks'][$bid]);
                    $layout['sections'][$sid]['block_order'] = array_values(array_diff($layout['sections'][$sid]['block_order'] ?? [], [$bid]));

                    continue;
                }
                $layout['sections'][$sid]['blocks'][$bid]['settings'] = $this->stripSettings($def['settings'] ?? [], $block['settings'] ?? []);
            }
            if (isset($layout['sections'][$sid]['blocks']) && $layout['sections'][$sid]['blocks'] === []) {
                unset($layout['sections'][$sid]['blocks'], $layout['sections'][$sid]['block_order']);
            }
        }

        return $layout;
    }

    /**
     * Text settings (per LayoutMerge::TEXT_TYPES) of the SECTIONS — the copy
     * that is translatable into `layout.i18n` (`<sid>.<key>`).
     *
     * @param  array<string,mixed>  $layout
     * @return array<string,array{value:string,type:string}> "<sid>.<key>" → value + setting type
     */
    public function copy(array $layout): array
    {
        $out = [];
        foreach ($layout['sections'] ?? [] as $sid => $section) {
            $types = [];
            foreach ($this->schema((string) $section['type'])['settings'] ?? [] as $def) {
                if (is_array($def) && isset($def['id'])) {
                    $types[(string) $def['id']] = (string) ($def['type'] ?? 'text');
                }
            }
            foreach ($section['settings'] ?? [] as $key => $value) {
                $type = $types[$key] ?? null;
                if ($type !== null && in_array($type, LayoutMerge::TEXT_TYPES, true) && is_string($value) && trim($value) !== '') {
                    $out[$sid.'.'.$key] = ['value' => $value, 'type' => $type];
                }
            }
        }

        return $out;
    }

    /**
     * Section id for a type, as the recipes name it (menu-grid → menu,
     * product-grid → products, …) so model output and recipes agree.
     */
    public static function canonicalId(string $type): string
    {
        static $map = null;
        static $app = null;
        if ($map === null || $app !== \Juwri\Core\Support\RequestEpoch::id()) {
            $app = \Juwri\Core\Support\RequestEpoch::id();
            $map = [];
            foreach (Recipes::all() as $recipe) {
                foreach ($recipe['layout']['sections'] ?? [] as $sid => $section) {
                    $t = (string) ($section['type'] ?? '');
                    if ($t !== '' && ! isset($map[$t]) && preg_match(LayoutDocument::ID_PATTERN, (string) $sid)) {
                        $map[$t] = (string) $sid;
                    }
                }
            }
        }

        return $map[$type] ?? $type;
    }

    /** @return array<string,mixed> */
    public function schema(string $type): array
    {
        return $this->schemaMemo[$type] ??= LayoutMerge::schemaFor($this->theme, $type);
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $ctx
     */
    public function allowedForVertical(array $schema, array $ctx): bool
    {
        $verticals = array_map('strval', (array) ($schema['verticals'] ?? ['all']));

        return in_array('all', $verticals, true)
            || in_array((string) ($ctx['group'] ?? ''), $verticals, true)
            || in_array((string) ($ctx['store_type'] ?? ''), $verticals, true);
    }

    /**
     * @param  list<mixed>  $defs
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    protected function stripSettings(array $defs, array $values): array
    {
        $types = [];
        foreach ($defs as $def) {
            if (is_array($def) && isset($def['id'])) {
                $types[(string) $def['id']] = (string) ($def['type'] ?? 'text');
            }
        }
        foreach ($values as $key => $value) {
            if (! isset($types[$key]) || in_array($types[$key], self::STRIPPED_TYPES, true)) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    /**
     * Every order CTA: "sections.<sid>" (an enabled floating cart) and
     * "sections.<sid>.blocks.<bid>" (a block that sends the shopper to the cart).
     *
     * @param  array<string,mixed>  $layout
     * @return list<string>
     */
    protected function cartTargets(array $layout): array
    {
        $out = [];
        foreach ($layout['sections'] ?? [] as $sid => $section) {
            if ($section['type'] === 'floating-cart' && ($section['disabled'] ?? false) !== true) {
                $out[] = "sections.{$sid}";

                continue;
            }
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                if (($block['settings']['target'] ?? null) === 'cart') {
                    $out[] = "sections.{$sid}.blocks.{$bid}";
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $layout
     */
    protected function hasEnabled(array $layout, string $type): bool
    {
        foreach ($layout['sections'] ?? [] as $section) {
            if ($section['type'] === $type && ($section['disabled'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $layout
     */
    protected function firstOfType(array $layout, string $type): ?string
    {
        foreach ($layout['order'] ?? [] as $sid) {
            if (($layout['sections'][$sid]['type'] ?? null) === $type) {
                return (string) $sid;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $layout
     */
    protected function lastOfType(array $layout, string $type): ?string
    {
        foreach (array_reverse($layout['order'] ?? []) as $sid) {
            if (($layout['sections'][$sid]['type'] ?? null) === $type) {
                return (string) $sid;
            }
        }

        return null;
    }

    /**
     * First enabled section id that is not page chrome.
     *
     * @param  array<string,mixed>  $layout
     */
    protected function firstContentSection(array $layout): ?string
    {
        foreach ($layout['order'] ?? [] as $sid) {
            $section = $layout['sections'][$sid] ?? null;
            if (! is_array($section) || ($section['disabled'] ?? false) === true) {
                continue;
            }
            if (($this->schema($section['type'])['group'] ?? '') !== 'chrome') {
                return (string) $sid;
            }
        }

        return null;
    }

    /**
     * How many sections at the top of the page are chrome (announcement, header).
     *
     * @param  array<string,mixed>  $layout
     */
    protected function leadingChromeCount(array $layout): int
    {
        $n = 0;
        foreach ($layout['order'] ?? [] as $sid) {
            if (($this->schema((string) ($layout['sections'][$sid]['type'] ?? ''))['group'] ?? '') !== 'chrome') {
                break;
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param  array<string,mixed>  $layout
     */
    protected function freeId(array $layout, string $base): string
    {
        $id = $base;
        for ($i = 2; isset($layout['sections'][$id]); $i++) {
            $id = $base.'-'.$i;
        }

        return $id;
    }

    /**
     * @param  array<string,mixed>  $layout
     */
    protected function remove(array &$layout, string $sid): void
    {
        unset($layout['sections'][$sid]);
        $layout['order'] = array_values(array_diff($layout['order'] ?? [], [$sid]));
    }
}
