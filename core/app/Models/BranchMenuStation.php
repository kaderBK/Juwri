<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Menu\Models\Menu;

class BranchMenuStation extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'item_kitchen_stations';

    protected $guarded = [];

    public function station()
    {
        return $this->belongsTo(KitchenStation::class, 'station_id');
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
