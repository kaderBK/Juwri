<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Services\Tenancy\TenantFill;

/**
 * Typed pivot for Menu::attachedExtras() — a library add-on attached to one item, carrying the per-item
 * override columns declared in withPivot().
 *
 * P1-06: an `item_modifier_group` row with `modifier_id` set (was `menu_item_extra`); `modifier_group_id` (the
 * add-on's group) and the tenant keys are filled on save.
 *
 * @property int $position
 * @property float|null $price_override P1-05: stored as price_override_minor (+ currency_code); a number or null.
 * @property bool|null $active
 *
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 * @property int|null $price_override_minor
 * @property string|null $currency_code
 */
class MenuItemExtraPivot extends Pivot implements MinorMoneyModel
{
    use HasMinorMoney;

    /** @var array<string, string> */
    protected array $minorMoney = ['price_override' => 'price_override_minor'];

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            if (empty($pivot->attributes['modifier_group_id']) && ! empty($pivot->attributes['modifier_id'])) {
                $pivot->attributes['modifier_group_id'] = DB::table('modifiers')
                    ->where('id', $pivot->attributes['modifier_id'])->value('modifier_group_id');
            }
            if (! $pivot->exists) {
                TenantFill::fill($pivot);
            }
        });
    }

    /** The pivot row's currency when it has none yet: its menu item's. */
    public function defaultMinorMoneyCurrency(): string
    {
        $menuId = $this->attributes['menu_id'] ?? null;
        if ($menuId && empty($this->attributes['store_id'])) {
            $row = DB::table('items')->where('id', $menuId)->first(['store_id', 'currency_code']);
            if ($row && $row->currency_code) {
                return strtoupper((string) $row->currency_code);
            }
            if ($row && $row->store_id) {
                return strtoupper(store_currency((int) $row->store_id));
            }
        }

        return $this->defaultStoreOrPlatformCurrency();
    }
}
