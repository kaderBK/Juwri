<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class StoreView extends Model
{
    use BelongsToAccount;
    use HasFactory;

    public $table = 'store_page_views';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'store_id',
        'ip',
        'date',
        'device',
        'lang',
        'source',
        'dwell_ms',
        'exit_section',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
