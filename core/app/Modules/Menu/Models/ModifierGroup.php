<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\BustsMenuCache;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P1-06 menu remodel: a modifier group on an item (`menu_id`) or in the store's library (`menu_id` null).
 *
 *  - kind `variant`: a choice group ("Size": Small / Large) — was `menu_variant_options`; the legacy
 *    {@see MenuVariantOption} reads/writes it with its positional `options` / `option_prices` /
 *    `option_images` lists (now one {@see Modifier} row per option).
 *  - kind `addon`: the add-ons of one item, or the store's add-on library — every legacy `menu_extras` row
 *    ({@see MenuExtra}) is a modifier in one of these; the group is found or created on save.
 *
 * Item attachments of library groups / single library add-ons live in `item_modifier_group`.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int|null $store_id
 * @property int|null $menu_id
 * @property string $kind
 * @property bool $is_global
 * @property string|null $title
 * @property bool $required
 * @property int $min_select
 * @property int|null $max_select
 * @property int $position
 * @property bool $active
 * @property string|null $purpose P3-09: `weight` (weight steps of an item sold by weight) | `substitution` (the store's
 *                               "If it's out of stock" group, API-22) | null
 */
class ModifierGroup extends Model
{
    use BelongsToAccount;
    use BustsMenuCache;
    use HasUuids;

    public const KIND_VARIANT = 'variant';

    public const KIND_ADDON = 'addon';

    /** P3-09: the weight steps of an item sold by weight (each option is the whole line price). */
    public const PURPOSE_WEIGHT = 'weight';

    /** P3-09 (API-22): the store's "If it's out of stock" group — never one of an item's priced groups. */
    public const PURPOSE_SUBSTITUTION = 'substitution';

    protected $table = 'modifier_groups';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
        'is_global' => 'boolean',
        'required' => 'boolean',
        'min_select' => 'integer',
        'max_select' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * P1-03 (J25-1): UUIDv7 public id; the bigint id stays the key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasMany<Modifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'modifier_group_id')->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Menu, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function menuCacheRestaurantId(): ?int
    {
        $rid = $this->store_id ?? ($this->menu_id ? Menu::withTrashed()->where('id', $this->menu_id)->value('store_id') : null);

        return $rid ? (int) $rid : null;
    }

    /**
     * The add-on group an add-on belongs in: the item's own group (`$menuId`), else the store's library group
     * (`$global`) or the store's loose add-ons. Created on first use.
     */
    public static function addonGroupFor(?int $menuId, ?int $storeId, bool $global, ?int $accountId = null): self
    {
        $query = static::query()->where('kind', self::KIND_ADDON);
        if ($menuId) {
            $query->where('menu_id', $menuId);
        } else {
            $query->whereNull('menu_id')->where('store_id', $storeId)->where('is_global', $global);
        }

        $group = $query->orderBy('id')->first();
        if ($group) {
            return $group;
        }

        return static::create(array_filter([
            'kind' => self::KIND_ADDON,
            'menu_id' => $menuId ?: null,
            'store_id' => $storeId ?: null,
            'account_id' => $accountId ?: null,
            'is_global' => ! $menuId && $global,
            'title' => $global && ! $menuId ? 'Add-ons library' : 'Extras',
            'required' => false,
            'min_select' => 0,
        ], fn ($v) => $v !== null));
    }
}
