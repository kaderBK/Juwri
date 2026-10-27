<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * PARITY-RUN T6: one choice group inside a combo — "Choose your drink", pick 1.
 *
 * min_select / max_select carry the "pick N" rule. The data model supports N > 1
 * from day one and {@see \Juwri\Modules\Menu\Services\Combos\ComboService} enforces it
 * server-side; the shipped storefront UI renders single-choice slots (the T6
 * spec's deliberate deferral), so a multi-pick slot is reachable today only from
 * the API/POS payload.
 */
class ComboSlot extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
    ];

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }

    public function options()
    {
        return $this->hasMany(ComboSlotOption::class)->orderBy('position')->orderBy('id');
    }

    /** Is this slot optional (the diner may skip it entirely)? */
    public function isOptional(): bool
    {
        return (int) $this->min_select <= 0;
    }

    /** 0 / null = unlimited, matching MenuVariantOption::max_select. */
    public function effectiveMax(): int
    {
        return (int) $this->max_select;
    }
}
