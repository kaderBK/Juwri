<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** A simplified invoice a till signed offline (OP-12), its chain check and its ZATCA reporting state. */
class PosOfflineZatcaInvoice extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_offline_zatca_invoices';

    protected $guarded = [];

    protected $casts = ['store_id' => 'integer', 'unit_id' => 'integer', 'icv' => 'integer', 'attempts' => 'integer',
        'report_response' => 'array', 'reported_at' => 'immutable_datetime'];
}
