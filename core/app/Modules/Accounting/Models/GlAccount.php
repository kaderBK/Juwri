<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * SYSTEMS-RUN S0: a chart-of-accounts account. Hierarchical (parent_id),
 * code-numbered, restaurant-scoped. `name` is a localized {en,ar} JSON blob.
 */
class GlAccount extends Model
{
    use BelongsToAccount;

    protected $table = 'accounting_accounts';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'system' => 'boolean',
        'archived' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(GlJournalLine::class, 'gl_account_id');
    }

    /** Localized display name (current locale → en → any). */
    public function displayName(): string
    {
        $name = (array) $this->name;
        $locale = app()->getLocale();

        return $name[$locale] ?? $name['en'] ?? (reset($name) ?: (string) $this->code);
    }

    /** Signed balance: assets/expenses are debit-normal (debit - credit); others credit-normal. */
    public function balance(): float
    {
        // P1-05: per-row major amounts from the *_minor columns (a journal posts in its store's currency).
        $debit = (float) $this->lines()->sum(DB::raw(GlJournalLine::minorMoneySql('debit')));
        $credit = (float) $this->lines()->sum(DB::raw(GlJournalLine::minorMoneySql('credit')));
        $dec = money_decimals(store_currency((int) $this->store_id)); // P6-208: 3-decimal currencies

        return in_array($this->type, ['asset', 'expense'], true)
            ? money_round($debit - $credit, $dec)
            : money_round($credit - $debit, $dec);
    }
}
