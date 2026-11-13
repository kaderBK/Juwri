<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An account in the unified double-entry ledger (Coupons & Wallets P1).
 * One row per (owner_type, owner_id, kind, currency). See the migration for the
 * tier meanings. Balance is authoritative from SUM(entries.amount), never a
 * column on this row.
 */
class LedgerAccount extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function entries()
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_account_id');
    }
}
