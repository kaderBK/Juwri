<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Abuse Guard: one warn/deny event. Written by Juwri\Core\Services\AbuseGuard::hit().
 * created_at only (no updated_at) — events are append-only evidence.
 */
class AbuseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];
}
