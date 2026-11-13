<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** Offline POS replay (OFFLINE-POS-SPEC §3): see the pos_offline_devices migration. */
class PosOfflineDevice extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_offline_devices';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'pepper', 'escrow'];

    protected $casts = ['store_id' => 'integer', 'last_seq' => 'integer', 'missing_seqs' => 'array', 'last_seen_at' => 'immutable_datetime',
        'pepper' => 'encrypted', 'escrow' => 'encrypted', 'registered_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'wiped_at' => 'immutable_datetime', 'wipe_requested_at' => 'immutable_datetime'];
}
