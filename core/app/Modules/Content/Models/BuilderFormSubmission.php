<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One submission from a curated Builder.js page form. Append-only (created_at only).
 */
class BuilderFormSubmission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'surface', 'ref_id', 'label', 'page_url', 'fields', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'fields' => 'array',
        'created_at' => 'datetime',
    ];
}
