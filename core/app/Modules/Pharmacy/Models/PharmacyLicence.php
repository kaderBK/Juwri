<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;

/**
 * P1-23 (AUP 3.1(b)): one licence upload of a pharmacy store, reviewed by the platform before the store may be
 * published. The newest row of a store is the one that counts.
 *
 * @property int $id
 * @property int $account_id
 * @property int $store_id
 * @property string $country
 * @property string $licence_number
 * @property string $issuing_authority
 * @property Carbon $expires_on
 * @property string|null $responsible_person_name
 * @property string|null $responsible_person_registration
 * @property string|null $online_sales_permit
 * @property string $document_path
 * @property string $status
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class PharmacyLicence extends Model
{
    use BelongsToAccount;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'pharmacy_licences';

    protected $guarded = ['id', 'status', 'review_note', 'reviewed_by', 'reviewed_at'];

    protected $casts = [
        'expires_on' => 'date',
        'reviewed_at' => 'datetime',
        'reviewed_by' => 'integer',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * The newest upload of a store, or null. A platform publish rule, so read across the tenant scope: a signed-in
     * user of another account must see the same published state as a guest.
     */
    public static function latestFor(int $storeId): ?self
    {
        return static::query()->withoutGlobalScope(AccountScope::class)->where('store_id', $storeId)->orderByDesc('id')->first();
    }

    public function isExpired(?Carbon $today = null): bool
    {
        return $this->expires_on->lt(($today ?? Carbon::today())->startOfDay());
    }
}
