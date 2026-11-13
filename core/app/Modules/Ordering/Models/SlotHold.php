<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-09 (API-3, API-11): a 10-minute hold of one delivery / collection slot. `active` until it expires, is released or
 * an order takes it: a pay-later order uses it (`used`), a hosted order binds it (`bound`, expiry moved to the end of
 * the payment window, API-10) until the order is paid (`used`) or the window ends (`expired`).
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int|null $branch_id
 * @property string $order_type
 * @property string $slot_id
 * @property Carbon $slot_starts_at
 * @property Carbon $slot_ends_at
 * @property Carbon $expires_at
 * @property string $token_hash
 * @property string|null $holder_key
 * @property string|null $idempotency_key
 * @property string $status
 * @property int|null $order_id
 */
class SlotHold extends Model
{
    use BelongsToAccount;
    use HasUuids;

    public const MINUTES = 10;

    protected $table = 'slot_holds';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'slot_starts_at' => 'datetime',
        'slot_ends_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** Is the hold still holding its slot (active or bound, not past its expiry)? */
    public function isLive(): bool
    {
        return in_array($this->status, ['active', 'bound'], true) && $this->expires_at->isFuture();
    }

    public function tokenMatches(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }
}
