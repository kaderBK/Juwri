<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS A2 — a line on a hotel event (a service, a room-hire fee, catering). Its
 * qty × unit_price posts as a folio charge when the event is billed (A8).
 *
 * P1-05: `unit_price` is stored as `unit_price_minor` in the row's `currency_code` (the store's, stamped on first
 * save; a quoted line — never re-interpreted). It keeps the number shape of the old `float` cast.
 *
 * @property float $unit_price major amount
 * @property int $unit_price_minor
 * @property string|null $currency_code
 */
class HotelEventItem extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $table = 'hotel_event_items';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['unit_price' => 'unit_price_minor'];

    protected $casts = [
        'qty' => 'float',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function lineTotal(): float
    {
        return (float) $this->qty * (float) $this->unit_price;
    }

    /** @return BelongsTo<HotelEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(HotelEvent::class, 'event_id');
    }

    /** @return BelongsTo<HotelExtraService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(HotelExtraService::class, 'service_id');
    }
}
