<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P3: a staff member's weekly availability preference. */
class StaffAvailability extends Model
{
    use BelongsToAccount;

    protected $table = 'staff_availabilities';
    protected $guarded = [];
}
