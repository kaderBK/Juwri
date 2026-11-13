<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MC-30 — a display-only FX rate (base → quote). DECIMAL(18,8); never used to
 * compute a charge, a stored total, or JSON-LD.
 */
class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    protected $fillable = ['base', 'quote', 'rate', 'provider', 'fetched_at'];

    protected $casts = [
        'rate' => 'decimal:8',
        'fetched_at' => 'datetime',
    ];
}
