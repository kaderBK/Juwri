<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HPMS E11: one row per owner-API-token request (method/path/status/duration).
 * Written after the response by LogApiRequest; pruned to 7 days daily.
 */
class ApiRequestLog extends Model
{
    public $timestamps = false; // only created_at, set explicitly

    protected $guarded = [];

    protected $casts = [
        'status'      => 'integer',
        'duration_ms' => 'integer',
        'created_at'  => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ApiToken, $this> */
    public function token(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'token_id');
    }
}
