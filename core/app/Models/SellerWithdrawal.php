<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Models\User;

/**
 * P9-5a: a marketplace seller's cash-out request. Lifecycle: pending → paid
 * (superadmin records an external reference) or pending → rejected (wallet
 * refunded). The wallet debit happens at request time via WalletService, so a
 * pending row already represents money reserved out of the balance.
 *
 * P1-05: `amount` is stored as `amount_minor` in the row's `currency_code` (the platform currency — the wallet's —
 * stamped on first save; never re-interpreted). It keeps the `decimal:2` string shape of the old cast, which is
 * also what the Stripe Connect payout converts. Query with `amount_minor`.
 *
 * @property string|float $amount major amount (decimal:2 string on read)
 * @property int $amount_minor
 * @property string|null $currency_code
 */
class SellerWithdrawal extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['amount' => 'amount_minor,decimal:2'];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
