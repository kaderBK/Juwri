<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Models\User;
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
