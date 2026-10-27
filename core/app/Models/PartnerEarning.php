<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * P4-7 (feature-platform): one row per marketplace sale credit.
 *
 * P1-05: `gross` / `net` are stored as `*_minor` in the row's `currency` (the platform currency, stamped on first
 * save; facts — never re-interpreted). They keep the number shape of the old `float` casts; `share` is a rate.
 * Query with the `*_minor` columns (sums: `PartnerEarning::minorMoneySql('net')`).
 *
 * @property float $gross major amount
 * @property float $net major amount
 * @property int $gross_minor
 * @property int $net_minor
 * @property string|null $currency
 */
class PartnerEarning extends Model implements MinorMoneyModel
{
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['gross' => 'gross_minor', 'net' => 'net_minor'];

    protected ?string $minorMoneyCurrencyColumn = 'currency';

    protected $casts = ['available_at' => 'datetime', 'share' => 'float'];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }
}
