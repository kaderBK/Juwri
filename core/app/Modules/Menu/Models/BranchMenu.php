<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Branch;

/**
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 * @property string|float|null $price
 * @property int|null $price_minor
 * @property string|null $currency_code
 */
class BranchMenu extends Model implements \Juwri\Core\Money\MinorMoneyModel
{
    use BelongsToAccount;
    use ResolvesStoreForMoney;

    use HasFactory;
    use \App\Models\Concerns\BustsMenuCache; // P5
    use \Juwri\Core\Money\Concerns\HasMinorMoney;

    /** @var array<string, string> P1-05: store-precision string, as the old StoreMoney cast */
    protected array $minorMoney = ['price' => 'price_minor,store'];

    protected $table = 'branch_menu';

    /** P5: the store this branch-override row belongs to (via its menu). */
    public function menuCacheRestaurantId(): ?int
    {
        $rid = $this->menu?->store_id ?? Menu::where('id', $this->menu_id)->value('store_id');

        return $rid ? (int) $rid : null;
    }

    protected $guarded = [];

    protected $casts = [
        'available' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** HasMinorMoney (row currency): a branch price override belongs to a menu item. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('items', 'menu_id', 'store_id');
    }
}
