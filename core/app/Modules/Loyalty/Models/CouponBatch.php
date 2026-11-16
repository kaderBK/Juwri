<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Models;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Coupons C1: a batch of generated coupon codes sharing one settings set. A batch belongs to exactly one issuer —
 * the platform (no user, no reseller), a reseller (`reseller_id`) or a store owner (`user_id`) — the same dimensions
 * as {@see Coupon::issuerKey()}. Read batches only through {@see \Juwri\Modules\Loyalty\Services\CouponBulk::batches()}
 * so a caller never sees another issuer's batch.
 *
 * Tenant table like `coupons` (TenantTables, user-owned): BelongsToAccount fills account_id from the owner's
 * account on create, applies the tenant scope (AccountScope) and resolves the tenant connection.
 *
 * @property int $id
 * @property int|null $account_id
 * @property string|null $name
 * @property string $issuer_scope
 * @property int|null $reseller_id
 * @property int|null $user_id
 * @property string|null $prefix
 * @property int $count
 * @property array<string, mixed>|null $settings
 * @property int|null $created_by
 */
class CouponBatch extends Model
{
    use BelongsToAccount;

    protected $table = 'coupon_batches';

    /** @var list<string> */
    protected $fillable = ['name', 'issuer_scope', 'reseller_id', 'user_id', 'prefix', 'count', 'settings', 'created_by'];

    /** @var array<string, string> */
    protected $casts = [
        'settings' => 'array',
        'count' => 'integer',
        'account_id' => 'integer',
        'reseller_id' => 'integer',
        'user_id' => 'integer',
    ];

    /** @return HasMany<Coupon, $this> */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'batch_id');
    }
}
