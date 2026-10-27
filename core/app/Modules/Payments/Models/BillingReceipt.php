<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * MC-02 — a durable charge record. Written BEFORE a transaction is consumed so
 * order-payment charge history survives the settlement that used to delete it
 * (PaymentController::paySuccess). Money is DECIMAL with an explicit
 * currency_code; nothing here is ever converted for display.
 *
 * P1-05: stored as `*_minor` integers in currency_code; `amount`/`base_amount` keep the old decimal:4 string.
 *
 * @property string|null $amount
 * @property string|null $base_amount
 * @property int|null $amount_minor
 * @property int|null $base_amount_minor
 * @property string|null $currency_code
 */
class BillingReceipt extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $table = 'billing_receipts';

    protected $fillable = [
        'user_id',
        'store_id',
        'transaction_id',
        'transaction_method',
        'product_name',
        'product_id',
        'plan_id',
        'addon_type',
        'amount',
        'base_amount',
        'currency_code',
        'gateway',
        'gateway_ref',
        'details',
    ];

    /** @var array<string, string> */
    protected array $minorMoney = [
        'amount' => 'amount_minor,decimal:4',
        'base_amount' => 'base_amount_minor,decimal:4',
    ];

    /** A receipt records a charge in the currency it was made in. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Idempotently record a receipt for a settled transaction. Keyed on
     * transaction_id so a gateway retry (or a re-entry into paySuccess) updates
     * the same row instead of duplicating the charge history.
     *
     * currency_code falls back to the install's platform currency at runtime —
     * never a hardcoded USD (per-install rule).
     */
    public static function recordFromTransaction(Transaction $transaction): self
    {
        return static::updateOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'user_id' => $transaction->user_id,
                'store_id' => $transaction->store_id,
                'transaction_method' => $transaction->transaction_method,
                'product_name' => $transaction->product_name,
                'product_id' => $transaction->product_id,
                'amount' => $transaction->amount ?? 0,
                'base_amount' => $transaction->base_amount,
                'currency_code' => $transaction->currency_code ?: platform_currency(),
                'gateway' => $transaction->transaction_gatway,
                'gateway_ref' => $transaction->payment_id,
                'details' => is_string($transaction->getRawOriginal('details'))
                    ? $transaction->getRawOriginal('details')
                    : (isset($transaction->details) ? json_encode($transaction->details) : null),
            ]
        );
    }
}
