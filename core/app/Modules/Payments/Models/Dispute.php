<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * GAPS-RUN-2 B11-P1: a payment dispute / chargeback.
 *
 * @property string|float $amount P1-05: major amount in `currency` (precision string on read)
 * @property int $amount_minor P1-05: minor units of `currency`
 * @property string|null $currency
 */
class Dispute extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    protected $casts = [
        'evidence_due_at' => 'datetime',
        'evidence_submitted_at' => 'datetime', // WIRING-RUN E6
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * P1-05: `amount` is stored as `amount_minor` in the dispute's own `currency` (the gateway's); a dispute
     * without one takes its store's currency, else the platform's.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['amount' => 'amount_minor,store'];

    protected string $minorMoneyCurrencyColumn = 'currency';

    /** P1-05: the disputed charge is a fact in the gateway's currency. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['won', 'lost', 'warning_closed', 'charge_refunded'], true);
    }
}
