<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use App\Services\WebhookFanout;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\NormalisesEmail;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Search\StoreSearchable;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Laravel\Scout\Attributes\SearchUsingFullText;

/**
 * P1-05: the lifetime-spend counter `total_spent` is stored as `total_spent_minor` in the row's `currency_code` (the
 * store's, stamped on first save). It keeps the store-precision string shape of the old StoreMoney cast. Query and
 * increment `total_spent_minor` (in the row currency) — the major name is not a column.
 *
 * @property string|float $total_spent
 * @property int $total_spent_minor
 * @property string|null $currency_code
 */
class Customer extends Authenticatable implements MinorMoneyModel
{
    use BelongsToAccount;

    // P6-40: emails stored lowercased
    use HasFactory, Notifiable;
    use HasMinorMoney;
    use HasUuids;
    use NormalisesEmail;
    use StoreSearchable; // P1-17: Scout database search (POS, owner list/export/palette, API v1)

    protected $fillable = [
        'store_id',
        'name',
        'email',
        'phone',
        'password',
        'oauth_provider',
        'oauth_uid',
        'avatar_url',
        'email_verified_at',
        'phone_verified_at',
        'marketing_consent',
        'sms_consent',
        'notifications_enabled',
        'consent_at',
        'birthday',
        'last_birthday_offer_sent_year',
        'source',
        'tags',
        'notes',
        'last_order_at',
        'orders_count',
        'total_spent',
        'preferences',
        'allergies',
        'system_tags',
        'referral_code',
        'referred_by_code',
        'pending_phone',
        'pending_email',
        'pending_contact_at',
    ];

    protected $hidden = [
        'password',
    ];

    /** @var array<string, string> */
    protected array $minorMoney = ['total_spent' => 'total_spent_minor,store'];

    protected $casts = [
        'store_id' => 'integer',
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'pending_contact_at' => 'datetime',
        'last_order_at' => 'datetime',
        'marketing_consent' => 'boolean',
        'sms_consent' => 'boolean',
        'notifications_enabled' => 'boolean',
        'consent_at' => 'datetime',
        'birthday' => 'date',
        'last_birthday_offer_sent_year' => 'integer',
        'tags' => 'array',
        'system_tags' => 'array',
        'orders_count' => 'integer',
    ];

    /**
     * P1-17: the searched fields — name, phone, email (what every customer search matched before Scout). Full-text
     * index customers_name_fulltext on the name (MySQL FULLTEXT, PostgreSQL GIN on to_tsvector('simple', name));
     * phone and email stay substring matches.
     *
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['name'], ['language' => 'simple'])]
    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'phone' => $this->phone, 'email' => $this->email];
    }

    /* HPMS E10: customer.created webhook. Best-effort + self-guarded — a webhook
       failure must never break the customer write, whichever path created it
       (guest checkout, dashboard, import). */
    protected static function booted(): void
    {
        static::created(function (Customer $customer) {
            WebhookFanout::forRestaurant((int) $customer->store_id, 'customer.created', [
                'customer_id' => $customer->id,
                'name' => $customer->name,
            ]);
        });
    }

    /**
     * HPMS E4: this customer's share code, minted lazily on first read and
     * persisted. Unique across customers; a referee arrives via `?ref=CODE`.
     */
    public function referralCode(): string
    {
        if (! empty($this->referral_code)) {
            return $this->referral_code;
        }
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        $this->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * M4: lifetime spend DERIVED from this customer's order history, using the one
     * authoritative money definition (Order::getTotalAttribute -> computeMoney).
     * The stored `total_spent` column is a running counter maintained at checkout;
     * this is the answer it is supposed to equal, and the dashboard shows both so a
     * merchant can see drift instead of trusting a silently-wrong number.
     *
     * Deliberately NOT `SUM(grand_total)`: that snapshot column shipped nullable
     * with no backfill (2026_07_28_120100_add_total_snapshot_columns_to_orders.php),
     * so a SQL sum reads 0 for every order predating it. Chunked so a heavy customer
     * cannot blow the profile page's memory.
     *
     * Deliberately NOT status-filtered: the running counter has never excluded
     * cancelled orders, so excluding them here would manufacture drift on every
     * store. Whether cancelled orders should count toward LTV is an open product
     * question (see WI-H10, which owns the revenue-definition split).
     */
    public function derivedLifetimeSpend(): float
    {
        $sum = 0.0;
        Order::where('customer_id', $this->id)
            ->with('items')
            ->chunkById(200, function ($orders) use (&$sum) {
                foreach ($orders as $order) {
                    $sum += (float) $order->total;
                }
            });

        return round($sum, currency_decimals(store_options($this->store_id, 'currency_code') ?: null));
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * F2 · Follow / subscribe-to-brand. The follow edges this customer owns.
     */
    public function follows()
    {
        return $this->hasMany(StoreFollow::class, 'customer_id');
    }

    /**
     * F2 · Restaurants this customer follows (reachable as a "Following" list from
     * the customer account).
     */
    public function followedRestaurants()
    {
        return $this->belongsToMany(Store::class, 'store_follows', 'customer_id', 'store_id')
            ->withTimestamps();
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
}
