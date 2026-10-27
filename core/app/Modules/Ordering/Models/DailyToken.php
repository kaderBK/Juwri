<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * PARITY-RUN T2: one counter row per (store, branch, store-local day).
 *
 * Never bump this directly — go through {@see \Juwri\Modules\Kitchen\Services\OrderBoard\DailyTokenService::next()},
 * which holds a row lock for the read-modify-write so two concurrent checkouts
 * cannot be handed the same number.
 */
class DailyToken extends Model
{
    use BelongsToAccount;

    protected $table = 'daily_tokens';

    protected $guarded = [];

    /**
     * `token_date` is deliberately NOT cast to a date. A `date` cast writes the
     * value back through the connection's datetime format ("Y-m-d H:i:s"), which
     * MySQL coerces into its DATE column but SQLite stores verbatim — so a
     * "Y-m-d" lookup would match in production and silently miss in the test
     * suite. The service always reads and writes a plain "Y-m-d" string.
     */
    protected $casts = [
        'store_id' => 'integer',
        'branch_id'     => 'integer',
        'last_seq'      => 'integer',
    ];
}
