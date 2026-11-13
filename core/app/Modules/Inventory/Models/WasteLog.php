<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Models;

use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * P1-05: `cost` is stored as `cost_minor` in the row's `currency_code` (the store's, stamped on first save; a recorded loss —
 * never re-interpreted). It keeps the store-precision string shape of the old StoreMoney cast. Query with `cost_minor`.
 *
 * @property string|float $cost major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int $cost_minor
 * @property string|null $currency_code
 * @property int|null $branch_id P6-224: the branch it happened at (null = store-wide)
 */
class WasteLog extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    public $timestamps = false; // only created_at

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['cost' => 'cost_minor,store'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public const REASONS = ['spoilage', 'expiry', 'prep_error', 'theft', 'overproduction', 'other'];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
