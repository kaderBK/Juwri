<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** P1 (feature-platform): immutable log of every grant/revoke. */
class EntitlementAudit extends Model
{
    use BelongsToAccount;

    protected $table = 'entitlement_audit_logs';

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
