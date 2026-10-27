<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-09: a store's delivery / collection time slots (`branch_id` NULL = the store's rule; a branch row overrides it for
 * that branch). Hourly slots inside `windows` (store time), `capacity_per_hour` orders per branch per hourly slot
 * (0 = no limit), bookable from `lead_minutes` ahead for `days_ahead` days (today included), an express option
 * (`express_fee_minor` NULL = off) and a radius in km around the branch (or `base_lat`/`base_lng`).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $branch_id
 * @property bool $active
 * @property array<int, mixed> $windows stored JSON: [["09:00", "12:00"], …] (DeliverySlots::windows() validates it)
 * @property int $capacity_per_hour
 * @property int $lead_minutes
 * @property int $days_ahead
 * @property int|null $express_fee_minor
 * @property int|null $express_eta_from
 * @property int|null $express_eta_to
 * @property string|null $radius_km
 * @property string|null $base_lat
 * @property string|null $base_lng
 * @property string|null $currency_code
 */
class DeliverySlotRule extends Model
{
    use BelongsToAccount;

    protected $table = 'delivery_slot_rules';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'windows' => 'array',
        'active' => 'boolean',
        'capacity_per_hour' => 'integer',
        'lead_minutes' => 'integer',
        'days_ahead' => 'integer',
        'express_fee_minor' => 'integer',
        'express_eta_from' => 'integer',
        'express_eta_to' => 'integer',
    ];
}
