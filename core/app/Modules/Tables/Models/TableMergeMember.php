<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * PARITY-RUN T4 — one table's membership of a merge group.
 *
 * Membership lives here rather than as a column on `store_tables` so that
 * releasing a group keeps the record of what was merged (that row has
 * `$timestamps = false` and no history of its own).
 */
class TableMergeMember extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'table_merge_members';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'group_id' => 'integer',
        'table_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(TableMergeGroup::class, 'group_id');
    }

    public function table()
    {
        return $this->belongsTo(StoreTable::class, 'table_id');
    }
}
