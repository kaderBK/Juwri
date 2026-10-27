<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Abuse Guard P7: one per-tenant, per-day resource-usage counter.
 * Written through TenantUsageDaily::bump(). One row per (date, user_id, metric).
 */
class TenantUsageDaily extends Model
{
    use BelongsToAccount;

    protected $table = 'tenant_usage_daily';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    /**
     * Upsert-increment today's counter for a tenant metric.
     */
    public static function bump(int $userId, string $metric, int $by = 1, ?int $resellerId = null, $date = null): void
    {
        $date = $date ?: now()->toDateString();

        $row = static::firstOrCreate(
            ['date' => $date, 'user_id' => $userId, 'metric' => $metric],
            ['reseller_id' => $resellerId, 'value' => 0]
        );

        $row->increment('value', $by);

        if ($resellerId !== null && $row->reseller_id === null) {
            $row->update(['reseller_id' => $resellerId]);
        }
    }
}
