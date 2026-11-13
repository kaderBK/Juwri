<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * SYSTEMS-RUN S0: a balanced journal entry (Σdebit = Σcredit, enforced by
 * JournalEngine). Polymorphic source (Order/Transaction/Expense/PayrollRun/…),
 * idempotent per (store_id, source_type, source_id, rule_key).
 */
class GlJournal extends Model
{
    use BelongsToAccount;

    protected $table = 'accounting_journals';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'locked' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return HasMany<GlJournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GlJournalLine::class, 'journal_id');
    }

    public function totalDebit(): float
    {
        return money_round((float) $this->lines->sum('debit'), $this->moneyDecimals());
    }

    public function totalCredit(): float
    {
        return money_round((float) $this->lines->sum('credit'), $this->moneyDecimals());
    }

    /** P6-208: the store currency's minor-unit decimals (KWD/BHD/JOD: 3). */
    private function moneyDecimals(): int
    {
        return money_decimals(store_currency((int) $this->store_id));
    }
}
