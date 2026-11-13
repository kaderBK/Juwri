<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * PARITY-RUN T6: one pickable component inside a combo slot.
 *
 * `upcharge` is the ONLY money this row contributes. The component's own menu
 * price is NOT charged — the diner already paid the bundle price — but it is
 * read (via {@see componentPrice()}) for the owner's "sum vs bundle" saving
 * preview and for the storefront "you save" badge.
 *
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 * @property string|float|null $upcharge
 * @property int|null $upcharge_minor
 * @property string|null $currency_code
 */
class ComboSlotOption extends Model implements \Juwri\Core\Money\MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use \Juwri\Core\Money\Concerns\HasMinorMoney;

    /** @var array<string, string> P1-05: store-precision string, as the old StoreMoney cast */
    protected array $minorMoney = ['upcharge' => 'upcharge_minor,store'];

    protected $guarded = [];

    protected $casts = [
        'active'   => 'boolean',
    ];

    /** Used by HasMinorMoney for the row currency — see Combo::storeIdForMoney(). */
    public function storeIdForMoney(): ?int
    {
        return $this->store_id ? (int) $this->store_id : null;
    }

    public function slot()
    {
        return $this->belongsTo(ComboSlot::class, 'combo_slot_id');
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function variant()
    {
        return $this->belongsTo(MenuVariant::class, 'variant_id');
    }

    /**
     * The component's à-la-carte price — variant price REPLACES the base item
     * price (it is never additive; see the money contract in
     * rev/parity/contracts/seam-audit.md). Display/preview only: this number is
     * never charged.
     */
    public function componentPrice(): float
    {
        if ($this->variant_id && $this->variant) {
            return (float) $this->variant->price;
        }

        return (float) ($this->menu->price ?? 0);
    }

    /** Is this component orderable right now (exists, not sold out, in window)? */
    public function isOrderable(?string $tz = null): bool
    {
        $menu = $this->menu;
        if (! $menu || ! $this->active) {
            return false;
        }
        if ($menu->is_sold_out) {
            return false;
        }

        return $menu->isAvailableNow($tz);
    }
}
