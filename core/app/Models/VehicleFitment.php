<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * rev/multi-vertical Lane E1 — one Year/Make/Model(/Submodel/Engine)
 * compatibility row for a car-parts item (DECISIONS A8). A part has MANY of
 * these (e.g. "fits a 2015-2018 Civic" is 4 rows, one per year).
 */
class VehicleFitment extends Model
{
    use BelongsToAccount;

    public $table = 'vehicle_fitments';

    public $timestamps = true;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
    ];

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** A short, human-readable label, e.g. "2016 Honda Civic EX (2.0L)". */
    public function getLabelAttribute(): string
    {
        $parts = [(string) $this->year, $this->make, $this->model];
        if ($this->submodel) {
            $parts[] = $this->submodel;
        }
        $label = implode(' ', array_filter($parts));
        if ($this->engine) {
            $label .= ' ('.$this->engine.')';
        }

        return $label;
    }
}
