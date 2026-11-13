<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use App\Models\Ingredient;

/**
 * P1-05: `unit_cost`, `line_total`, `actual_unit_cost` are stored as `*_minor` in the row's `currency_code` (its
 * purchase order's, stamped on first save; never re-interpreted). They keep the store-precision string shape of the
 * old StoreMoney cast. Query with the `*_minor` columns.
 *
 * @property string|float $unit_cost major amount (store-precision string on read, as the old StoreMoney cast)
 * @property string|float $line_total major amount (store-precision string on read)
 * @property string|float|null $actual_unit_cost major amount (store-precision string on read)
 * @property int $unit_cost_minor
 * @property int $line_total_minor
 * @property int|null $actual_unit_cost_minor
 * @property string|null $currency_code
 */
class PurchaseOrderItem extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = [
        'unit_cost' => 'unit_cost_minor,store',
        'line_total' => 'line_total_minor,store',
        'actual_unit_cost' => 'actual_unit_cost_minor,store',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** A line is in its purchase order's currency (else its store's). */
    public function defaultMinorMoneyCurrency(): string
    {
        $poId = $this->getAttribute('purchase_order_id');
        $code = $poId ? DB::table('purchase_orders')->where('id', $poId)->value('currency_code') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    /** A line belongs to its purchase order. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('purchase_orders', 'purchase_order_id', 'store_id');
    }
}
