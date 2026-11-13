<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use App\Models\Ingredient;

/**
 * P1-05: `unit_cost` is stored as `unit_cost_minor` in the row's `currency_code` (the store's, stamped on first save; a stock-ledger fact —
 * never re-interpreted). It keeps the store-precision string shape of the old StoreMoney cast. Query with `unit_cost_minor`.
 *
 * @property string|float|null $unit_cost major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int|null $unit_cost_minor
 * @property string|null $currency_code
 */
class StockMovement extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    public $timestamps = false; // only created_at, set manually

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['unit_cost' => 'unit_cost_minor,store'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public const TYPES = ['purchase', 'sale', 'waste', 'adjustment', 'count', 'transfer'];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
