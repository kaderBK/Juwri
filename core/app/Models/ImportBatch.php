<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** T7: audit + idempotency record for a committed import. */
class ImportBatch extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    /** P6-324: set by the importers when an identical file was already imported (no changes made). Never persisted. */
    public bool $already_imported = false;
}
