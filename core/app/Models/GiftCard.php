<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\LedgerAccount;

/**
 * T2 gift card. Spendable value is the balance of its ledger account
 * (kind 'gift_card', owner_id = this id); this row is identity + policy + status.
 *
 * P1-05: `initial_value` (the face value) is stored as `initial_value_minor` in the card's `currency` (a fact). It
 * keeps the store-precision string shape of the old StoreMoney cast.
 *
 * @property string|float $initial_value
 * @property int $initial_value_minor
 * @property string $currency
 */
class GiftCard extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['initial_value' => 'initial_value_minor,store'];

    protected ?string $minorMoneyCurrencyColumn = 'currency';

    protected $casts = [
        'issued_at' => 'datetime',
        'scheduled_send_at' => 'datetime',
        'delivered_at' => 'datetime',
        'expires_at' => 'datetime',
        // P6-214: delivery retry bookkeeping. attempts counts how many times we have
        // tried to send; next_try_at backs the scheduled tick off (5/10/20/40/60 min)
        // until the count hits GIFT_CARD_MAX_DELIVERY_ATTEMPTS, when failed_at is set
        // and the card is considered permanently undelivered.
        'delivery_attempts' => 'integer',
        'delivery_next_try_at' => 'datetime',
        'delivery_failed_at' => 'datetime',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** The ledger account holding this card's spendable balance. */
    public function ledgerAccount(): LedgerAccount
    {
        return app(Ledger::class)->accountFor('gift_card', (int) $this->id, 'gift_card', $this->currency);
    }

    /** Authoritative spendable balance (from the ledger, never a stored column). */
    public function balance(): float
    {
        return app(Ledger::class)->accountBalance($this->ledgerAccount());
    }

    /** Has the card lapsed by its own expiry date? */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Can this card currently be redeemed at all? */
    public function isRedeemable(): bool
    {
        return $this->status === 'active' && ! $this->isExpired() && $this->balance() > 0.0;
    }

    /**
     * P6-214: has the email delivery been permanently abandoned?
     * Only true after MAX attempts have all failed; a transient failure leaves
     * delivery_failed_at null and the scheduled tick picks the card up again.
     */
    public function isDeliveryFailed(): bool
    {
        return $this->delivery_failed_at !== null;
    }
}
