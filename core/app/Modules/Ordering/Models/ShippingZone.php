<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-08: an area a store ships to. An address is in the zone when its country is one of `countries` and, for each
 * of `regions`, `cities` and `postal_prefixes` that is set, it matches one of them (case-insensitive; a postcode by
 * prefix). The first active zone by position wins.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property string $code
 * @property string $name
 * @property list<string> $countries
 * @property list<string>|null $regions
 * @property list<string>|null $cities
 * @property list<string>|null $postal_prefixes
 * @property bool $active
 * @property int $position
 */
class ShippingZone extends Model
{
    use BelongsToAccount;
    use HasUuids;

    protected $table = 'shipping_zones';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'countries' => 'array',
        'regions' => 'array',
        'cities' => 'array',
        'postal_prefixes' => 'array',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasMany<ShippingMethod, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'shipping_zone_id');
    }

    /**
     * Does this zone cover the address?
     *
     * @param  array<string, mixed>  $address  country (ISO 3166-1 alpha-2), region, city, postal_code
     */
    public function covers(array $address): bool
    {
        $country = strtoupper(trim((string) ($address['country'] ?? '')));
        if ($country === '' || ! in_array($country, array_map(fn ($c) => strtoupper((string) $c), (array) $this->countries), true)) {
            return false;
        }
        foreach (['regions' => 'region', 'cities' => 'city'] as $list => $field) {
            $allowed = array_values(array_filter(array_map(fn ($v) => mb_strtolower(trim((string) $v)), (array) ($this->{$list} ?? []))));
            if ($allowed !== [] && ! in_array(mb_strtolower(trim((string) ($address[$field] ?? ''))), $allowed, true)) {
                return false;
            }
        }
        $prefixes = array_values(array_filter(array_map(fn ($v) => strtoupper(str_replace(' ', '', (string) $v)), (array) ($this->postal_prefixes ?? []))));
        if ($prefixes !== []) {
            $postcode = strtoupper(str_replace(' ', '', (string) ($address['postal_code'] ?? '')));
            if ($postcode === '') {
                return false;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($postcode, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
