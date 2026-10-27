<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P4-22: one switching import of a store (menu, customers or loyalty balances from a file or the previous Odoo).
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property string $dataset menu|customers|loyalty
 * @property string $source csv|xlsx|odoo
 * @property string $mode add|update
 * @property string|null $file_hash
 * @property string $status previewed|queued|running|committed|failed|discarded
 * @property int $rows_total
 * @property int $rows_done
 * @property string|null $error
 * @property list<array<string, string>>|null $rows encrypted at rest; cleared after the commit
 * @property array<string, mixed>|null $plan
 * @property array<string, mixed>|null $summary
 * @property Carbon|null $committed_at
 * @property Carbon|null $created_at
 */
class SwitchImport extends Model
{
    use BelongsToAccount;

    protected $table = 'switch_imports';

    protected $guarded = ['id', 'account_id'];

    protected $hidden = ['rows'];

    protected $casts = [
        'store_id' => 'integer',
        'user_id' => 'integer',
        'rows_total' => 'integer',
        'rows_done' => 'integer',
        'rows' => 'encrypted:array',
        'plan' => 'array',
        'summary' => 'array',
        'committed_at' => 'datetime',
    ];
}
