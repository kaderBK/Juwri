<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * GAPS-RUN-2 B1: menu_id ↔ provider ↔ external POS catalog/inventory id. A pushed
 * line item references the POS's own item when a mapping exists; unmapped items
 * fall back to ad-hoc name/price line items.
 */
class PosItemMap extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_item_mappings';

    protected $guarded = [];

    /** External catalog/inventory id for a menu item on a provider, or null. */
    public static function externalIdFor(string $provider, int $menuId): ?string
    {
        return static::where('provider', $provider)->where('menu_id', $menuId)->value('external_id');
    }
}
