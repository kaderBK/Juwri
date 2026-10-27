<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * HPMS A2/A9 — a housekeeping work item on a room (clean|inspect|maintenance),
 * open until a staff member marks it done. Checkout auto-opens a clean task and
 * flips the room's hk_status to 'dirty'; completing a clean/inspect task advances
 * the room's cleanliness. The room's own hk_status axis lives on
 * store_tables (never merged with occupancy). Store-scoped by store_id.
 */
class HousekeepingTask extends Model
{
    use BelongsToAccount;

    protected $table = 'housekeeping_tasks';

    public const TYPE_CLEAN = 'clean';

    public const TYPE_INSPECT = 'inspect';

    public const TYPE_MAINTENANCE = 'maintenance';

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'done_at' => 'datetime',
    ];

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<HousekeepingTask>  $q
     * @return \Illuminate\Database\Eloquent\Builder<HousekeepingTask>
     */
    public function scopeForRestaurant($q, int $storeId)
    {
        return $q->where('store_id', $storeId);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<StoreTable, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(StoreTable::class, 'room_id');
    }

    /** @return BelongsTo<HotelStay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(HotelStay::class, 'stay_id');
    }
}
