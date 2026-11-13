<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\AppliesTimeWindow;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;

/**
 * PARITY-RUN T5: a happy hour / day-part price rule.
 *
 * Pricing is resolved SERVER-SIDE only — see
 * {@see MenuScheduleService}. Nothing a theme, cart
 * or POS client sends is ever trusted for money; the client is told the price
 * so it can display it, and the server independently recomputes and snapshots
 * it onto the order line at order time.
 *
 * MONEY (P1-05, owner decision 2026-10-01 — split by type): the adjustment is stored in ONE of two columns,
 * chosen by `adjustment_type`, so each row says what its number means:
 *   - `percent`               → `adjustment_rate`  (a percentage, decimal — not money; never rounded to a currency);
 *   - `amount` / `fixed_price` → `adjustment_minor` (minor units of `currency_code`, the store's — stamped on first
 *                               save, moved to the store's current currency when edited; read as `adjustment_amount`).
 * The other column is NULL. `adjustment_value` stays the form/API attribute: writing it routes the number to the
 * column of the row's type when the row is saved (whatever the fill order); reading it gives the number in the old
 * store-precision string shape ('15.00', '0.125'). Changing only the type moves the number across (the decimal
 * column kept the number). JSON keeps `adjustment_value` and adds the two columns + `adjustment_amount`.
 *
 * @property string|null $adjustment_value
 * @property float|string|null $adjustment_rate
 * @property float|null $adjustment_amount
 * @property int|null $adjustment_minor
 * @property string|null $currency_code
 */
class PriceSchedule extends Model implements MinorMoneyModel
{
    use AppliesTimeWindow;
    use BelongsToAccount;
    use Concerns\BustsMenuCache;
    use Concerns\TranslatesForStorefront;
    use HasFactory;
    use HasMinorMoney {
        save as private saveMinorMoney;
        attributesToArray as private minorMoneyAttributesToArray;
    }

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['adjustment_amount' => 'adjustment_minor'];

    /** A value written to `adjustment_value` since the last save (exact decimal string), routed on save. */
    private ?string $pendingAdjustment = null;

    private bool $hasPendingAdjustment = false;

    protected $casts = [
        'label_translations' => 'object',
        'active' => 'boolean',
        'priority' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public const SCOPES = ['store', 'category', 'item'];

    public const TYPES = ['percent', 'amount', 'fixed_price'];

    /* orders.type vocabulary — the storefront, POS and aggregators all use it. */
    public const ORDER_TYPES = ['on-table', 'takeaway', 'delivery', 'room_service'];

    /** Is this type's adjustment a percentage (else an amount of money)? The column default type is 'percent'. */
    public static function typeIsRate(?string $type): bool
    {
        return ($type ?? 'percent') === 'percent';
    }

    /** The legacy single-number attribute: write. Routed to the type's column on save. */
    public function setAdjustmentValueAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $value = $value->major();
        }
        $this->pendingAdjustment = ($value === null || $value === '') ? null
            : (string) Money::decimal(is_numeric($value) ? $value : (float) $value);
        $this->hasPendingAdjustment = true;
    }

    /** The legacy single-number attribute: read, in the old store-precision string shape. */
    public function getAdjustmentValueAttribute(): ?string
    {
        $type = $this->attributes['adjustment_type'] ?? null;
        if ($this->hasPendingAdjustment) {
            $value = $this->pendingAdjustment;
        } elseif (self::typeIsRate($type)) {
            $value = $this->attributes['adjustment_rate'] ?? null;
            $value = ($value === null || $value === '') ? null : (string) Money::decimal($value);
        } else {
            $money = $this->money('adjustment_amount');
            $value = $money?->major();
        }
        if ($value === null) {
            return array_key_exists('adjustment_rate', $this->attributes) || array_key_exists('adjustment_minor', $this->attributes)
                ? $this->formatAdjustment('0') : null;
        }

        return $this->formatAdjustment($value);
    }

    /** The percentage of a `percent` rule (null for an amount rule). */
    public function adjustmentRate(): ?float
    {
        if (! self::typeIsRate($this->attributes['adjustment_type'] ?? null)) {
            return null;
        }
        if ($this->hasPendingAdjustment) {
            return (float) ($this->pendingAdjustment ?? 0);
        }

        return (float) ($this->attributes['adjustment_rate'] ?? 0);
    }

    /** The money amount of an `amount` / `fixed_price` rule (null for a percent rule), major units. */
    public function adjustmentAmount(): ?float
    {
        if (self::typeIsRate($this->attributes['adjustment_type'] ?? null)) {
            return null;
        }
        if ($this->hasPendingAdjustment) {
            return (float) ($this->pendingAdjustment ?? 0); // not saved yet (a preview / an unsaved rule)
        }

        return (float) ($this->adjustment_amount ?? 0);
    }

    /** The store-precision string the old StoreMoney cast gave (a percent keeps up to 3 decimals). */
    private function formatAdjustment(string $value): string
    {
        $currency = $this->minorMoneyCurrency();
        $decimals = Currencies::exponent($currency); // bug batch 9 · 2: the stored precision, not the display one
        $exact = Money::decimal($value);
        if (self::typeIsRate($this->attributes['adjustment_type'] ?? null)) {
            $fraction = rtrim(explode('.', (string) $exact.'.')[1], '0');
            $decimals = max($decimals, min(3, strlen($fraction)));
        }

        return number_format($exact->toFloat(), $decimals, '.', '');
    }

    /** Route the adjustment to the column of the row's type (exactly one is set). */
    public function save(array $options = [])
    {
        $type = $this->attributes['adjustment_type'] ?? null;
        $isRate = self::typeIsRate($type);
        $value = null;
        if ($this->hasPendingAdjustment) {
            $value = $this->pendingAdjustment ?? '0';
        } elseif (! $this->exists && ! array_key_exists('adjustment_rate', $this->attributes) && ! array_key_exists('adjustment_minor', $this->attributes)) {
            $value = '0'; // the old column default
        } elseif ($this->exists && $this->isDirty('adjustment_type') && self::typeIsRate($this->getOriginal('adjustment_type')) !== $isRate) {
            // Only the type changed: the number moves to the other column unchanged.
            $value = $isRate ? ($this->money('adjustment_amount')?->major() ?? '0')
                : (string) Money::decimal($this->attributes['adjustment_rate'] ?? 0);
        }
        if ($value !== null) {
            if ($isRate) {
                $this->attributes['adjustment_rate'] = $value;
                $this->attributes['adjustment_minor'] = null;
                $this->minorMoneyPending = [];
            } else {
                $this->attributes['adjustment_rate'] = null;
                $this->setAttribute('adjustment_amount', $value);
            }
        }
        $this->pendingAdjustment = null;
        $this->hasPendingAdjustment = false;

        return $this->saveMinorMoney($options);
    }

    /** JSON keeps the legacy `adjustment_value` key next to the explicit columns. */
    public function attributesToArray()
    {
        $array = $this->minorMoneyAttributesToArray();
        if (! in_array('adjustment_value', $this->getHidden(), true)) {
            $array['adjustment_value'] = $this->getAdjustmentValueAttribute();
        }

        return $array;
    }

    public function targets()
    {
        return $this->hasMany(PriceScheduleTarget::class, 'schedule_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Is this rule live at `$at` (a store-local Carbon) for `$orderType`?
     * Date range → day codes + time window → order-type scope.
     */
    public function isLiveAt(Carbon $at, ?string $orderType = null): bool
    {
        if (! $this->active) {
            return false;
        }

        /* Compared as CALENDAR DATES, not instants. `$at` is on the store's
           clock while a `date`-cast column materialises in the app timezone, so
           an instant comparison would end a 31 July season three hours early
           for a store east of the server. "Runs 1–31 July" means the store's
           own 1–31 July. */
        $today = $at->toDateString();
        if ($this->starts_on && $today < $this->starts_on->toDateString()) {
            return false;
        }
        if ($this->ends_on && $today > $this->ends_on->toDateString()) {
            return false;
        }

        if (! static::scopeCoversOrderType($this->order_types, $orderType)) {
            return false;
        }

        return static::windowCovers($this->days, $this->starts_at, $this->ends_at, $at);
    }

    /**
     * Apply this rule to a base unit price. Never returns a negative price and
     * never returns MORE than the base — a "schedule" that raised the price
     * would be a surcharge, which this feature deliberately does not sell.
     *
     * Rounding follows the STORE's currency, so a 3-decimal store keeps its fils
     * instead of being quietly rounded to two like a hardcoded round($v, 2).
     */
    public function apply(float $base): float
    {
        $value = self::typeIsRate($this->adjustment_type) ? (float) $this->adjustmentRate() : (float) $this->adjustmentAmount();

        $price = match ($this->adjustment_type) {
            'percent' => $base * (1 - ($value / 100)),
            'amount' => $base - $value,
            'fixed_price' => $value,
            default => $base,
        };

        $price = max(0.0, min($price, $base));

        return round($price, self::decimalsFor((int) $this->store_id));
    }

    /**
     * Store currency decimals, memoised per request. store_options() is an
     * uncached query, and apply() runs once per schedule per item — a 200-item
     * menu would otherwise fire hundreds of identical lookups on every render.
     */
    private static array $decimalsMemo = [];

    /** The application instance $decimalsMemo was filled for. */
    private static ?int $memoApp = null;

    /**
     * Bind the memo to the current application instance, as StoreOption does for
     * its option memo — a store id alone does not say which database it came
     * from, and RefreshDatabase reuses the same low ids across tests, so a
     * 3-decimal store's precision would follow its id into the next test.
     */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$decimalsMemo = [];
        }
    }

    private static function decimalsFor(int $storeId): int
    {
        if ($storeId <= 0) {
            // Unresolvable store ⇒ 2, the same floor the old StoreMoney cast used. Never
            // guess 3, and never hit the database for a store that is not one.
            return 2;
        }

        self::scopeMemo();

        return self::$decimalsMemo[$storeId] ??= money_decimals(
            store_options($storeId, 'currency_code') ?: null
        );
    }

    /** Testing seam — the memo is per-request in normal use. */
    public static function flushDecimalsCache(): void
    {
        self::$decimalsMemo = [];
    }

    /** The badge a diner sees. Falls back to the owner's internal name. */
    public function displayLabel(): string
    {
        /* Same customer-language mechanism as Menu::getNameAttribute(): the
           storefront language switch sets the Quick_user_lang_code cookie, and
           an owner-provided translation for that code wins over the base label.
           The base `label` (then `name`) stays the fallback, so an untouched
           schedule behaves exactly as before. */
        $language = $this->storefrontLanguage();
        $translation = $language === null ? null : ($this->label_translations->{$language} ?? null);
        if (! empty($translation) && ($translated = trim((string) $translation)) !== '') {
            return $translated;
        }

        return trim((string) ($this->label ?: $this->name)) ?: ___('Special price');
    }

    /** P6-24b: a schedule edit changes cached storefront card prices. */
    public function menuCacheRestaurantId(): ?int
    {
        return $this->store_id ? (int) $this->store_id : null;
    }
}
