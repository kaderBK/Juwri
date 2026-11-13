<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Juwri\Modules\Payments\Services\LedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * An append-only posting in the unified ledger (Coupons & Wallets P1). Never
 * updated or deleted after creation; corrections are new balancing entries with
 * `reversed_by` linking the pair.
 *
 * @property string|float $amount P1-05: major amount (a 2-decimal string on read, as the old cast; bug batch 4 · 1:
 *                                the currency's own decimals when it has more — '1.235' KWD)
 * @property string|float $balance_after
 * @property int $amount_minor P1-05: minor units of currency_code (the ledger account's currency)
 * @property int $balance_after_minor
 * @property string|null $currency_code
 */
class LedgerEntry extends Model implements MinorMoneyModel
{
    use HasMinorMoney;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * P1-05: `amount` / `balance_after` (2-decimal strings as before, 3 for fils currencies) are stored as `*_minor` in the entry's
     * `currency_code` — its ledger account's currency. SQL must use the `*_minor` columns: an account's entries
     * share one currency, so `SUM(amount_minor)` is exact ({@see LedgerService::balanceFor()}).
     *
     * @var array<string, string>
     */
    protected array $minorMoney = [
        'amount' => 'amount_minor,atleast:2',
        'balance_after' => 'balance_after_minor,atleast:2',
    ];

    /** P1-05: an entry is in its ledger account's currency. */
    public function defaultMinorMoneyCurrency(): string
    {
        $accountId = $this->getAttribute('ledger_account_id');
        $code = $accountId ? DB::table('ledger_accounts')->where('id', $accountId)->value('currency') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    /** P1-05: a posting is a fact in its account's currency. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
