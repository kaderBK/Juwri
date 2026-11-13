<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** A card payment taken offline (see the pos_offline_card_payments migration). */
class PosOfflineCardPayment extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_offline_card_payments';

    protected $guarded = [];

    protected $casts = ['store_id' => 'integer', 'order_id' => 'integer', 'amount_minor' => 'integer', 'taken_at' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
}
