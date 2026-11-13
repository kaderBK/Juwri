<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use App\Models\AppInstallation;

/**
 * P3-4: one metered-usage report from a partner app (idempotent by ref).
 *
 * P1-05: `charged` (the pre-tax charge) is stored as `charged_minor` in the row's `currency_code` (the platform
 * currency, stamped on first save; a charge — never re-interpreted). It reads as a number (the column was uncast).
 *
 * @property float $charged major amount
 * @property int $charged_minor
 * @property string|null $currency_code
 */
class AppUsageRecord extends Model implements MinorMoneyModel
{
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['charged' => 'charged_minor'];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function installation()
    {
        return $this->belongsTo(AppInstallation::class, 'app_installation_id');
    }
}
