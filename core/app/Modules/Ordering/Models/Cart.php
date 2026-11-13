<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * @property float|null $tip_amount P1-05: major amount
 * @property int|null $tip_amount_minor P1-05: minor units of currency_code (the store's currency)
 * @property string|null $currency_code
 */
class Cart extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use HasFactory;

    protected $fillable = [
        'shopper_id',
        'guest_token',
        'client_uuid',      // FM-01: per-cart order-dedup token, rotated on checkout
        'store_id',
        'mk_reseller_id',   // FM-03: 0 = platform, the tenant this cart belongs to
        'status',
        'expires_at',
        'fulfillment_mode', // T3-2: 'delivery' | 'pickup' (null = delivery)
        'scheduled_at',     // T3-2: picked slot (null = ASAP)
        'tip_amount',       // T3-3: absolute tip for this store (delivery only)
        'cutlery',          // T3-3: include cutlery (eco-default OFF)
        'donation',         // T3-3: whole-bag round-up flag, uniform across rows
    ];

    /**
     * P1-05: `tip_amount` (a number, as the old float cast) is stored as `tip_amount_minor`.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['tip_amount' => 'tip_amount_minor'];

    protected $casts = [
        'mk_reseller_id' => 'integer',
        'expires_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'cutlery' => 'boolean',
        'donation' => 'boolean',
    ];

    /** @return HasMany<CartLine, $this> */
    public function lines(): HasMany
    {
        // Insertion order: PostgreSQL returns an updated row last without an ORDER BY, which reordered the cart
        // and changed which line setItem() treats as "the first line" after a quantity edit.
        return $this->hasMany(CartLine::class)->orderBy('id');
    }

    public function scopeLive($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
