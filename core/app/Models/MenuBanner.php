<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Observers\MenuBannerObserver;

#[ObservedBy([MenuBannerObserver::class])]
class MenuBanner extends Model
{
    use BelongsToAccount;
    use HasFactory;
    use TranslatesForStorefront;

    protected $guarded = [];

    // The `image` column is NOT NULL with no DB default, so a banner created
    // without one (e.g. a text-only promo) fails under MySQL strict mode
    // (STRICT_TRANS_TABLES on prod). Default it to '' at the model level so every
    // create path is strict-safe; callers still set a real image where they have one.
    protected $attributes = [
        'image' => '',
    ];

    protected $casts = [
        'active' => 'boolean',
        'translations' => 'object',
    ];

    protected $appends = ['image_url'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function getImageUrlAttribute(): string
    {
        // Banner images are library-managed (folder=banner); never unlinked on row delete.
        return ! empty($this->image) ? media_url('storage/menu/', $this->image, $this->store_id) : '';
    }

    /**
     * Localize the title for the storefront visitor's language, falling back to
     * the base (default-language) value. Mirrors Menu::getNameAttribute; the
     * Quick_user_lang_code cookie is only set on the storefront (unset in admin).
     *
     * @return mixed
     */
    public function getTitleAttribute(?string $value)
    {
        return $this->storefrontTranslation('title') ?? $value;
    }

    /**
     * Localize the subtitle for the storefront visitor's language, falling back
     * to the base (default-language) value. Mirrors Menu::getDescriptionAttribute.
     *
     * @return mixed
     */
    public function getSubtitleAttribute(?string $value)
    {
        return $this->storefrontTranslation('subtitle') ?? $value;
    }
}
