<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * PARITY-RUN T4 — a live (or historical) merge of two or more tables onto one
 * bill. `released_at === null` means the merge is live.
 *
 * The group owns NO money. The bill for a merged party is still the sum of the
 * individual orders' own `Order::getTotalAttribute()` values, because a merge
 * moves orders, it never re-creates or re-prices them. See TableMergeService.
 */
class TableMergeGroup extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'table_merge_groups';

    protected $guarded = [];

    protected $casts = [
        'released_at' => 'datetime',
        'primary_table_id' => 'integer',
        'store_id' => 'integer',
        'branch_id' => 'integer',
    ];

    public function members()
    {
        return $this->hasMany(TableMergeMember::class, 'group_id');
    }

    public function tables()
    {
        return $this->belongsToMany(StoreTable::class, 'table_merge_members', 'group_id', 'table_id')
            ->withPivot('is_primary');
    }

    public function primaryTable()
    {
        return $this->belongsTo(StoreTable::class, 'primary_table_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Live merges only. */
    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /** The secondary (non-primary) table ids of this group. */
    public function secondaryTableIds(): array
    {
        return $this->members
            ->reject(fn ($m) => (bool) $m->is_primary)
            ->pluck('table_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
