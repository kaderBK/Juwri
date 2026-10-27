<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Zone;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'name',
        'timezone',
        'lat',
        'lng',
        'active',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
