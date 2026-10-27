<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Ingredient;

class Supplier extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'lead_time_days' => 'integer',
        'active'         => 'boolean',
    ];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function ingredients()
    {
        return $this->hasMany(Ingredient::class);
    }
}
