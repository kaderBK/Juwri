<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * P1-23: one personal-data breach notice to one customer (see the breach_notices migration). Platform-level record,
 * written only by security:breach-notice.
 *
 * @property int $id
 * @property string $incident_ref
 * @property int|null $target_account_id
 * @property int|null $recipient_user_id
 * @property string $email
 * @property string|null $country
 * @property int $notice_hours
 * @property Carbon $confirmed_at
 * @property Carbon $deadline_at
 * @property Carbon|null $sent_at
 */
class BreachNotice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_account_id' => 'integer',
        'recipient_user_id' => 'integer',
        'notice_hours' => 'integer',
        'confirmed_at' => 'datetime',
        'deadline_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
