<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Ingredient;

class StockCountItem extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'theoretical_qty' => 'decimal:3',
        'counted_qty'     => 'decimal:3',
        'variance'        => 'decimal:3',
    ];

    public function stockCount()
    {
        return $this->belongsTo(StockCount::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
