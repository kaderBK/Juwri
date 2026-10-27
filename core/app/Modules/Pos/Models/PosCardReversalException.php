<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** P5-28: a provider-confirmed card refund/void the POS could not book (see the pos_card_reversal_exceptions migration). */
class PosCardReversalException extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_card_reversal_exceptions';

    protected $guarded = [];

    protected $casts = ['store_id' => 'integer', 'order_id' => 'integer', 'amount_minor' => 'integer', 'staff_id' => 'integer',
        'resolved_by_staff_id' => 'integer', 'resolved_at' => 'immutable_datetime'];
}
