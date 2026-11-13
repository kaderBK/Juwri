<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Menu\Models\Menu;

class KitchenStation extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'active'         => 'boolean',
        'is_expo'        => 'boolean',
        'position'       => 'integer',
        'target_minutes' => 'integer',
    ];

    public function menus()
    {
        return $this->hasMany(Menu::class, 'station_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'station_id');
    }
}
