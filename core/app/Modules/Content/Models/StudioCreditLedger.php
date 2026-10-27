<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

class StudioCreditLedger extends Model
{
    protected $table = 'studio_credit_ledger_entries';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = ['delta' => 'integer'];
}
