<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P4-21: one store event queued for Odoo (a paid order or a refund / void) and what Odoo answered.
 *
 * @property int $id
 * @property int $store_id
 * @property string $kind order|refund
 * @property int $source_id the order id (order) or the order_adjustments id (refund)
 * @property int $order_id
 * @property string $reference the order's UUIDv7 (order) or "<uuid>/refund/<adjustment id>" (refund)
 * @property string $status pending|retrying|synced|failed|skipped
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $result
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 */
class OdooSyncRecord extends Model
{
    use BelongsToAccount;

    protected $table = 'odoo_sync_records';

    protected $guarded = ['id', 'account_id'];

    protected $casts = [
        'store_id' => 'integer',
        'source_id' => 'integer',
        'order_id' => 'integer',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
        'result' => 'array',
    ];
}
