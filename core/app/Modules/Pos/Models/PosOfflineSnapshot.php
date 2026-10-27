<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * One version of a till's offline catalogue snapshot (OFFLINE-POS-SPEC §2).
 *
 * @property int $id
 * @property int $store_id
 * @property int $branch_id
 * @property int $version
 * @property string $hash
 * @property string $payload
 */
class PosOfflineSnapshot extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    protected $table = 'pos_offline_snapshots';

    protected $guarded = [];

    protected $casts = ['store_id' => 'integer', 'branch_id' => 'integer', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
}
