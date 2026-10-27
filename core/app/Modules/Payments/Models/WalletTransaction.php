<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Models\User;

/**
 * A platform-wallet movement (top-ups, sales, payouts, add-on purchases).
 *
 * @property string|float $amount P1-05: major amount (2-decimal string on read, more for fils currencies: '1.235')
 * @property string|float $balance_after
 * @property int $amount_minor P1-05: minor units of currency_code (the platform currency)
 * @property int $balance_after_minor
 * @property string|null $currency_code
 */
class WalletTransaction extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /**
     * P1-05: `amount` / `balance_after` are stored as `*_minor` in the row's `currency_code` (the platform currency,
     * stamped on first save). SQL must use the `*_minor` columns. They read as 2-decimal strings as before, or at the
     * currency's exponent when it has more (bug batch 7 · 2: KWD '1.235', not '1.24' — the wallet shows what it holds).
     *
     * @var array<string, string>
     */
    protected array $minorMoney = [
        'amount' => 'amount_minor,atleast:2',
        'balance_after' => 'balance_after_minor,atleast:2',
    ];

    /** P1-05: a wallet movement is a fact in the currency it was made in. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
