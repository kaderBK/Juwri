<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** P5-21: a store's own SoftPOS acquirer account (credentials encrypted, never serialised). */
class TerminalAccount extends Model
{
    use BelongsToAccount;

    protected $table = 'terminal_accounts';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected $casts = ['store_id' => 'integer', 'enabled' => 'boolean', 'credentials' => 'encrypted:array'];
}
