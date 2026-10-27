<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use App\Models\Customer;
use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS A2 — a hotel reservation. Mews-style lifecycle:
 *   pending → confirmed → checked_in → checked_out
 * with terminal branches cancelled (any pre-check-in state) and no_show
 * (confirmed, past arrival + grace). The row lives forever; on check-in it links
 * to its `hotel_stays` row via hotel_stay_id and the folio takes over the money.
 *
 * `code` is the public A-Z0-9 reference; `token` is the guest manage-link bearer
 * credential (kept out of serialisation, like HotelStay.token). Store-scoped by
 * store_id.
 *
 * P1-05: `quoted_total` is stored as `quoted_total_minor` in the row's `currency` (the quote's — pinned with it;
 * never re-interpreted). It keeps the number shape of the old `float` cast. Query with `quoted_total_minor`.
 *
 * @property float|null $quoted_total major amount
 * @property int|null $quoted_total_minor
 * @property string|null $currency
 */
class HotelBooking extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $table = 'hotel_bookings';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN,
        self::STATUS_CHECKED_OUT, self::STATUS_CANCELLED, self::STATUS_NO_SHOW,
    ];

    /** Statuses that hold a room (block availability + own night rows). */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN,
    ];

    public const SOURCE_DESK = 'desk';

    public const SOURCE_REQUEST = 'request';

    public const SOURCE_INSTANT = 'instant';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['quoted_total' => 'quoted_total_minor'];

    protected ?string $minorMoneyCurrencyColumn = 'currency';

    /** The guest manage-link token is a bearer credential — never serialise it. */
    protected $hidden = ['token'];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'int',
        'children' => 'int',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /**
     * Generate a unique public booking code (A-Z0-9, 8 chars in a 12-char column).
     * Ambiguous 0/O/1/I dropped so the code reads cleanly off a confirmation.
     */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Mint a guest manage-link token (48 hex-ish chars). */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** Number of nights (check_out exclusive; back-to-back stays are legal). */
    public function nightsCount(): int
    {
        $in = $this->getAttribute('check_in');
        $out = $this->getAttribute('check_out');
        if (! $in || ! $out) {
            return 0;
        }

        return (int) max(0, Carbon::parse($in)->diffInDays(Carbon::parse($out)));
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<HotelBooking>  $q
     * @return \Illuminate\Database\Eloquent\Builder<HotelBooking>
     */
    public function scopeForRestaurant($q, int $storeId)
    {
        return $q->where('store_id', $storeId);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'rate_plan_id');
    }

    /**
     * The assigned room (a store_tables row); null until assigned.
     *
     * @return BelongsTo<StoreTable, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(StoreTable::class, 'room_id');
    }

    /** @return BelongsTo<HotelStay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(HotelStay::class, 'hotel_stay_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return HasMany<HotelBookingNight, $this> */
    public function nights(): HasMany
    {
        return $this->hasMany(HotelBookingNight::class, 'booking_id');
    }
}
