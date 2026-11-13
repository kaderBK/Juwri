<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WI-G4: a platform-level menu category (no store_id). `parent_id` gives
 * sub-categories. Owners ADOPT these into their own scoped MenuCategory rows.
 */
class GlobalMenuCategory extends Model
{
    protected $fillable = ['parent_id', 'name', 'translations', 'image', 'position', 'active'];

    protected $casts = ['active' => 'boolean', 'translations' => 'array'];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
