<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * PARITY-RUN T3 — one verified (or pending) dine-in scan session.
 *
 * The guest holds only `token` (in an encrypted cookie). Everything that decides
 * whether an order may be placed — which restaurant, which table key, which day,
 * which method, whether it was actually verified — lives here, server-side.
 *
 * @see \Juwri\Modules\Tables\Services\TableSessionService
 */
class TableSession extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'table_sessions';

    protected $guarded = [];

    protected $casts = [
        'day' => 'date',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'orders_count' => 'integer',
        'failed_attempts' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function table()
    {
        return $this->belongsTo(StoreTable::class, 'table_id');
    }

    /** Verified AND not expired. A session that only exists is not yet a pass. */
    public function isUsable(): bool
    {
        if ($this->verified_at === null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Does this session authorise ordering for the given table? Deliberately a
     * strict match — the whole point of the feature is that table A's cookie is
     * worthless at table B. Either identifier may be supplied (the QR carries
     * the key, the order payload carries the number); a supplied one that
     * disagrees is a refusal, never a shrug.
     */
    public function coversTable(?string $tableKey, ?string $tableNo = null): bool
    {
        $matched = false;

        if ($tableKey !== null && $tableKey !== '') {
            if (! hash_equals((string) $this->table_key, $tableKey)) {
                return false;
            }
            $matched = true;
        }

        if ($tableNo !== null && $tableNo !== '') {
            if ($this->table_no === null || ! hash_equals((string) $this->table_no, (string) $tableNo)) {
                return false;
            }
            $matched = true;
        }

        return $matched;
    }
}
