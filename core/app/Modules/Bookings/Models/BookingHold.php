<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;

/**
 * P3-10: a 10-minute hold on one staff member's time (API-11). Its cells in booking_slot_cells are what keeps the
 * time; the token is shown once and only its SHA-256 is stored.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int $staff_id
 * @property int|null $booking_id
 * @property list<int> $service_ids
 * @property string $location
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $block_starts_at
 * @property Carbon $block_ends_at
 * @property Carbon $expires_at
 * @property string $token_hash
 * @property string|null $holder_key
 * @property string $status
 */
class BookingHold extends Model
{
    use BelongsToAccount;
    use StoresUtc;
    use HasUuids;

    public const MINUTES = 10;

    protected $table = 'booking_holds';

    protected $guarded = ['id', 'account_id'];

    protected $hidden = ['token_hash', 'holder_key'];

    protected $casts = [
        'store_id' => 'integer',
        'staff_id' => 'integer',
        'booking_id' => 'integer',
        'service_ids' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'block_starts_at' => 'datetime',
        'block_ends_at' => 'datetime',
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
