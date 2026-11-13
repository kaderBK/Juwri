<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * One payment gateway of one store (P1-07). Read and write through
 * Juwri\Modules\Payments\Services\StorePaymentGateways, which scopes every query by store,
 * encrypts credentials and masks them for display.
 *
 * @property int $id
 * @property int|null $account_id
 * @property int $store_id
 * @property string $gateway
 * @property bool $enabled
 * @property array<string, mixed>|null $config
 * @property array<string, string>|null $credentials
 */
class StorePaymentGateway extends Model
{
    use BelongsToAccount;

    protected $table = 'store_payment_gateways';

    protected $fillable = ['account_id', 'store_id', 'gateway', 'enabled', 'config', 'credentials'];

    /** Credentials never leave the model in toArray()/toJson() (API resources, logs, dumps). */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }
}
