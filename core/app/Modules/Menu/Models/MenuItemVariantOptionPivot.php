<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Juwri\Core\Services\Tenancy\TenantFill;

/**
 * Typed pivot for Menu::attachedVariantOptions() — a library option group attached to one item, carrying the
 * per-item rule overrides declared in withPivot().
 *
 * P1-06: an `item_modifier_group` row with `modifier_id` null (was `menu_item_variant_option`); the tenant keys
 * are filled on save.
 *
 * @property int $position
 * @property bool|null $required
 * @property int|null $min_select
 * @property int|null $max_select
 * @property bool|null $active
 */
class MenuItemVariantOptionPivot extends Pivot
{
    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            if (! $pivot->exists) {
                TenantFill::fill($pivot);
            }
        });
    }
}
