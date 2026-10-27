<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin overlay row for one filesystem menu template (store_templates/<slug>).
 * Null columns mean "fall back to the on-disk theme-info.txt / preview.png".
 */
class ThemeTemplateMeta extends Model
{
    protected $table = 'theme_template_meta';

    protected $fillable = [
        'slug',
        'display_name',
        'description',
        'preview_image',
        'featured',
        'enabled',
        'sort_order',
    ];

    protected $casts = [
        'featured' => 'boolean',
        'enabled' => 'boolean',
        'sort_order' => 'integer',
    ];
}
