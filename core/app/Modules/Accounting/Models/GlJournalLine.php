<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * SYSTEMS-RUN S0: one debit/credit leg of a GL journal.
 *
 * P1-05: `debit` / `credit` are stored as `*_minor` in the row's `currency_code` (its store's, stamped on first
 * save; a posted leg — never re-interpreted; every leg of a journal is posted in one transaction, so a journal is
 * in one currency and balances in it). They keep the store-precision string shape of the old StoreMoney cast.
 * Query with the `*_minor` columns (sums: `GlJournalLine::minorMoneySql('debit')`).
 *
 * @property string|float $debit major amount (store-precision string on read)
 * @property string|float $credit major amount (store-precision string on read)
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string|null $currency_code
 */
class GlJournalLine extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $table = 'accounting_journal_lines';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['debit' => 'debit_minor,store', 'credit' => 'credit_minor,store'];

    protected $casts = [
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<GlJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(GlJournal::class, 'journal_id');
    }

    /** @return BelongsTo<GlAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    /** HasMinorMoney (row currency): a journal line belongs to its journal. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('accounting_journals', 'journal_id', 'store_id');
    }
}
