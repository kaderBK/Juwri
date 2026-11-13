<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Inventory\Models\Supplier;

/**
 * P1-05: `cost_per_unit` is stored as `cost_per_unit_minor` in the row's `currency_code` (the store's; a catalogue
 * row — an edited cost moves to the store's current currency). It keeps the store-precision string shape of the old
 * StoreMoney cast. Query with `cost_per_unit_minor`.
 *
 * @property string|float $cost_per_unit major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int $cost_per_unit_minor
 * @property string|null $currency_code
 */
class Ingredient extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['cost_per_unit' => 'cost_per_unit_minor,store'];

    protected $casts = [
        'current_stock' => 'decimal:3',
        'par_level' => 'decimal:3',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    public const UNITS = ['g', 'kg', 'ml', 'l', 'each', 'dozen', 'pack'];

    public function movements()
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Is the ingredient at or below its reorder point? */
    public function getIsLowAttribute(): bool
    {
        return $this->par_level > 0 && $this->current_stock <= $this->par_level;
    }

    /** Cached value of stock on hand (qty × unit cost). */
    public function getStockValueAttribute(): float
    {
        return round((float) $this->current_stock * (float) $this->cost_per_unit, 2);
    }
}
