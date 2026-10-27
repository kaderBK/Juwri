<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PCI DSS 4.0 6.4.3: one CSP violation a browser reported from a payment page (POST csp-report/payment/{page}).
 * No IP address is stored.
 *
 * @property int $id
 * @property string $page
 * @property string|null $directive
 * @property string|null $blocked_uri
 * @property string|null $document_uri
 * @property string|null $source_file
 * @property int|null $line
 * @property string|null $disposition
 * @property string|null $sample
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class PaymentCspReport extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'line' => 'integer',
        'created_at' => 'datetime',
    ];
}
