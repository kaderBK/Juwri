<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user locked-template edit capabilities, set by the superadmin. A user with no
 * row falls back to the global default (see LockedTemplates\Permissions).
 *
 * @property int $id
 * @property int $user_id
 * @property array<string, bool> $caps
 * @property array<int, int>|null $allowed_templates
 * @property int|null $updated_by
 */
class LockedTemplatePermission extends Model
{
    protected $fillable = [
        'user_id',
        'caps',
        'allowed_templates',
        'updated_by',
    ];

    protected $casts = [
        'caps' => 'array',
        'allowed_templates' => 'array',
    ];
}
