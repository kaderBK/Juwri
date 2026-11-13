<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use App\Models\Concerns\BustsMenuCache;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ScopesToOwner;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\RequestEpoch;

/**
 * @property string|null $public_id UUIDv7 public id (J25-1)
 * @property Collection<int, BranchHourException> $hourExceptions P6-291 holidays / special hours
 * @property string|null $plan_suspended_at P6-220: set when the plan cap switched the branch off
 * @property int|null $prep_time_minutes P5-31: null = the store's prep time
 * @property float|null $delivery_fee P5-31: major amount; null = the store's delivery fee
 * @property int|null $delivery_fee_minor
 * @property float|null $min_order P5-31: major amount; null = the store's minimum order
 * @property int|null $min_order_minor
 * @property string|null $currency_code the currency the branch's amounts are stored in (its store's)
 */
class Branch extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use BustsMenuCache;

    // P5
    use HasFactory; // S13
    use HasMinorMoney {
        settleMinorMoney as protected settleMinorMoneyNow;
    }
    use HasUuids;
    use ScopesToOwner; // P5-31

    protected $guarded = [];

    /**
     * P5-31: optional overrides of the store's delivery fee / minimum order (minor units of `currency_code`).
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['delivery_fee' => 'delivery_fee_minor', 'min_order' => 'min_order_minor'];

    /**
     * P5-31: a branch without any money override needs no currency. Skipping the store-currency lookup then keeps
     * every plain branch save (store creation, rename, hours) from reading — and memoising — the store's currency
     * before its settings are written, which priced later quotes / shipping zones in the platform currency.
     */
    public function settleMinorMoney(): void
    {
        if ($this->minorMoneyPending === [] && (string) ($this->attributes['currency_code'] ?? '') === '') {
            $hasMoney = false;
            foreach ($this->minorMoneyMap() as $major => $minor) {
                if (array_key_exists($major, $this->attributes) || ($this->attributes[$minor] ?? null) !== null) {
                    $hasMoney = true;
                    break;
                }
            }
            if (! $hasMoney) {
                return;
            }
        }
        $this->settleMinorMoneyNow();
    }

    /** P1-04: a branch carries its store's account_id (set on create and when it moves store). */
    protected static function booted(): void
    {
        static::saving(function (self $branch) {
            if (($branch->isDirty('store_id') || ! $branch->exists) && $branch->store_id) {
                $branch->account_id = Store::whereKey($branch->store_id)->value('account_id');
            }
        });
        // Edition limits (owner 2026-10-05): a new branch, or one moving to another store, must fit the edition's
        // branch limits (StoreLimitGuard); editing a branch never is.
        static::creating(fn (self $branch) => StoreLimitGuard::guardBranch($branch));
        static::updating(fn (self $branch) => StoreLimitGuard::guardBranch($branch));
        // p5r: a branch write can change its store's primary timezone (store_tz level 2).
        static::saved(fn () => self::$primaryTz = []);
        static::deleted(fn () => self::$primaryTz = []);
    }

    /** @var array<int, string|null> store id => first active branch's timezone, per unit of work */
    private static array $primaryTz = [];

    private static ?int $primaryTzApp = null;

    /**
     * P6-289 (store_tz level 2): the timezone of the store's first active branch (position, id) that has one; null when
     * none. Memoised per request (store_tz runs on every storefront hot path), cleared when any branch is written.
     */
    public static function primaryTimezone(int $storeId): ?string
    {
        $id = RequestEpoch::id();
        if (self::$primaryTzApp !== $id) {
            self::$primaryTzApp = $id;
            self::$primaryTz = [];
        }
        if (! array_key_exists($storeId, self::$primaryTz)) {
            $tz = self::query()->where('store_id', $storeId)->where('active', true)
                ->whereNotNull('timezone')->where('timezone', '!=', '')
                ->orderBy('position')->orderBy('id')->value('timezone');
            self::$primaryTz[$storeId] = is_string($tz) && $tz !== '' ? $tz : null;
        }

        return self::$primaryTz[$storeId];
    }

    /** S13: Branch belongs to its tenant via store_id. */
    protected string $tenantForeignKey = 'store_id';

    /** P5: this branch's store. */
    public function menuCacheRestaurantId(): ?int
    {
        return $this->store_id ? (int) $this->store_id : null;
    }

    protected $casts = [
        'hours' => 'array',
        'pos_settings' => 'array', // P1: auto_print/copies/station_name/autolock_minutes
        'order_types' => 'array',  // D6: per-branch order-type overrides
        'active' => 'boolean',
        'accepting_orders' => 'boolean',
        'position' => 'integer',
        'latitude' => 'decimal:7', // F4: nearest-branch map coords
        'longitude' => 'decimal:7',
        'prep_time_minutes' => 'integer',
    ];

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** D6: the order types a branch can override (storefront chip keys). */
    public const ORDER_TYPES = ['on-table', 'takeaway', 'delivery'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'branch_id');
    }

    public function staff()
    {
        return $this->hasMany(StaffMember::class, 'branch_id');
    }

    /** P6-291: per-date hour exceptions (holidays, special hours). */
    /** @return HasMany<BranchHourException, $this> */
    public function hourExceptions(): HasMany
    {
        return $this->hasMany(BranchHourException::class, 'branch_id');
    }

    /** Hours for a given day key, normalised. */
    public function dayHours(string $day): array
    {
        $h = $this->hours[$day] ?? null;

        return [
            'open' => $h['open'] ?? '09:00',
            'close' => $h['close'] ?? '22:00',
            'closed' => (bool) ($h['closed'] ?? false),
        ];
    }

    /** Is the branch currently open per its hours + timezone? */
    public function isOpenNow(): bool
    {
        return $this->isOpenAt(Carbon::now());
    }

    /**
     * P6-290 / P6-291: is the branch open at $at (UTC or any tz)? The same logic
     * as isOpenNow(), evaluated at $at converted to the branch's own timezone
     * (falls back to the app timezone on an invalid branch tz). An inactive
     * branch is never open.
     *
     * EXCEPTION RULES (one query per branch per request — use the preloaded
     * `hourExceptions` relation when available, else `whereDate in [date-1,
     * date]` to cover both today and the yesterday-overnight bleed):
     *   - closed exception for today's local date → not open. The
     *     yesterday-overnight bleed is also suppressed only when yesterday has
     *     its own CLOSED exception (otherwise it stays, mirroring the weekly
     *     behaviour).
     *   - special-hours exception (closed=false, open/close set) → use those
     *     hours instead of the weekly entry (overnight allowed: close < open).
     *   - an exception applies even when the weekly hours are empty: a closed
     *     exception closes a no-hours branch; special hours open it only within
     *     them.
     */
    public function isOpenAt(Carbon $at): bool
    {
        if (! $this->active) {
            return false;
        }

        $tz = $this->timezone ?: config('app.timezone', 'UTC');
        try {
            $local = $at->copy()->setTimezone($tz);
        } catch (\Throwable $e) {
            $local = $at->copy()->setTimezone(config('app.timezone', 'UTC'));
        }

        $today = $local->format('Y-m-d');
        $yesterday = $local->copy()->subDay()->format('Y-m-d');
        $cur = $local->format('H:i');

        $exc = $this->exceptionsFor([$yesterday, $today]);
        $todayExc = $exc[$today] ?? null;
        $yesterdayExc = $exc[$yesterday] ?? null;

        // The yesterday-overnight bleed is checked first — using yesterday's
        // special-hours window when there is one, otherwise the weekly entry.
        // A CLOSED exception on yesterday suppresses the bleed (a holiday
        // yesterday would otherwise silently keep the store open at 01:00
        // today). Without a yesterday-exception, the weekly overnight rule
        // applies normally.
        if ($yesterdayExc !== null) {
            if (! $yesterdayExc->closed) {
                [$openY, $closeY] = $this->exceptionWindow($yesterdayExc);
                if ($closeY !== null && $openY !== null && $closeY < $openY && $cur <= $closeY) {
                    return true;
                }
            }
        } else {
            $yh = $this->dayHours(self::DAYS[($local->dayOfWeekIso - 1 + 6) % 7]);
            if (! $yh['closed'] && $yh['close'] < $yh['open'] && $cur <= $yh['close']) {
                return true;
            }
        }

        // Today's exception: closed → not open; special hours → use them.
        // A closed exception does NOT by itself suppress the yesterday bleed
        // (the bleed check above already ran); the bleed is only suppressed
        // when YESTERDAY has its own closed exception, per spec.
        if ($todayExc !== null) {
            if ($todayExc->closed) {
                return false;
            }
            [$openT, $closeT] = $this->exceptionWindow($todayExc);
            if ($openT === null || $closeT === null) {
                // Special-hours row with no usable times = closed for the day.
                return false;
            }

            if ($closeT < $openT) {
                return $cur >= $openT || $cur <= $closeT;
            }

            return $cur >= $openT && $cur <= $closeT;
        }

        // No today-exception: weekly window. No weekly hours → always open
        // (matches pre-existing Branch::isOpenNow semantics).
        if (empty($this->hours)) {
            return true;
        }

        $day = self::DAYS[$local->dayOfWeekIso - 1]; // 1=Mon
        $h = $this->dayHours($day);
        if ($h['closed']) {
            return false;
        }

        if ($h['close'] < $h['open']) {
            return $cur >= $h['open'] || $cur <= $h['close'];
        }

        return $cur >= $h['open'] && $cur <= $h['close'];
    }

    /**
     * Resolve the exception rows for the given local dates (Y-m-d). Uses the
     * preloaded `hourExceptions` relation when available — otherwise runs ONE
     * `whereDate in (...)` query for the two dates. Returns a [date => row]
     * map (only dates with a row are present).
     *
     * @param  list<string>  $dates
     * @return array<string, BranchHourException>
     */
    private function exceptionsFor(array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        if ($this->relationLoaded('hourExceptions')) {
            $out = [];
            foreach ($this->hourExceptions as $row) {
                $d = $row->date->format('Y-m-d');
                if (in_array($d, $dates, true)) {
                    $out[$d] = $row;
                }
            }

            return $out;
        }

        $rows = $this->hourExceptions()->whereIn('date', $dates)->get();
        $out = [];
        /** @var BranchHourException $row */
        foreach ($rows as $row) {
            $d = $row->date->format('Y-m-d');
            $out[$d] = $row;
        }

        return $out;
    }

    /**
     * Normalise a special-hours exception row to [open, close]; null entries
     * mean the row has no usable hours and the day should be treated as closed.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function exceptionWindow(BranchHourException $row): array
    {
        $open = $row->open;
        $close = $row->close;
        if (! is_string($open) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $open)) {
            $open = null;
        }
        if (! is_string($close) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $close)) {
            $close = null;
        }

        return [$open, $close];
    }

    /** Can this branch take an order right now? */
    public function canAcceptOrders(): bool
    {
        return $this->active && $this->accepting_orders && $this->isOpenNow();
    }

    /**
     * D6 — this branch's override for ONE order type: true (force on), false (force
     * off), or null (inherit the store flag). A key absent from the JSON map, or a
     * null column, is always "inherit".
     */
    public function orderTypeOverride(string $type): ?bool
    {
        $map = $this->order_types;
        if (! is_array($map) || ! array_key_exists($type, $map) || $map[$type] === null) {
            return null;
        }

        return (bool) $map[$type];
    }

    /**
     * D6 — is $type enabled at this branch, given the store's own flag? The branch
     * override wins when set; otherwise the store default is inherited. This is the
     * one resolver the storefront/POS consult — a null branch always falls back to
     * the store flag, so pre-override behaviour is preserved.
     */
    public function orderTypeAllowed(string $type, bool $storeDefault): bool
    {
        $override = $this->orderTypeOverride($type);

        return $override === null ? $storeDefault : $override;
    }

    /**
     * D6 — the effective on-table / takeaway / delivery flags for this branch,
     * given the store-level flags.
     *
     * @param  array<string, bool>  $storeFlags  keyed by ORDER_TYPES
     * @return array<string, bool>
     */
    public function effectiveOrderTypes(array $storeFlags): array
    {
        $out = [];
        foreach (self::ORDER_TYPES as $type) {
            $out[$type] = $this->orderTypeAllowed($type, (bool) ($storeFlags[$type] ?? false));
        }

        return $out;
    }

    public function statusLabel(): string
    {
        if (! $this->active) {
            return 'inactive';
        }
        if (! $this->accepting_orders) {
            return 'paused';
        }

        return $this->isOpenNow() ? 'open' : 'closed';
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
     * P5-31: the branch of $storeId with id $branchId, or null (no branch, or a branch of another store — a branch's
     * overrides never apply to another store's order).
     */
    public static function ofStore(int $storeId, int|string|null $branchId): ?self
    {
        if ($storeId <= 0 || $branchId === null || (int) $branchId <= 0) {
            return null;
        }

        return self::query()->where('store_id', $storeId)->whereKey((int) $branchId)->first();
    }

    /** P5-31: this branch's own delivery fee (major units, store currency), null = the store's. */
    public function deliveryFeeOverride(): ?float
    {
        return $this->getAttribute('delivery_fee_minor') === null ? null : (float) $this->delivery_fee;
    }

    /** P5-31: this branch's own minimum order (major units, store currency), null = the store's. */
    public function minOrderOverride(): ?float
    {
        return $this->getAttribute('min_order_minor') === null ? null : (float) $this->min_order;
    }

    /** P5-31: this branch's own prep time in minutes, null = the store's. */
    public function prepMinutesOverride(): ?int
    {
        $v = $this->getAttribute('prep_time_minutes');

        return $v === null ? null : max(0, (int) $v);
    }

    /**
     * P5-31: the delivery fee a delivery order of this store/branch charges before any quoted fee — the branch's own
     * fee when it has one, else the store's `restaurant_delivery_charge` (major units). The store's "free delivery over
     * X" is applied by Order::deliveryCharge (P5-34: it waives a branch's own fee too unless the store's
     * `restaurant_free_delivery_covers_branch_fee` is off).
     */
    public static function effectiveDeliveryFee(int $storeId, ?self $branch, ?object $postOptions = null): float
    {
        $own = $branch !== null && (int) $branch->store_id === $storeId ? $branch->deliveryFeeOverride() : null;
        if ($own !== null) {
            return $own;
        }
        $fee = $postOptions !== null ? ($postOptions->restaurant_delivery_charge ?? 0) : store_options($storeId, 'restaurant_delivery_charge');

        return is_numeric($fee) ? (float) $fee : 0.0;
    }

    /** P5-31: the minimum order (items subtotal, major units) of this store/branch; null = none. */
    public static function effectiveMinOrder(int $storeId, ?self $branch): ?float
    {
        $own = $branch !== null && (int) $branch->store_id === $storeId ? $branch->minOrderOverride() : null;
        $min = $own ?? StoreSettings::get($storeId, 'min_order_value');

        return is_numeric($min) && (float) $min > 0 ? (float) $min : null;
    }
}
