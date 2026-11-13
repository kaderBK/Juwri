<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class ExternalInvoice extends Model
{
    use BelongsToAccount;

    protected $table = 'external_invoices';
    protected $guarded = [];
}
