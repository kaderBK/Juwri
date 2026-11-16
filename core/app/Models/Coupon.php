<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;

/**
 * P1-05: `min_order_amount` is stored as `min_order_amount_minor` (nullable) in the row's `currency_code` — the
 * issuer's currency: a reseller coupon its reseller's selling currency, an owner coupon the currency of the owner's
 * first store, a platform coupon the platform's. Stamped on first save; an edited minimum moves to the issuer's
 * current currency (catalogue row). It reads as a currency-precision string ('30.00'). Query with
 * `min_order_amount_minor`.
 *
 * VALUE (bug fix 2026-10-01 — split by type, the price_schedules rule): the old integer `percentage` column held a
 * percent OR an amount and dropped fractions (12.5 %, 4.99). It is now one of two columns, chosen by `coupon_type`:
 *   - 'fixed'            → `amount_minor` (minor units of `currency_code`, the issuer's; read as `amount_off`);
 *   - any other type     → `percentage_rate` (a percent, decimal(7,3) — not money, never rounded to a currency).
 * The other column is NULL. `percentage` stays the form/API/JSON attribute: writing it routes the number to the
 * column of the row's type when the row is saved (whatever the fill order); reading it gives the number (an int when
 * whole — the old shape — else a float: 12.5, 4.99, 1.125). Changing only the type moves the number across.
 * Query with `percentage_rate` / `amount_minor` — `percentage` is not a column.
 *
 * @property string|float|null $min_order_amount
 * @property int|null $min_order_amount_minor
 * @property int|float|null $percentage
 * @property string|null $percentage_rate
 * @property int|null $amount_minor
 * @property float|null $amount_off
 * @property string|null $currency_code
 */
class Coupon extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney {
        save as private saveMinorMoney;
        attributesToArray as private minorMoneyAttributesToArray;
    }

    /** The largest percent the rate column holds (decimal(7,3)); a form caps a percentage coupon at 100. */
    public const MAX_RATE = '9999.999';

    /** @var array<string, string> */
    protected array $minorMoney = ['min_order_amount' => 'min_order_amount_minor,store', 'amount_off' => 'amount_minor'];

    /** A value written to `percentage` since the last save (exact decimal string), routed on save. */
    private ?string $pendingValue = null;

    private bool $hasPendingValue = false;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'percentage',
        'limit',
        'used',
        'expiry_at',
        'coupon_type',
        'user_id',
        // P4 (plans rebuild): interval scoping + first-period flag
        'applies_interval',
        'first_period_only',
        // Coupons & Wallets P4-1: explicit scope + owner-coupon extras.
        'issuer_scope',
        'reseller_id',
        'applies_to',
        'min_order_amount',
        'per_customer_limit',
        'branch_id',
        // HPMS E5: buy-X-get-Y parameters (coupon_type = 'bogo').
        'buy_scope',
        'buy_id',
        'buy_qty',
        'get_id',
        'get_qty',
        'get_discount_percent',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'expiry_at' => 'datetime',
    ];

    /** The issuer's currency: the reseller's, the owner's first store's, else the platform's. */
    public function defaultMinorMoneyCurrency(): string
    {
        if (! empty($this->attributes['reseller_id'])) {
            // P1-13 13f: the reseller's billing currency (its currency_code, else the platform's — as the Reseller
            // module's ResellerCurrency::code()), read through Core's extension point (Community: no resellers).
            $reseller = app(ResellerContext::class)->find((int) $this->attributes['reseller_id']);
            $code = $reseller?->getAttribute('currency_code') ?: platform_currency();

            return strtoupper(trim((string) $code)) ?: 'USD';
        }
        if (! empty($this->attributes['user_id'])) {
            $storeId = Store::where('user_id', $this->attributes['user_id'])->orderBy('id')->value('id');
            if ($storeId) {
                return strtoupper(store_currency((int) $storeId));
            }
        }

        return $this->defaultStoreOrPlatformCurrency();
    }

    /** Is this type's value an amount of money (else a percent)? The column default type is 'percentage'. */
    public static function typeIsAmount(?string $type): bool
    {
        return $type === 'fixed';
    }

    /**
     * Validation rules for the coupon value of a type: a percent 0 < x <= 100 with up to 3 decimals, or an amount
     * > 0 in the currency's decimals (USD 4.99, KWD 1.125, JPY 500).
     *
     * @return list<string>
     */
    public static function valueRules(?string $type, ?string $currency, int|float $maxAmount = 9999999): array
    {
        if (self::typeIsAmount($type)) {
            return ['numeric', 'gt:0', 'decimal:0,'.Currencies::exponent($currency), 'max:'.$maxAmount];
        }

        return ['numeric', 'gt:0', 'max:100', 'decimal:0,3'];
    }

    /**
     * BL-036 C7: one rule for a date-only expiry typed into a store-owner or reseller coupon form. The value must
     * be a real Y-m-d calendar date (a typo like year 12026 or 30 February is refused, never silently read as
     * another date) and not before today in the issuer's timezone. Re-sending the stored expiry unchanged is
     * always accepted, so an already expired coupon can still be edited without picking a new date. One plain,
     * translated message per failure.
     *
     * @param  string  $tz  the issuer's timezone ("today" and the stored date are read in it)
     * @param  string|null  $storedLocalDate  the coupon's current expiry as a Y-m-d date in $tz (edit only)
     * @return list<string|\Closure>
     */
    public static function expiryDateRules(string $tz, ?string $storedLocalDate = null, bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', function (string $attribute, mixed $value, \Closure $fail) use ($tz, $storedLocalDate): void {
            if (! self::isCalendarDate($value)) {
                $fail(___('Enter a valid expiry date.'));

                return;
            }
            if ($value !== $storedLocalDate && $value < Carbon::now($tz)->toDateString()) {
                $fail(___('The expiry date cannot be in the past.'));
            }
        }];
    }

    /** BL-036 C7: a real Y-m-d calendar date with a four-digit year (no 12026, no 2031-02-30). */
    public static function isCalendarDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A date-only expiry means the END of that day in the issuer's timezone (23:59:59 local), stored in the app
     * timezone: a coupon dated today is still valid until local midnight.
     */
    public static function expiryEndOfDay(?string $date, string $tz): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }

        return Carbon::parse($date, $tz)->setTime(23, 59, 59)->setTimezone((string) (config('app.timezone') ?: 'UTC'));
    }

    /** The legacy single-number attribute: write. Routed to the type's column on save. */
    public function setPercentageAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $value = $value->major();
        }
        $this->pendingValue = ($value === null || $value === '') ? null
            : (string) Money::decimal(is_numeric($value) ? $value : (float) $value);
        $this->hasPendingValue = true;
    }

    /** The legacy single-number attribute: read — an int when whole (the old shape), else a float. */
    public function getPercentageAttribute(): int|float|null
    {
        if ($this->hasPendingValue) {
            return self::number($this->pendingValue);
        }
        if (! array_key_exists('percentage_rate', $this->attributes) && ! array_key_exists('amount_minor', $this->attributes)) {
            return null; // not selected
        }
        $value = self::typeIsAmount($this->attributes['coupon_type'] ?? null)
            ? $this->money('amount_off')?->major()
            : ($this->attributes['percentage_rate'] ?? null);

        return self::number($value) ?? 0;
    }

    /** The percent of a non-fixed coupon (null for a fixed one). */
    public function discountPercent(): ?float
    {
        if (self::typeIsAmount($this->attributes['coupon_type'] ?? null)) {
            return null;
        }

        return (float) ($this->percentage ?? 0);
    }

    /** The amount off of a fixed coupon in major units of its currency (null for a percent coupon). */
    public function discountAmount(): ?float
    {
        if (! self::typeIsAmount($this->attributes['coupon_type'] ?? null)) {
            return null;
        }

        return (float) ($this->percentage ?? 0);
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $exact = Money::decimal(is_numeric($value) ? (string) $value : (float) $value)->strippedOfTrailingZeros();

        return $exact->getScale() <= 0 ? $exact->toInt() : $exact->toFloat();
    }

    /** Route the value to the column of the row's type (exactly one is set). */
    public function save(array $options = [])
    {
        $isAmount = self::typeIsAmount($this->attributes['coupon_type'] ?? null);
        $value = null;
        if ($this->hasPendingValue) {
            $value = $this->pendingValue ?? '0';
        } elseif (! $this->exists && ! array_key_exists('percentage_rate', $this->attributes) && ! array_key_exists('amount_minor', $this->attributes)) {
            $value = '1'; // the old column default
        } elseif ($this->exists && $this->isDirty('coupon_type') && self::typeIsAmount($this->getOriginal('coupon_type')) !== $isAmount) {
            // Only the type changed: the number moves to the other column unchanged.
            $value = $isAmount ? (string) Money::decimal((string) ($this->attributes['percentage_rate'] ?? 0))
                : ($this->money('amount_off')?->major() ?? '0');
        }
        if ($value !== null) {
            if ($isAmount) {
                $this->attributes['percentage_rate'] = null;
                $this->setAttribute('amount_off', $value);
            } else {
                $rate = Money::decimal($value)->toScale(3, RoundingMode::HalfUp);
                $max = Money::decimal(self::MAX_RATE);
                $rate = $rate->isGreaterThan($max) ? $max : ($rate->isLessThan($max->negated()) ? $max->negated() : $rate);
                $this->attributes['percentage_rate'] = (string) $rate;
                $this->attributes['amount_minor'] = null;
                unset($this->minorMoneyPending['amount_off']);
            }
        }
        $this->pendingValue = null;
        $this->hasPendingValue = false;
        // P6-215: the per-issuer unique key (unique(issuer_key, code_key)).
        // Only from a full picture: a new row, or a loaded row whose code/issuer changed (never from a partial select).
        if (! $this->exists || $this->isDirty(['code', 'user_id', 'reseller_id'])) {
            $this->attributes['issuer_key'] = self::issuerKey($this->attributes['user_id'] ?? null, $this->attributes['reseller_id'] ?? null);
            $this->attributes['code_key'] = self::codeKey((string) ($this->attributes['code'] ?? ''));
        }

        return $this->saveMinorMoney($options);
    }

    /** JSON keeps the legacy `percentage` key next to the explicit columns. */
    public function attributesToArray()
    {
        $array = $this->minorMoneyAttributesToArray();
        if (! in_array('percentage', $this->getHidden(), true)
            && (array_key_exists('percentage_rate', $this->attributes) || array_key_exists('amount_minor', $this->attributes) || $this->hasPendingValue)) {
            $array['percentage'] = $this->getPercentageAttribute();
        }

        return $array;
    }

    /** P6-215: who a code is unique within — an owner, a reseller, else the platform. */
    public static function issuerKey(mixed $userId, mixed $resellerId): string
    {
        return ! empty($userId) ? 'u:'.(int) $userId : (! empty($resellerId) ? 'r:'.(int) $resellerId : 'p');
    }

    /** P6-215: codes compare case-insensitively (as CouponService::resolve matches them). */
    public static function codeKey(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    /** P6-215: does this issuer already hold the code (any case)? Other issuers' codes never count. */
    public static function codeTaken(string $code, ?int $userId, ?int $resellerId = null, ?int $ignoreId = null): bool
    {
        return self::query()->withoutGlobalScopes()
            ->where('issuer_key', self::issuerKey($userId, $resellerId))
            ->where('code_key', self::codeKey($code))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /** P6-215: validation rule — the code is free for this issuer. Never says another issuer has it. */
    public static function uniqueCodeRule(?int $userId, ?int $resellerId = null, ?int $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($userId, $resellerId, $ignoreId): void {
            if (is_string($value) && self::codeTaken($value, $userId, $resellerId, $ignoreId)) {
                $fail(___('You already have a coupon with this code.'));
            }
        };
    }

    /**
     * Coupons C1: the generated batch this code belongs to (null for a hand-made coupon).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Juwri\Modules\Loyalty\Models\CouponBatch, $this>
     */
    public function batch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Juwri\Modules\Loyalty\Models\CouponBatch::class, 'batch_id');
    }

    /** Has the expiry moment passed? (A coupon without one is read as "now".) */
    public function isExpiry(): bool
    {
        return Carbon::parse($this->expiry_at)->lessThan(Carbon::now());
    }
}
