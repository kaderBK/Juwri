<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Inbound/outbound HungerStation API event log (for owner visibility + debugging).
 * Secrets must be redacted before writing here.
 */
class HsEventLog extends Model
{
    use BelongsToAccount;

    protected $table = 'hungerstation_event_logs';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'request'    => 'array',
        'response'   => 'array',
        'ok'         => 'boolean',
        'created_at' => 'datetime',
    ];

    public static function record(string $direction, string $kind, array $data = []): self
    {
        return self::create(array_merge([
            'direction'  => $direction,
            'kind'       => $kind,
            'ok'         => true,
            'created_at' => now(),
        ], $data));
    }
}
