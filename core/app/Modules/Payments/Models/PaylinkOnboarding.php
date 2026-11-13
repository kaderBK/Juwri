<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * PAYMENTS-RUN G10: one Paylink partner onboarding attempt per restaurant.
 */
class PaylinkOnboarding extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'store_id', 'reseller_id', 'status', 'provider_merchant_id',
        'cr_number', 'owner_id_number', 'iban', 'provider_status', 'reject_reason',
        'submitted_at', 'activated_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PENDING_NAFATH = 'pending_nafath';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
}
