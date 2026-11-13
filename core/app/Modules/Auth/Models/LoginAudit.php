<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A1: one sign-in to a privileged account (superadmin or reseller owner).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $device_hash
 * @property bool $new_device
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class LoginAudit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['new_device' => 'boolean', 'created_at' => 'datetime'];
}
