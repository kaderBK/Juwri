<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * SYSTEMS-RUN A-P2: a restaurant expense, classified to a GL expense account.
 *
 * P1-05: `amount` / `tax` are stored as `*_minor` in the row's `currency_code` (the store's, stamped on first save;
 * a recorded cost — never re-interpreted). They keep the store-precision string shape of the old StoreMoney cast.
 *
 * @property string|float $amount major amount (store-precision string on read)
 * @property string|float $tax major amount (store-precision string on read)
 * @property int $amount_minor
 * @property int $tax_minor
 * @property string|null $currency_code
 */
class Expense extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['amount' => 'amount_minor,store', 'tax' => 'tax_minor,store'];

    protected $casts = [
        'date' => 'date',
        'recur_next' => 'date',
        'recur_active' => 'boolean',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function glAccount()
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function gross(): float
    {
        return round((float) $this->amount + (float) $this->tax, 2);
    }
}
