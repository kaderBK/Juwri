<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS A2 — a sellable add-on (breakfast, parking, spa) posted to a stay folio.
 * charge_mode drives quantity expansion at post time (A7):
 *   once                 → price × qty
 *   per_night            → price × booked nights × qty
 *   per_person_per_night → price × (adults+children) × booked nights × qty
 * Store-scoped by store_id.
 *
 * P1-05: `price` is stored as `price_minor` in the row's `currency_code` (the store's; a catalogue row — an edited amount moves to the store's current currency).
 * It keeps the number shape of the old `float` cast; `tax_rate` is a rate (unchanged). Query with `price_minor`.
 *
 * @property float $price major amount
 * @property int $price_minor
 * @property string|null $currency_code
 */
class HotelExtraService extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $table = 'hotel_extra_services';

    public const MODE_ONCE = 'once';

    public const MODE_PER_NIGHT = 'per_night';

    public const MODE_PER_PERSON_PER_NIGHT = 'per_person_per_night';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['price' => 'price_minor'];

    protected $casts = [
        'tax_rate' => 'float',
        'active' => 'boolean',
    ];

    /**
     * @param  Builder<HotelExtraService>  $q
     * @return Builder<HotelExtraService>
     */
    public function scopeForRestaurant($q, int $storeId)
    {
        return $q->where('store_id', $storeId);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
