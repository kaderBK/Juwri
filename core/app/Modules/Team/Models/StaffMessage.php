<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Staff PWA SP9 #41/#42: one post in a restaurant's staff comms thread. kind is
 * 'message' (staff-to-staff) or 'broadcast' (a manager announcement that also pushes).
 */
class StaffMessage extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
}
