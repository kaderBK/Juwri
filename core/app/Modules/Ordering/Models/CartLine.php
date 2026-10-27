<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class CartLine extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'menu_id',
        'quantity',
        'variation',
        'options',
        'extras',
        'note',
        // rev/multi-vertical Lane D2: per-kg service booking lines (see the
        // add_weight_kg_to_cart_lines_table migration) — quantity stays 1 for
        // a weighed line, the actual amount lives here.
        'weight_kg',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'options' => 'array',
        'extras' => 'array',
        'weight_kg' => 'float',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
