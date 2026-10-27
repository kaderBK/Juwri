<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * SYSTEMS-RUN P-P1: a payroll run over a pay period.
 *
 * P1-05: `total_net` is stored as `total_net_minor` in the row's `currency_code` (the store's, stamped on first save;
 * never re-interpreted); its items carry the run's currency. It keeps the store-precision string shape of the old
 * StoreMoney cast. Query with `total_net_minor`.
 *
 * @property string|float $total_net major amount (store-precision string on read)
 * @property int $total_net_minor
 * @property string|null $currency_code
 */
class PayrollRun extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['total_net' => 'total_net_minor,store'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function items()
    {
        return $this->hasMany(PayrollItem::class, 'payroll_run_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }
}
