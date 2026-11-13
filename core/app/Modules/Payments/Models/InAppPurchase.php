<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** P5-17 owner-app in-app billing (see the inapp billing tables migration). */
class InAppPurchase extends Model
{
    use BelongsToAccount;

    protected $table = 'inapp_purchases';

    protected $guarded = [];

    protected $casts = ['user_id' => 'integer', 'plan_id' => 'integer', 'last_event_ms' => 'integer', 'expires_at' => 'immutable_datetime'];
}
