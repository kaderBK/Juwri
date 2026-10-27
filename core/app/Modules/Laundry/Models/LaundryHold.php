<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;

/**
 * P3-11 (API-23): a 10-minute hold on the laundry's pickup slot and, for a delivery order, the delivery-back slot — one
 * row, one token, one release. Once the order is placed the hold keeps the delivery-back time (`order_id`), which is
 * what the order's tracking page, the board and the slot capacity read.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property string $mode
 * @property Carbon $pickup_at
 * @property Carbon|null $return_at
 * @property Carbon $expires_at
 * @property string $token_hash
 * @property string|null $holder_key
 * @property string $status
 * @property int|null $order_id
 */
class LaundryHold extends Model
{
    use BelongsToAccount;
    use HasUuids;
    use StoresUtc;

    public const MINUTES = 10;

    protected $table = 'laundry_slot_holds';

    protected $guarded = ['id', 'account_id'];

    protected $hidden = ['token_hash', 'holder_key'];

    protected $casts = [
        'store_id' => 'integer',
        'order_id' => 'integer',
        'pickup_at' => 'datetime',
        'return_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function isLive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function tokenMatches(string $token): bool
    {
        return $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }
}
