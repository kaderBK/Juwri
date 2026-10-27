<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Menu\Services\Retail\Wishlist;

/**
 * P3-08 (aisle §12): one saved item of a shopper's wishlist at one store — a signed-in customer's (`customer_id`) or
 * a guest's (`guest_key` = sha256 of the browser's wishlist token, never the token itself). A guest's list moves to
 * the customer's at sign-in ({@see Wishlist::merge()}).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $customer_id
 * @property string|null $guest_key
 * @property int $menu_id
 * @property int|null $variant_id
 */
class WishlistItem extends Model
{
    use BelongsToAccount;

    protected $table = 'wishlist_items';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /** Most items one list keeps (the oldest beyond it are dropped when a new one is saved). */
    public const MAX_ITEMS = 200;

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
