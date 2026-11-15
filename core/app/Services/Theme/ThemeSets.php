<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Theme;

use App\Services\Verticals\VerticalRegistry;

/**
 * The THEME SETS — the vertical families a storefront theme belongs to.
 *
 * A set is what a theme is OFFERED by. A restaurant store is offered the
 * 'food' set; a clothing, retail, cosmetic, car-parts or pharmacy store the
 * 'retail' set; a laundry store the 'service' set; a hotel the 'hotel' set.
 *
 * The set a vertical belongs to is DECLARED in config/verticals.php
 * (`types.<vertical>.themes`) — the platform's single source of truth, read
 * through the VerticalRegistry and never forked here. That is what makes the
 * registration open: a theme for ANY vertical can be submitted, and the
 * marketplace side (the `themes.theme_set` column, the catalogue filter, the
 * card badge) derives its set from the same declaration. Adding a vertical
 * that names a new set registers that set automatically — no new column, no
 * new enum, no controller change.
 *
 * Sibling of `VerticalThemeSets`, which answers the same question for the
 * first-party Blade templates (which slug belongs to which set). This class
 * owns the VOCABULARY; that one owns the slug→set map.
 */
class ThemeSets
{
    /**
     * The default set: a theme that declares no vertical is a food theme —
     * that is where every marketplace theme sat before the sets existed.
     */
    public const DEFAULT = 'food';

    /**
     * The display name of each set, as the English source string.
     *
     * Every one of these is an EXISTING lang key (the helper slugifies it:
     * 'Retail' → `lang.retail`), so a set label never auto-registers a new
     * key into the lang files.
     *
     * The default set carries NO BADGE on purpose: a food theme needs none,
     * it is the catalogue's baseline. It still has a NAME, because the
     * catalogue's set filter has to name it.
     *
     * @var array<string,string>
     */
    private const LABELS = [
        'food' => 'Food',
        'retail' => 'Retail',
        'service' => 'Services',
        'hotel' => 'Hotel',
    ];

    /**
     * Store-family names used in first-party theme manifests (`theme.json`
     * "vertical") mapped to their theme set.
     *
     * @var array<string,string>
     */
    private const FAMILY_SETS = [
        'food' => 'food',
        'goods' => 'retail',
        'services' => 'service',
        'stays' => 'hotel',
    ];

    /**
     * Every set the platform knows, keyed by set name.
     *
     * Collected from the verticals config (so a vertical that names a new
     * set registers it) plus the labels above (so a set that is defined but
     * no vertical currently uses is still known).
     *
     * @return array<string,string> set => translated display name
     */
    public function all(): array
    {
        $sets = [];

        foreach (app(VerticalRegistry::class)->all() as $vertical) {
            $set = (string) ($vertical['themes'] ?? self::DEFAULT);
            $sets[$set] = $this->name($set) ?? self::humanise($set);
        }

        foreach (array_keys(self::LABELS) as $set) {
            $sets[$set] ??= $this->name($set) ?? self::humanise($set);
        }

        ksort($sets);

        return $sets;
    }

    /**
     * The badge label for a set, or null when the set needs no badge.
     *
     * A set nobody declared a label for still gets one, built from its key —
     * a theme submitted for a brand-new set is never shown without a badge.
     */
    public function label(?string $set): ?string
    {
        $set = trim((string) $set);
        if ($set === '' || $set === self::DEFAULT) {
            return null;
        }

        return $this->name($set);
    }

    /**
     * The display name of a set, for the catalogue's set filter.
     *
     * Null only for an empty set.
     *
     * The translation happens ONCE, from the English source string the label
     * map holds. Translating an already-translated value would slugify the
     * ARABIC text (and auto-register the result as a new lang key).
     */
    public function name(?string $set): ?string
    {
        $set = trim((string) $set);
        if ($set === '') {
            return null;
        }

        if (isset(self::LABELS[$set])) {
            return ___(self::LABELS[$set]);
        }

        return self::humanise($set);
    }

    /** 'spa-days' → 'Spa days' — for a set nobody declared a name for. */
    private static function humanise(string $set): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $set));
    }

    /** Is this a set the platform knows? */
    public function isKnown(?string $set): bool
    {
        $set = trim((string) $set);

        return $set !== '' && array_key_exists($set, $this->all());
    }

    /**
     * The set a vertical, store family, or set name belongs to — named here so
     * the marketplace side has one entry point.
     */
    public function forVertical(?string $vertical): string
    {
        $vertical = trim((string) $vertical);
        if ($vertical === '') {
            return self::DEFAULT;
        }
        if (isset(self::FAMILY_SETS[$vertical])) {
            return self::FAMILY_SETS[$vertical];
        }
        if ($this->isKnown($vertical)) {
            return $vertical;
        }

        return app(VerticalRegistry::class)->themeSet($vertical);
    }

    /**
     * Whether a theme manifest's "vertical" value is valid: a known store
     * type, a store family name (food|goods|services|stays), or a theme set.
     */
    public function isValidManifestVertical(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        return app(VerticalRegistry::class)->exists($value)
            || array_key_exists($value, self::FAMILY_SETS)
            || $this->isKnown($value);
    }

    /** @return list<string> */
    public function validManifestVerticals(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(app(VerticalRegistry::class)->all()),
            array_keys(self::FAMILY_SETS),
            array_keys($this->all()),
        )));
    }

    /** The set a store TYPE belongs to (a store's vertical IS its type). */
    public function forStoreType(?string $storeType): string
    {
        return $this->forVertical($storeType);
    }
}
