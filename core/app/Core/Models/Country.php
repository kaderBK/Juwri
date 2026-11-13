<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'capital',
        'continent',
        'continent_code',
        'phone',
        'currency',
        'symbol',
        'alpha_3',
        'default_locale',
        'locales',
        'tax_profile',
        'payment_providers',
        'mk_active',
    ];

    protected $casts = [
        'locales' => 'array',
        'tax_profile' => 'array',
        'payment_providers' => 'array',
        'mk_active' => 'boolean',
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function scopeMkActive($query)
    {
        return $query->where('mk_active', true);
    }

    // Marketplace-spec aliases over the legacy reference columns.
    public function getIso2Attribute(): ?string
    {
        return $this->code;
    }

    public function getCurrencyCodeAttribute(): ?string
    {
        return $this->currency;
    }

    public function getPhonePrefixAttribute(): ?string
    {
        return $this->phone;
    }
}
