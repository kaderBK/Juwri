<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * theme-pack-blade · F2. A single customer→restaurant follow edge. The composite
 * unique on (customer_id, store_id) — enforced at the DB level — keeps follow
 * idempotent regardless of how many times the endpoint is hit.
 */
class StoreFollow extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'store_follows';

    protected $fillable = [
        'customer_id',
        'store_id',
        'notify',
    ];

    protected $casts = [
        'notify' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
