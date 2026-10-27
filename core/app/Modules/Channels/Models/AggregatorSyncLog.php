<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * INTEGRATIONS-RUN: a menu-push / sync activity row for an aggregator channel.
 */
class AggregatorSyncLog extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    public static function record(int $storeId, string $provider, string $status, ?string $message = null): self
    {
        return static::create([
            'store_id' => $storeId,
            'provider' => $provider,
            'status' => $status,
            'message' => $message ? mb_substr($message, 0, 500) : null,
        ]);
    }
}
