<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Juwri\Core\Models\Store;

/**
 * A priced add-on ("Extra cheese") of one item (`menu_id`) or of the store's library (`is_global`).
 *
 * P1-06: a `modifiers` row of kind `addon`. Its {@see ModifierGroup} (the item's add-on group, or the store's
 * add-on library group) is found or created when the add-on is saved, so callers keep writing
 * menu_id / store_id / is_global as before.
 *
 * @property string|null $name
 * @property-read MenuItemExtraPivot $pivot  Present when loaded via Menu::attachedExtras().
 *
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 * @property string|float|null $price
 * @property int|null $price_minor
 * @property string|null $currency_code
 */
class MenuExtra extends Modifier
{
    use HasFactory; // P5
    use TranslatesForStorefront;

    /** @var array<string, string> P1-05: store-precision string, as the old StoreMoney cast */
    protected array $minorMoney = ['price' => 'price_minor,store'];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
        'is_global' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('addon_kind', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('kind'), ModifierGroup::KIND_ADDON);
        });
        static::creating(function (self $extra): void {
            $extra->setAttribute('kind', ModifierGroup::KIND_ADDON);
            $extra->placeInGroup();
        });
        static::updating(function (self $extra): void {
            if ($extra->isDirty(['menu_id', 'store_id', 'is_global', 'modifier_group_id'])) {
                $extra->placeInGroup();
            }
        });
    }

    /** Keep the add-on in the add-on group matching its item / library (created on first use). */
    public function placeInGroup(): void
    {
        $menuId = $this->menu_id ? (int) $this->menu_id : null;
        $storeId = $this->store_id ? (int) $this->store_id : null;
        $global = (bool) $this->is_global;

        if ($this->modifier_group_id) {
            $current = ModifierGroup::query()->whereKey($this->modifier_group_id)->first();
            if ($current && $current->kind === ModifierGroup::KIND_ADDON
                && (int) $current->menu_id === (int) $menuId
                && ($menuId || ((bool) $current->is_global === $global && (int) $current->store_id === (int) $storeId))) {
                return;
            }
        }

        $this->modifier_group_id = ModifierGroup::addonGroupFor($menuId, $storeId, $global, $this->account_id ? (int) $this->account_id : null)->id;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Items this extra is attached to (library many-to-many). */
    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'item_modifier_group', 'modifier_id', 'menu_id')
            ->using(MenuItemExtraPivot::class)
            ->withPivot(['position', 'price_override_minor', 'currency_code', 'active']);
    }

    /** The extra's title in the storefront visitor's language. */
    public function getTitleAttribute($value): mixed
    {
        return $this->storefrontTranslation('title') ?? $value;
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * Public URL for the extra image (empty string when unset). Image is library-managed.
     */
    public function getImageUrlAttribute(): string
    {
        $rid = $this->menu->store_id ?? null;

        return ! empty($this->image) ? media_url('storage/menu/', $this->image, $rid) : '';
    }
}
