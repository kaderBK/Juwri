<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use App\Models\KitchenStation;
use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * FEATURES-RUN-4 T1 (hotel): a guest service request raised from a room QR
 * (H5). Routed to a department — a {@see KitchenStation} row referenced by
 * `department_id`. Its own table; leaves the existing `waiter_calls` signal
 * untouched.
 */
class ServiceRequest extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'service_requests';

    public const TYPES = ['housekeeping', 'maintenance', 'amenity', 'staff_call', 'custom'];

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    protected $guarded = [];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** The room/area the request came from (store_tables row). */
    public function room()
    {
        return $this->belongsTo(StoreTable::class, 'table_id');
    }

    public function stay()
    {
        return $this->belongsTo(HotelStay::class, 'stay_id');
    }

    /** The department handling this request (a kitchen_stations row). */
    public function department()
    {
        return $this->belongsTo(KitchenStation::class, 'department_id');
    }

    public function assignedStaff()
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }
}
