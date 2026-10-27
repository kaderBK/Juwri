<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * TAX-RUN Z-P2 / E-P1: a fiscal device (ZATCA EGS unit per branch, or ETA POS
 * serial). Anchors the per-device hash chain and carries the onboarding status.
 */
class FiscalDevice extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public const ZATCA_FLOW = ['new', 'compliance', 'production'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function certificates()
    {
        return $this->hasMany(FiscalCertificate::class, 'device_key', 'device_key')
            ->where('store_id', $this->store_id);
    }

    public function isProduction(): bool
    {
        return $this->status === 'production';
    }
}
