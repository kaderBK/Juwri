<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** P5-21: one SoftPOS charge / refund / void, idempotent per (store, client_uuid). */
class TerminalPayment extends Model
{
    use BelongsToAccount;

    protected $table = 'terminal_payments';

    protected $guarded = [];

    protected $casts = ['store_id' => 'integer', 'parent_id' => 'integer', 'amount_minor' => 'integer', 'refunded_minor' => 'integer', 'order_id' => 'integer', 'tip_minor' => 'integer', 'settled_at' => 'datetime'];
}
