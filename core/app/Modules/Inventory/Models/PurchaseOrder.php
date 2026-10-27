<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * P1-05: `total` is stored as `total_minor` in the row's `currency_code` (the store's, stamped on first save; a
 * purchase document — never re-interpreted). It keeps the store-precision string shape of the old StoreMoney cast;
 * its lines carry the order's currency. Query with `total_minor`.
 *
 * @property string|float $total major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int $total_minor
 * @property string|null $currency_code
 * @property int|null $branch_id P6-224: the branch it happened at (null = store-wide)
 */
class PurchaseOrder extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['total' => 'total_minor,store'];

    protected $casts = [
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'ordered', 'received', 'cancelled'];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function recalcTotal(): void
    {
        $this->total = round((float) $this->items()->sum(DB::raw(PurchaseOrderItem::minorMoneySql('line_total'))), 2);
        $this->save();
    }
}
