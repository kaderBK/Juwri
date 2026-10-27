<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class SmsLog extends Model
{
    use BelongsToAccount;

    protected $table = 'sms_logs';

    protected $guarded = [];
}
