<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'payment_install',
        'payment_title',
        'payment_folder',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payment_install' => 'boolean',
    ];

    /**
     * MoR-RUN F2: exclude merchant-of-record gateways (Paddle Billing, Lemon
     * Squeezy). MoR providers only permit direct digital-product sales, so
     * they are offered on platform billing surfaces (plans, add-ons, reseller
     * invoices) and excluded everywhere else (wallet/gift-card credit,
     * marketplace purchases).
     */
    public function scopeWithoutMor($query)
    {
        return $query->whereNotIn('payment_folder', (array) config('mor-gateways.folders', []));
    }
}
