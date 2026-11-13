<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Juwri\Modules\Hr\Models;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
class StaffTimeClock extends Model
{
    use BelongsToAccount;

    protected $table='staff_time_clock_entries'; protected $guarded=[]; protected $casts=['clock_in_at'=>'datetime','clock_out_at'=>'datetime'];
}
