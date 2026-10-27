<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * A saved skin-scan profile + routine for the lumiere (cosmetic) storefront.
 * Keyed to a customer when logged in, else to a guest session_token.
 */
class SkinProfile extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'store_id', 'customer_id', 'session_token', 'skin_age', 'metrics', 'routine',
    ];

    protected $casts = [
        'metrics' => 'array',
        'routine' => 'array',
        'skin_age' => 'integer',
    ];
}
