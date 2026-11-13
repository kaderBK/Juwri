<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * TAX-RUN F0-P2: encrypted certificate / key storage for fiscal drivers (ZATCA
 * CSR/CCSID/PCSID, Egypt e-seal). The PEM is encrypted at rest via the same
 * Laravel encryption Credential secrets use. It is NEVER logged and NEVER copied
 * into a FiscalDocument payload snapshot.
 */
class FiscalCertificate extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'pem' => 'encrypted',
        // meta carries live API secrets (ZATCA CCSID/PCSID basic-auth secret) alongside
        // non-secret fields — encrypt the whole blob at rest, never plaintext JSON.
        'meta' => 'encrypted:array',
        'active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /** Keep secret material out of arrays/JSON/logs by default. */
    protected $hidden = ['pem', 'meta'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Active cert of a given kind for a restaurant/device, or null. */
    public static function resolve(int $storeId, string $kind, ?string $deviceKey = null): ?self
    {
        return static::where('store_id', $storeId)
            ->where('kind', $kind)
            ->where('active', true)
            ->when($deviceKey !== null, fn ($q) => $q->where('device_key', $deviceKey))
            ->latest('id')
            ->first();
    }

    /** True once the certificate's validity has ended (unknown expiry = not expired). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
