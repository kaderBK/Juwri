<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * SYSTEMS-RUN SUP-P1: a captured supplier invoice (manual or OCR).
 *
 * P1-05: `total` is stored as `total_minor` in the row's `currency_code` (the store's, stamped on first save; a
 * document — never re-interpreted). It keeps the store-precision string shape of the old StoreMoney cast. The OCR
 * `lines` JSON keeps its major amounts. Query with `total_minor`.
 *
 * @property string|float $total major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int $total_minor
 * @property string|null $currency_code
 */
class SupplierInvoice extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    protected $casts = ['lines' => 'array'];

    /** @var array<string, string> */
    protected array $minorMoney = ['total' => 'total_minor,store'];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }
}
