<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-04: the tenant. One account per owner; it owns stores (which own branches). Every tenant row
 * carries `account_id` (J29-1). Created by AccountProvisioner, never by hand.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int|null $reseller_id
 * @property int|null $owner_user_id
 * @property string $name
 * @property string $region
 * @property array<string, mixed>|null $billing_profile
 * @property string $status
 */
class Account extends Model
{
    use HasUuids;

    public const STATUSES = ['active', 'suspended', 'closed'];

    protected $table = 'accounts';

    /** @var list<string> */
    protected $fillable = ['reseller_id', 'owner_user_id', 'name', 'region', 'billing_profile', 'status'];

    /** @var array<string, string> */
    protected $casts = [
        'billing_profile' => 'array',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'region' => 'global',
        'status' => 'active',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'account_id');
    }

    /** @return HasMany<Store, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'account_id');
    }
}
