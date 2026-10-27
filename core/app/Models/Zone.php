<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Juwri\Core\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Models\City;

/**
 * P1-05: the fees are stored as `*_minor` in the row's `currency_code` — the zone's city's country currency (the
 * marketplace currency there), else the platform's; stamped on first save and moved to the country's current
 * currency when a fee is edited (catalogue row). Floats on read, as the old cast. Query with the `*_minor` columns.
 *
 * @property float $base_fee
 * @property float $per_km_fee
 * @property float $min_order
 * @property int $base_fee_minor
 * @property int $per_km_fee_minor
 * @property int $min_order_minor
 * @property string|null $currency_code
 */
class Zone extends Model implements MinorMoneyModel
{
    use HasFactory;
    use HasMinorMoney;

    /** @var array<string, string> */
    protected array $minorMoney = [
        'base_fee' => 'base_fee_minor',
        'per_km_fee' => 'per_km_fee_minor',
        'min_order' => 'min_order_minor',
    ];

    protected $fillable = [
        'city_id',
        'name',
        'polygon',
        'base_fee',
        'per_km_fee',
        'min_order',
        'active',
    ];

    protected $casts = [
        'polygon' => 'array',
        'active' => 'boolean',
    ];

    /** The zone's city's country currency, else the platform's. */
    public function defaultMinorMoneyCurrency(): string
    {
        $cityId = $this->attributes['city_id'] ?? null;
        $code = $cityId ? DB::table('cities')->join('countries', 'countries.id', '=', 'cities.country_id')
            ->where('cities.id', $cityId)->value('countries.currency') : null;

        return Currencies::normalize(is_string($code) ? $code : null) ?: $this->defaultStoreOrPlatformCurrency();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function containsPoint(float $lat, float $lng): bool
    {
        return Geo::pointInPolygon($lat, $lng, $this->polygon ?? []);
    }
}
