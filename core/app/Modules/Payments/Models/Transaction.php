<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * The 'object' cast normally decodes billing to a stdClass, but legacy rows written
 * during the double-encode bug read back as a JSON string, and it may be an array
 * before save — billingArray() normalizes all of these. Typed honestly here so the
 * defensive is_string() branch is not seen as dead.
 *
 * @property array<array-key, mixed>|\stdClass|string|null $billing
 * @property float|null $amount P1-05: major amounts in currency_code (number, like the old double column)
 * @property float|null $base_amount
 * @property int|null $amount_minor P1-05: minor units of currency_code
 * @property int|null $base_amount_minor
 * @property string|null $currency_code
 */
class Transaction extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;
    use HasUuids;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PENDING = 'pending';

    /** B1: a delayed payment the provider reported as failed (Stripe checkout.session.async_payment_failed) — never settles. */
    public const STATUS_FAILED = 'failed';

    /** MC-02: terminal state for a settled order payment whose charge history is
     *  preserved as a billing_receipt instead of being deleted. */
    public const STATUS_CONSUMED = 'consumed';

    /** BL-038 P1: a recurring charge whose amount or currency did not match — recorded, never settled (RenewalAmountHold). */
    public const STATUS_REVIEW = 'review';

    /**
     * BL-039: a PayPal subscription checkout created on the gateway and awaiting its first
     * `PAYMENT.SALE.COMPLETED` webhook. Kept by hourly housekeeping and hidden from user/admin
     * payment lists until the first sale settles (which transitions it to `recurring_setup`).
     */
    public const STATUS_RECURRING_PENDING = 'recurring_pending';

    /**
     * BL-038: a merchant-of-record checkout (Paddle, Lemon Squeezy) whose first payment settled and a gateway
     * subscription follows — the settlement wrote its own `success` row; this one stays findable for the renewals,
     * which are bound to it by id (MorPaymentGuard). Never a payment itself. Also (BL-039) the checkout row a PayPal
     * subscription was sold from — its `custom_id` carries only a short local reference and renewals read from here.
     */
    public const STATUS_RECURRING_SETUP = 'recurring_setup';

    /**
     * BL-038: a merchant-of-record checkout whose one-time payment settled — kept for the gateway's later events, never
     * a payment itself (not `consumed`, which refunds and reports read as paid).
     */
    public const STATUS_CHECKOUT_SETTLED = 'checkout_settled';

    /** BL-038 review: a pending checkout the buyer will not pay (replaced, failed to start, cancelled) — AbandonedCheckout. */
    public const STATUS_ABANDONED = 'abandoned';

    /**
     * Statuses of a checkout row kept only as bookkeeping — never a payment, so never listed as one.
     *
     * @var list<string>
     */
    public const CHECKOUT_BOOKKEEPING = [self::STATUS_RECURRING_PENDING, self::STATUS_RECURRING_SETUP, self::STATUS_CHECKOUT_SETTLED, self::STATUS_ABANDONED];

    /** Merchant-of-record gateways: their checkout row carries the gateway's later events. */
    public const MOR_GATEWAYS = ['paddle', 'lemon_squeezy'];

    /**
     * P1-05 (J25-4): charge amounts are `*_minor` integers in the row's `currency_code` (stamped on first save: the
     * store's currency for an order payment, else the platform's). `amount`/`base_amount` stay readable/writable as
     * numbers; SQL must use the `*_minor` columns (sums: {@see HasMinorMoney::minorMoneySql()}).
     *
     * @var array<string, string>
     */
    protected array $minorMoney = [
        'amount' => 'amount_minor',
        'base_amount' => 'base_amount_minor',
    ];

    /** A charge is a fact in the currency it was made in. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /**
     * The stored attributes with the money in the legacy shape (`amount`/`base_amount` as major numbers in place of
     * the `*_minor` columns) — the checkout "passthrough" gateways round-trip as metadata/custom data and hand back to
     * Billing's CheckoutController::processWebhook() (through Payments\Support\Fulfilment), which reads `amount`/`base_amount` (P1-05: the wire shape is unchanged).
     *
     * @return array<string, mixed>
     */
    public function legacyAttributes(): array
    {
        $flip = array_flip($this->minorMoneyMap());
        $out = [];
        foreach ($this->getAttributes() as $key => $value) {
            if (isset($flip[$key])) {
                $out[$flip[$key]] = $this->getAttribute($flip[$key]);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** Legacy singular table name (payments, subscriptions and order payments share it). */
    public $table = 'transaction';

    /**
     * Columns the payment flows write in one create()/update().
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_name',
        'product_id',
        'user_id',
        'store_id',
        'status',
        'amount',
        'base_amount',
        'currency_code',
        'payment_id',
        'transaction_gatway',
        'transaction_method',
        'transaction_description',
        'transaction_ip',
        'frequency',
        'billing',
        'taxes_ids',
        'details',
        'coupon',
        'featured',
        'urgent',
        'highlight',
        'invoice_number',
        'credited_at',
    ];

    /**
     * JSON columns read back as objects; see the class docblock for billing.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'coupon' => 'object',
        'billing' => 'object',
        'details' => 'object',
        'credited_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /**
     * P6-246: a row that is a money fact (paid, settled into a receipt, or a refund / credit note) is never
     * hard-deleted by an admin; it can only be voided (kept, stamped, logged). Unpaid / failed / abandoned rows can go.
     */
    public function isPaidRecord(): bool
    {
        return in_array((string) $this->status, [self::STATUS_SUCCESS, self::STATUS_CONSUMED, 'refunded'], true)
            || (string) $this->transaction_method === 'refund';
    }

    /**
     * The paying owner (plans, add-ons, top-ups, …). NULL on an order payment — the diner is a customer, not a
     * user; the order's store is {@see store()}.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The store an order payment (transaction_method 'order') belongs to; NULL on platform payments.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Syria wallet_transfer: the order a CLAIM is for (product_id → orders.id).
     * Only meaningful for transaction_method='order' wallet_transfer rows; the
     * Order Hub claims list attaches/reads it as $tx->claimOrder.
     *
     * @return BelongsTo<Order, $this>
     */
    public function claimOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'product_id');
    }

    /**
     * Words the system wrote into `product_name` / `transaction_description` when the payment was made ("App: X",
     * "App purchase: x", "Wallet top-up"). The stored text stays as written (receipts and accounting read it); screens show it through
     * {@see displayName()} / {@see displayDescription()} so an Arabic operator does not read English labels another
     * language's session wrote (found by the P1-12 Arabic journey).
     */
    private static function localised(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        if ($text === 'Wallet top-up') { // written by CustomerWalletService / StorePurchaseService in the language of the session that paid
            return ___('Wallet top-up');
        }
        if (str_starts_with($text, 'App purchase: ')) {
            return ___('App purchase: :app', ['app' => substr($text, 14)]);
        }
        if ($text === 'Wallet top-up') {
            return ___('Wallet top-up');
        }
        if (str_starts_with($text, 'App: ')) {
            return ___('App: :app', ['app' => substr($text, 5)]);
        }

        return $text;
    }

    /** `product_name` for a screen, in the current language. */
    public function displayName(): ?string
    {
        return self::localised($this->product_name);
    }

    /** `transaction_description` for a screen, in the current language. */
    public function displayDescription(): ?string
    {
        return self::localised($this->transaction_description);
    }

    // #68: Atomically assign a sequential invoice number if not already set.
    public function ensureInvoiceNumber(): string
    {
        if ($this->invoice_number) {
            return $this->invoice_number;
        }

        return \DB::transaction(function () {
            // Re-fetch with lock to avoid concurrent duplicates
            $tx = static::lockForUpdate()->find($this->id);
            if ($tx->invoice_number) {
                return $tx->invoice_number;
            }

            $prefix = config('settings.invoice_nr_prefix', 'INV-');
            $year = date('Y');
            // Count all invoices in this year to determine sequence
            $seq = static::whereYear('created_at', $year)
                ->whereNotNull('invoice_number')
                ->count() + 1;
            $number = $prefix.$year.'-'.str_pad($seq, 5, '0', STR_PAD_LEFT);

            $tx->update(['invoice_number' => $number]);
            $this->invoice_number = $number;

            return $number;
        });
    }

    /**
     * P1-03 (J25-1): UUIDv7 public id (HasUuids) for URLs/APIs; the bigint id stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * BL-038: a merchant-of-record settlement marks the account's checkout row it was sold from — `recurring_setup`
     * when a gateway subscription follows (`$recurring` null = judged from the row: monthly / yearly), else
     * `checkout_settled` — so the hourly cleanup keeps it for the gateway's later events. Only a row of that account
     * still without a status, awaiting its first PayPal sale (`recurring_pending`), or `abandoned` (BL-038 review:
     * money captured on a checkout already closed is settled, never refused; a charge paid one period at a time —
     * details.one_time_only — is no subscription). Called by the MoR gateway controllers right after a 200 settlement (and on a re-delivery
     * of a settled payment) — not inside the settlement's DB transaction: a crash between the two leaves the row
     * unmarked until the next delivery of that payment, or the backfill migration (mark_settled_mor_checkouts).
     */
    public static function markCheckoutSettled(int $checkoutId, int|string|null $userId, ?bool $recurring = null): void
    {
        $awaiting = fn ($q) => $q->whereNull('status')->orWhereIn('status', [self::STATUS_RECURRING_PENDING, self::STATUS_ABANDONED]);
        $checkout = static::query()->withoutGlobalScopes()->whereKey($checkoutId)->where($awaiting)->first();
        if ($checkout === null || (string) $checkout->user_id !== (string) $userId) {
            return;
        }
        $recurring ??= in_array($checkout->frequency, ['MONTHLY', 'YEARLY'], true) && data_get($checkout->details, 'one_time_only') !== true;
        static::query()->withoutGlobalScopes()->whereKey($checkoutId)->where($awaiting)
            ->update(['status' => $recurring ? self::STATUS_RECURRING_SETUP : self::STATUS_CHECKOUT_SETTLED]);
    }
}
