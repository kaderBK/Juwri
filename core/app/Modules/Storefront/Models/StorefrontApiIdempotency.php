<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-06b: one answered (or running) non-order write of the headless storefront API, by store + operation +
 * Idempotency-Key (see the migration). `response_body` is encrypted at rest.
 *
 * @property int $id
 * @property int $store_id
 * @property string $operation
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 */
class StorefrontApiIdempotency extends Model
{
    use BelongsToAccount;

    protected $table = 'storefront_api_idempotency';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'response_body' => 'encrypted',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
