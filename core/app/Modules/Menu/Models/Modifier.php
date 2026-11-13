<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\BustsMenuCache;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * P1-06 menu remodel: one choice of a {@see ModifierGroup} — an option of a variant group (was a position in
 * `menu_variant_options.options` / `option_prices` / `option_images`) or an add-on (was a `menu_extras` row, read
 * through {@see MenuExtra}). `menu_id`, `kind` and `is_global` mirror the group (denormalised so add-ons
 * stay queryable by item / library). Price in `price_minor` (P1-05); an option without a price has null.
 *
 * @property int $id
 * @property int|null $modifier_group_id null until saved (an add-on is placed in its group on save)
 * @property int|null $menu_id
 * @property string $kind
 * @property string|null $title
 * @property string|null $image
 * @property float|string|null $price
 * @property int|null $price_minor
 * @property string|null $currency_code
 * @property int $position
 * @property bool $active
 * @property string|null $public_id UUIDv7 public id (J25-1)
 * @property int|null $weight_g P3-09: grams of a weight step
 * @property string|null $picker_action P3-09 (API-25): offer_replacement | call_customer | remove_item (substitution options)
 * @property string|null $picker_fallback P3-09 (API-30): offer_replacement | remove_item (call_customer options)
 */
class Modifier extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use BustsMenuCache;
    use HasMinorMoney;
    use HasUuids;

    protected $table = 'modifiers';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['price' => 'price_minor'];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
        'is_global' => 'boolean',
        'active' => 'boolean',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<ModifierGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function menuCacheRestaurantId(): ?int
    {
        $rid = $this->store_id ?? ($this->menu_id ? Menu::withTrashed()->where('id', $this->menu_id)->value('store_id') : null);

        return $rid ? (int) $rid : null;
    }
}
