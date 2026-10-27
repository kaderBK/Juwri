<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class Allergy extends Model
{
    use BelongsToAccount;
    use HasFactory, TranslatesForStorefront;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'store_id',
        'title',
        'image',
        'translations',
        'position',
        'active',
    ];

    /**
     * Active allergens available to a restaurant: the global (superadmin)
     * catalog plus that restaurant's own owner-defined allergens.
     */
    public function scopeAvailableFor($query, $storeId)
    {
        return $query->where('active', '1')
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            });
    }

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
    ];

    /**
     * Localised title for the storefront: return the visitor-language title from
     * `translations->{lang}->title` when present, else the base `title`. Mirrors
     * Menu::getNameAttribute so allergen chips render in the diner's language
     * instead of the owner's base entry. `Quick_user_lang_code` is the storefront
     * locale cookie (unset in the admin dashboard, so admins see the base title).
     * The base title is still read raw via getRawOriginal('title') in the admin
     * edit form, so editing is never fed the localised value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function getTitleAttribute($value)
    {
        return $this->storefrontTranslation('title') ?? $value;
    }
}
