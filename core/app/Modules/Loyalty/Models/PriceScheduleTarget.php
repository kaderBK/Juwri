<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Models;

use App\Models\Concerns\BustsMenuCache;
use Illuminate\Database\Eloquent\Model;
use App\Models\PriceSchedule;

/**
 * PARITY-RUN T5: which items/categories a non-store-wide PriceSchedule covers.
 * No money here, so no cast concerns — the rule itself carries the amount.
 */
class PriceScheduleTarget extends Model
{
    use BustsMenuCache;

    public $timestamps = false;

    protected $guarded = [];

    public function schedule()
    {
        return $this->belongsTo(PriceSchedule::class, 'schedule_id');
    }

    /** P6-24b: retargeting a schedule changes which cached cards it discounts. */
    public function menuCacheRestaurantId(): ?int
    {
        $storeId = PriceSchedule::query()->whereKey($this->schedule_id)->value('store_id');

        return $storeId ? (int) $storeId : null;
    }
}
