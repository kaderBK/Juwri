<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\User;

class ReferralConversion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'approved_at'   => 'datetime',
        'paid_at'       => 'datetime',
    ];

    public function referral()
    {
        return $this->belongsTo(Referral::class);
    }

    public function newUser()
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }

    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'pending'  => '<span style="background:rgba(245,181,74,.15);color:#f5b54a;padding:2px 8px;border-radius:6px;font-size:11px;">'.___('Pending').'</span>',
            'approved' => '<span style="background:rgba(34,197,94,.15);color:#22c55e;padding:2px 8px;border-radius:6px;font-size:11px;">'.___('Approved').'</span>',
            'paid'     => '<span style="background:rgba(99,102,241,.15);color:#818cf8;padding:2px 8px;border-radius:6px;font-size:11px;">'.___('Paid').'</span>',
            'rejected' => '<span style="background:rgba(255,71,87,.15);color:#ff4757;padding:2px 8px;border-radius:6px;font-size:11px;">'.___('Rejected').'</span>',
            default    => $this->status,
        };
    }
}
