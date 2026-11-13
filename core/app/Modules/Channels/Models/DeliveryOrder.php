<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class DeliveryOrder extends Model
{
    use BelongsToAccount;

    protected $table = 'delivery_orders';
    protected $guarded = [];
}
