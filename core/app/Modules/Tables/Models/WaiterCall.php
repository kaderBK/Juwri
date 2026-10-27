<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class WaiterCall extends Model
{
    use BelongsToAccount;
    use HasFactory;

    public $table = 'waiter_calls';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * The store whose table called the waiter.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }
}
