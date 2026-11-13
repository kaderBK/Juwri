<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Theme;

use App\Services\Verticals\VerticalRegistry;

/**
 * rev/multi-vertical Lane C (C4) — which storefront theme SET a filesystem
 * template (store_templates/<slug>) belongs to.
 *
 * `VerticalRegistry->themeSet($storeType)` returns one of the SAME keys
 * ('food'|'retail'|'service') declared in config/verticals.php `types.*.themes`
 * (a frozen F0 seam — read, never forked). A template is offered to a store
 * only when its own set matches the store's vertical set, so:
 *   - a `retail`/`cosmetic`/`clothing`/`carparts`/`pharmacy` store can pick
 *     `shelf` but never a food theme (crave, matcha, …);
 *   - a `laundry` store can pick `bookla` but never a food theme;
 *   - a `restaurant` store is completely unaffected (it never even sees
 *     shelf/bookla as options) — this class only ADDS two new slugs, every
 *     existing template stays mapped to 'food' by the default branch below.
 *
 * Deliberately NOT a change to `App\Services\StorefrontThemeService` (shared
 * infra used by all 45+ existing themes) — this is a small, additive,
 * Lane-C-owned filter that WRAPS its output. Adding a third generic theme
 * later is one entry in SET_MAP, never a change to a controller.
 */
class VerticalThemeSets
{
    /**
     * Non-food storefront themes, keyed by slug => theme-set. Any slug not
     * listed here is 'food' — i.e. every existing restaurant theme, which
     * keeps them byte-identical to before this class existed.
     *
     * @var array<string,string>
     */
    private const SET_MAP = [
        'shelf' => 'retail',
        'bookla' => 'service',
        // Premium per-vertical templates (multi-vertical-templates-loop). Goods
        // templates join the 'retail' set (offered to retail/cosmetic/clothing/
        // carparts/pharmacy); the laundry template joins the 'service' set.
        'torque' => 'retail',
        'freshly' => 'service',
        'carely' => 'retail',
        'zevoa' => 'retail',
        'maison' => 'retail',
        'lumiere' => 'retail',
        // Hotel super-app theme (its own set, offered only to hotel-vertical stores).
        'aurelia' => 'hotel',
        // Phase 3 kit themes (Juwri\Modules\Storefront\Services\Theme\KitThemes::SLUGS): table and brew are food
        // (the default branch already says 'food'; listed so the set is explicit and tested), aisle and basket
        // are goods (retail set), book is services (service set). They are only offered once a theme has views.
        'table' => 'food',
        'brew' => 'food',
        'aisle' => 'retail',
        'basket' => 'retail',
        'book' => 'service',
    ];

    /** The theme-set a template slug belongs to. Unknown/unlisted slugs are 'food'. */
    public function setFor(string $slug): string
    {
        return self::SET_MAP[$slug] ?? 'food';
    }

    /**
     * Filter a StorefrontThemeService::available()-shaped list (slug => meta) down
     * to the templates whose set matches the given store type's theme set.
     *
     * @param  array<string,array<string,mixed>>  $templates
     * @return array<string,array<string,mixed>>
     */
    public function filterFor(array $templates, ?string $storeType): array
    {
        $wanted = app(VerticalRegistry::class)->themeSet($storeType);

        return array_filter(
            $templates,
            fn (string $slug) => $this->setFor($slug) === $wanted,
            ARRAY_FILTER_USE_KEY
        );
    }
}
