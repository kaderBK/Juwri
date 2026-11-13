<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Menu\Models\Menu;

class RecipeItem extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** Line cost = quantity per portion × ingredient unit cost. */
    public function getLineCostAttribute(): float
    {
        $cost = $this->ingredient->cost_per_unit ?? 0;
        return round((float) $this->quantity * (float) $cost, 2);
    }
}
