<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS E7: a payment recorded against a supplier. See SupplierBalanceService for
 * the balance (received-goods value − payments) and the interleaved statement.
 *
 * P1-05: `amount` is stored as `amount_minor` in the row's `currency_code` (the supplier's store currency, stamped
 * on first save; a payment — never re-interpreted). It keeps the number shape of the old `float` cast. Query with
 * `amount_minor` (sums: `SupplierPayment::minorMoneySql('amount')`).
 *
 * @property float $amount major amount
 * @property int $amount_minor
 * @property string|null $currency_code
 */
class SupplierPayment extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['amount' => 'amount_minor'];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** A payment belongs to its supplier's store (store_id is copied from the supplier on create, D-P1-21-15). */
    public function storeIdForMoney(): ?int
    {
        return ! empty($this->attributes['store_id']) ? (int) $this->attributes['store_id'] : $this->storeIdVia('suppliers', 'supplier_id', 'store_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
