<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\User;

/**
 * P9-5b: a seller's Stripe Connect account, target of stripe_connect payouts.
 */
class SellerStripeAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'details_submitted' => 'boolean',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
