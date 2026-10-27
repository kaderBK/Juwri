<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * @property int|null $branch_id P6-224: the branch counted (null = store-wide)
 */
class StockCount extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'counted_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
    }
}
