<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use App\Services\Theme\ThemeSets;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\AppStore\Models\MarketplaceReview;

/**
 * P2-6: marketplace theme identity (listing-level). Files live on disk;
 * versions are ThemeVersion rows. seller_id null = platform-owned.
 *
 * P1-05: `price` is stored as `price_minor` in the row's `currency_code` (the platform currency; a catalogue row). It
 * reads as a number (the column was uncast). Query with `price_minor`.
 *
 * @property float $price major amount
 * @property int $price_minor
 * @property string|null $currency_code
 */
class Theme extends Model implements MinorMoneyModel
{
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['price' => 'price_minor'];

    protected $casts = [
        'translations' => 'array', // {locale: {tagline, description, notes}} — display only
    ];

    /**
     * Blade showcase listing (a first-party post_template surfaced in the store)
     * vs. the default installable Liquid theme. Blade rows carry a `template_slug`
     * and `demo_slug` and are applied as a PLAIN slug, never `mp:<slug>@<ver>`.
     */
    public function isBlade(): bool
    {
        return $this->engine === 'blade';
    }

    /** Public URL of the live demo store that previews this Blade theme, if set. */
    public function demoUrl(): ?string
    {
        return $this->demo_slug ? url('/'.$this->demo_slug) : null;
    }

    /**
     * The theme set a manifest targets — the vertical family a store must be
     * in for the theme to be offered to it.
     *
     * The vertical comes from the manifest's own `vertical` key, or from the
     * recipe id it names ("clothing/default" → clothing), and the set from
     * the vertical registry (config/verticals.php: clothing.themes = retail).
     * A manifest with neither is a food theme — that is where every
     * marketplace theme sat before the sets were registered.
     *
     * Any vertical works: a theme for a pharmacy, a hotel or a laundry
     * store registers its own set the same way.
     *
     * @param  array<string,mixed>  $manifest
     */
    public static function setForManifest(array $manifest): string
    {
        $vertical = is_string($manifest['vertical'] ?? null) ? trim($manifest['vertical']) : '';
        if ($vertical === '') {
            $recipe = is_string($manifest['recipe'] ?? null) ? $manifest['recipe'] : '';
            if (str_contains($recipe, '/')) {
                $vertical = trim(explode('/', $recipe)[0]);
            }
        }
        if ($vertical === '') {
            return ThemeSets::DEFAULT;
        }

        return app(ThemeSets::class)->forVertical($vertical);
    }

    /**
     * Is this theme offered to a store of the given vertical? The offerability
     * rule: a retail theme is for retail stores, a food theme for restaurants.
     *
     * A store that ALREADY runs this theme keeps it (grandfathered): a theme
     * installed before the sets existed carries the default set, and locking
     * its owner out of their own storefront would be a worse bug than the
     * mis-filed set. This guards a fresh switch only.
     */
    public function fitsStore(?string $storeType, ?string $currentTemplate = null): bool
    {
        if ($currentTemplate !== null && $this->runsOn($currentTemplate)) {
            return true;
        }

        return (string) $this->theme_set === app(ThemeSets::class)->forStoreType($storeType);
    }

    /**
     * Does this theme already run on the given template value?
     *
     * The template value is whatever the store's `store_template` option
     * holds: a marketplace theme is "mp:<slug>@<version>", a Blade theme the
     * plain slug.
     */
    public function runsOn(string $currentTemplate): bool
    {
        $current = trim($currentTemplate);
        if ($current === '') {
            return false;
        }
        if (str_starts_with($current, 'mp:')) {
            $current = substr($current, 3);
        }

        return explode('@', $current)[0] === $this->slug;
    }

    /**
     * A human label for a theme set, so a retail theme says "Retail" on the
     * catalogue card instead of passing for a restaurant's. Null for the
     * default set (food) — that one needs no badge.
     */
    public static function setLabel(?string $set): ?string
    {
        return app(ThemeSets::class)->label($set);
    }

    /**
     * Active-locale tagline for cards/detail; falls back to the stored English.
     * Theme NAMES are deliberately left untranslated (product names).
     */
    public function getTaglineAttribute(?string $value): ?string
    {
        return $this->localizedField('tagline', $value);
    }

    /** Active-locale About copy for the detail page, else the given fallback. */
    public function localizedDescription(?string $fallback): ?string
    {
        return $this->localizedField('description', $fallback);
    }

    /** Active-locale demo disclaimer, else the stored English `demo_notes`. */
    public function localizedNotes(): ?string
    {
        return $this->localizedField('notes', $this->demo_notes);
    }

    /** Active-locale translation for $field, else the stored $value. Null-safe. */
    protected function localizedField(string $field, ?string $value): ?string
    {
        $t = $this->translations[app()->getLocale()] ?? null;
        if (is_array($t) && isset($t[$field]) && is_string($t[$field]) && $t[$field] !== '') {
            return $t[$field];
        }

        return $value;
    }

    public function versions()
    {
        return $this->hasMany(ThemeVersion::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function media()
    {
        return $this->morphMany(MarketplaceMedia::class, 'mediable')->orderBy('position');
    }

    /** Screenshots only, ordered — the gallery source for the detail page. */
    public function screenshots()
    {
        return $this->media()->where('kind', 'screenshot');
    }

    public function reviews()
    {
        return $this->morphMany(MarketplaceReview::class, 'reviewable');
    }
}
