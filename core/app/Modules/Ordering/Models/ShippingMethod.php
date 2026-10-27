<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-08: one way a store gets goods to a shopper — `standard` / `express` delivery inside a {@see ShippingZone}, or
 * `pickup_in_store` at a branch (no zone). Money in minor units of `currency_code` (the store currency when saved):
 * `fee_minor`, free when the items subtotal reaches `free_over_minor`. Dates: transit `min_days`–`max_days` counted
 * from today (from tomorrow after `cutoff_time`, store time), moved onto the next of `delivery_days` (0 = Sunday).
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int|null $shipping_zone_id
 * @property string $kind
 * @property string $name
 * @property string|null $carrier
 * @property string|null $tracking_url_template
 * @property int $fee_minor
 * @property int|null $free_over_minor
 * @property string $currency_code
 * @property int $min_days
 * @property int $max_days
 * @property string|null $cutoff_time
 * @property list<int>|null $delivery_days
 * @property int|null $pickup_branch_id
 * @property bool $active
 * @property int $position
 */
class ShippingMethod extends Model
{
    use BelongsToAccount;
    use HasUuids;
    use TranslatesForStorefront;

    public const KINDS = ['standard', 'express', 'pickup_in_store'];

    protected $table = 'shipping_methods';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
        'fee_minor' => 'integer',
        'free_over_minor' => 'integer',
        'min_days' => 'integer',
        'max_days' => 'integer',
        'delivery_days' => 'array',
        'pickup_branch_id' => 'integer',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /** The name in the storefront visitor's language. */
    public function getNameAttribute(mixed $value): mixed
    {
        return $this->storefrontTranslation('name') ?? $value;
    }

    /** The carrier's tracking page for a tracking number, from the template (`{tracking}`), https only. */
    public function trackingUrl(?string $trackingNumber): ?string
    {
        $template = trim((string) $this->tracking_url_template);
        $number = trim((string) $trackingNumber);
        if ($template === '' || $number === '' || ! str_starts_with(strtolower($template), 'https://')) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($number), $template);
    }
}
