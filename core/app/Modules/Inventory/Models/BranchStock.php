<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Branch;
use App\Models\Ingredient;

class BranchStock extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'branch_stock_levels';

    protected $guarded = [];

    protected $casts = [
        'current_stock' => 'decimal:3',
        'par_level'     => 'decimal:3',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function getIsLowAttribute(): bool
    {
        return $this->par_level > 0 && $this->current_stock <= $this->par_level;
    }
}
