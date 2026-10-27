<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** A till's ZATCA EGS unit (OP-12; see the pos_offline_zatca tables migration). Its private key is on the till only. */
class PosOfflineZatcaUnit extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_offline_zatca_units';

    protected $guarded = [];

    protected $hidden = ['compliance_secret', 'production_secret'];

    protected $casts = ['store_id' => 'integer', 'pos_offline_device_id' => 'integer', 'last_icv' => 'integer',
        'compliance_secret' => 'encrypted', 'production_secret' => 'encrypted',
        'production_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime'];
}
