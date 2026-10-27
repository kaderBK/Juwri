<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * PARITY-RUN T6: a combo / meal deal — a bundle of choice slots sold at ONE
 * bundle price.
 *
 * `price` is the whole price of the bundle. Component menu prices are never
 * added to it; the only thing that can raise it is a per-option `upcharge` on
 * {@see ComboSlotOption} (a "+1.00 for a large drink" style top-up).
 *
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 *
 * @property string|float|null $price
 * @property int|null $price_minor
 * @property string|null $currency_code
 */
class Combo extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    /** @var array<string, string> P1-05: store-precision string, as the old StoreMoney cast */
    protected array $minorMoney = ['price' => 'price_minor,store'];

    protected $guarded = [];

    protected $casts = [
        // Store-owned money: a 3-decimal currency (OMR/KWD/JOD/BHD) must keep its
        // fils. The cast resolves precision from `store_id` (its first
        // lookup) and falls back to storeIdForMoney() below.
        'active' => 'boolean',
    ];

    /**
     * Used by HasMinorMoney to pick the row currency (store's, else the platform's).
     * `store_id` is a real column here so it resolves before ever reaching this
     * method — implemented anyway so the contract survives a future rename (a
     * missing storeIdForMoney() would silently fall back to the platform currency).
     */
    public function storeIdForMoney(): ?int
    {
        return $this->store_id ? (int) $this->store_id : null;
    }

    public function slots()
    {
        return $this->hasMany(ComboSlot::class)->orderBy('position')->orderBy('id');
    }

    public function options()
    {
        return $this->hasMany(ComboSlotOption::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Active combos for a store, in owner-defined order, eager for the storefront. */
    public function scopeSellable($query, int $storeId, $branchId = null)
    {
        return $query->where('store_id', $storeId)
            ->where('active', true)
            ->when($branchId, fn ($q) => $q->where(function ($w) use ($branchId) {
                $w->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * What the same picks would cost à la carte — the number the owner's price
     * preview and the storefront "you save" badge compare the bundle against.
     * Uses the CHEAPEST option in every slot so the saving is never overstated.
     */
    public function alaCarteFloor(): float
    {
        $sum = 0.0;
        foreach ($this->slots as $slot) {
            $prices = [];
            foreach ($slot->options as $opt) {
                if (! $opt->active || ! $opt->menu) {
                    continue;
                }
                $prices[] = $opt->componentPrice() + (float) $opt->upcharge;
            }
            if (! $prices) {
                continue;
            }
            sort($prices);
            // A "pick N" slot takes its N cheapest options.
            $take = max(1, (int) $slot->min_select);
            $sum += array_sum(array_slice($prices, 0, $take));
        }

        return round($sum, money_decimals(store_options($this->store_id, 'currency_code') ?: null));
    }
}
