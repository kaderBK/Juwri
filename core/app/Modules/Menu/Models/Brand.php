<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * rev/multi-vertical Lane A3 — a per-store brand (Nike, L'Oréal, …). Two
 * different stores each keep their own row even for the same real-world brand
 * name; there is no cross-store brand catalog (out of scope, see A1).
 */
class Brand extends Model
{
    use BelongsToAccount;

    public $timestamps = true;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return HasMany<Menu, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Menu::class, 'brand_id');
    }
}
