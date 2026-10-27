<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Broadcasting\LiveUpdates;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ScopesToOwner;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\ExchangeRateService;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Kitchen\Services\OrderBoard\DailyTokenService;
use Juwri\Modules\Kitchen\Services\OrderBoard\OrderBoardService;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;
use Juwri\Modules\Ordering\Services\CustomerPushService;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Juwri\Modules\Pos\Services\Printing\PaidOrderPrint;
use Juwri\Modules\Tables\Services\TableMergeService;
use Juwri\Modules\Tables\Services\TableSessionService;

/**
 * P1-05 money (*_minor in the order's `currency_code`; major attributes via HasMinorMoney):
 *
 * @property float|null $subtotal
 * @property float|null $tax_total
 * @property float|null $grand_total
 * @property float|null $discount_amount
 * @property float|null $mk_wallet_applied
 * @property string|float|null $tip_amount
 * @property string|float|null $donation_amount
 * @property string|float|null $delivery_fee bug batch 4 · 3: quoted delivery fee (NULL = the store's delivery charge)
 * @property string|float|null $channel_fee
 * @property string|float|null $amount_tendered
 * @property string|float|null $change_due
 * @property string|float|null $service_charge_amount
 * @property string|float|null $wallet_applied
 * @property string|float|null $gift_card_applied
 * @property int|null $subtotal_minor
 * @property int|null $tax_total_minor
 * @property int|null $grand_total_minor
 * @property int $discount_amount_minor
 * @property int $wallet_applied_minor
 * @property int $mk_wallet_applied_minor
 * @property int $gift_card_applied_minor
 * @property int|null $delivery_fee_minor
 * @property int|null $shipping_method_id P3-08: the shipping method of a ship order (its fee is delivery_fee)
 * @property Carbon|null $shipping_eta_from P3-08: earliest delivery date (store time)
 * @property Carbon|null $shipping_eta_to P3-08: latest delivery date (store time)
 * @property int $tip_amount_minor
 * @property int $channel_fee_minor
 * @property int|null $amount_tendered_minor
 * @property int|null $change_due_minor
 * @property int|null $service_charge_amount_minor
 * @property int $donation_amount_minor
 * @property string|null $currency_code
 * @property string|null $public_id UUIDv7 public id (J25-1)
 */
class Order extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;
    use HasUuids;
    use ScopesToOwner; // S13 (tenant via store_id)

    public $timestamps = false;

    /**
     * PARITY-RUN T3: the pre-KOT holding status for `staff_approve` dine-in
     * verification. Deliberately a NEW value on the existing `orders.status`
     * string column rather than a fork of the order path — the order is created
     * exactly as it always was, it just does not reach the kitchen until a human
     * approves it. Every live staff queue filters it out through
     * {@see scopeLive()}, and KdsService::syncOrderStatus() refuses to derive a
     * status for an order still sitting here.
     */
    // R1a: BC alias of the typed enum value (do NOT add an Eloquent $casts['status'] —
    // legacy stores may hold out-of-enum strings that a hard cast would throw on).
    public const STATUS_PENDING_APPROVAL = OrderStatus::PendingApproval->value;

    /** R1a: tolerant typed read of the status (null for any out-of-enum legacy string). */
    public function statusEnum(): ?OrderStatus
    {
        return OrderStatus::tryFrom((string) $this->status);
    }

    /**
     * Whether a diner may request a refund on this order right now, and if not,
     * a machine-readable reason. A request needs: a real customer account, a paid
     * and non-cancelled order, no request already in flight or handled, and the
     * order inside the store's refund window (0/absent window = no limit).
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public function customerRefundEligibility(int $windowDays): array
    {
        if (! $this->customer_id) {
            return ['eligible' => false, 'reason' => 'no_account'];
        }
        if (! $this->is_paid) {
            return ['eligible' => false, 'reason' => 'not_paid'];
        }
        if ($this->cancelled_at) {
            return ['eligible' => false, 'reason' => 'cancelled'];
        }
        if ($this->refund_request_status) {
            return ['eligible' => false, 'reason' => $this->refund_request_status];
        }
        if ($windowDays > 0 && $this->created_at && $this->created_at->lt(now()->subDays($windowDays))) {
            return ['eligible' => false, 'reason' => 'window_expired'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = [];

    /**
     * P1-05 (J25-4): order money is stored as `*_minor` integers in the order's own `currency_code` (stamped at
     * creation, never re-interpreted — see minorMoneyFollowsStoreCurrency()). The old attribute names stay
     * readable/writable in their old shape: the former StoreMoney fields as a store-precision string ('3.00',
     * '0.275'), the former uncast columns (discount_amount, mk_wallet_applied, subtotal/tax_total/grand_total) as a
     * number. SQL must use the `*_minor` columns — for sums see {@see HasMinorMoney::minorMoneySql()}.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = [
        'subtotal' => 'subtotal_minor',
        'tax_total' => 'tax_total_minor',
        'grand_total' => 'grand_total_minor',
        'discount_amount' => 'discount_amount_minor',
        'mk_wallet_applied' => 'mk_wallet_applied_minor',
        'tip_amount' => 'tip_amount_minor,store',
        'donation_amount' => 'donation_amount_minor,store', // T3-3 marketplace round-up donation
        'delivery_fee' => 'delivery_fee_minor,store', // bug batch 4 · 3: quoted delivery fee (NULL = store setting)
        'channel_fee' => 'channel_fee_minor,store',
        'amount_tendered' => 'amount_tendered_minor,store',
        'service_charge_amount' => 'service_charge_amount_minor,store',
        'wallet_applied' => 'wallet_applied_minor,store',
        'gift_card_applied' => 'gift_card_applied_minor,store',
        'change_due' => 'change_due_minor,store',
    ];

    /** An order's currency is a fact of the sale: a later store-currency change never moves its amounts. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'cart_recovery_checked_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'fire_at' => 'datetime',
        'released_at' => 'datetime',
        'is_curbside' => 'boolean',
        'curbside_arrived_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refund_requested_at' => 'datetime',
        // P3-06b: hosted payment window + late payments (headless storefront API)
        'payment_expires_at' => 'datetime',
        'shipping_eta_from' => 'date', // P3-08
        'shipping_eta_to' => 'date',
        'late_payment_at' => 'datetime',
        'authorisation_expires_at' => 'datetime',
        // P3-09: grocery slot, card hold of a weighed basket, picking
        'slot_starts_at' => 'datetime',
        'slot_ends_at' => 'datetime',
        'express' => 'boolean',
        'hold_minor' => 'integer',
        'captured_minor' => 'integer',
        'captured_at' => 'datetime',
        'picked_at' => 'datetime',
    ];

    /* T1-5 shopper push: single shared choke point for EVERY status-write path
       (owner dashboard, staff PWA/API, DeliveryService::setStatus, aggregators)
       — all of them save() through Eloquent, so one updated hook covers them.
       Guarded + try/caught so it can never break an order transition. */
    protected static function booted(): void
    {
        /* MC-10: snapshot the store's currency onto the order at creation so its
           totals round to that currency forever, even if the store later changes
           currency. Only fills a blank — an explicit currency (e.g. a marketplace
           bag already priced in a chosen currency) is respected. This is the one
           choke point every creation path (storefront, OrderApiService, aggregator
           ingestor) passes through. */
        static::creating(function (Order $order) {
            if (empty($order->currency_code) && $order->store_id) {
                $order->currency_code = store_options($order->store_id, 'currency_code') ?: null;
            }

            /* C9: freeze the currency→platform-base display-FX rate AT CREATION so
               cross-currency reporting sums each order at the rate that was true
               when it was placed. Null-safe by design: ExchangeRateService::rate()
               returns null when the daily feed doesn't cover the pair (or is empty),
               and null stays null — legacy behaviour (read-time live fallback), never
               a throw on the creation path. Only fills a blank; a same-currency order
               stamps 1.0. */
            if ($order->fx_rate_base === null && ! empty($order->currency_code)) {
                try {
                    // The PLATFORM base, never the dashboard's working-store display swap.
                    $base = platform_currency();
                    $order->fx_rate_base = ExchangeRateService::rate((string) $order->currency_code, $base);
                } catch (\Throwable $e) {
                    // best-effort only — a missing/errored feed leaves it null (legacy)
                }
            }
        });

        /* PARITY-RUN T2: hand the order its daily token number. Placed on the
           model rather than in one controller because orders are created from
           the storefront checkout, OrderApiService (POS/kiosk/agent/API) and the
           aggregator ingestor — this is the one place all three pass through.
           A store with the board off gets no number and no extra work. */
        static::created(function (Order $order) {
            try {
                DailyTokenService::assign($order);
            } catch (\Throwable $e) {
                // best-effort only — a counter must never block an order
            }
            // Pricing build: every creation path passes here — the billing edition counts the Free plan's monthly
            // orders (after commit, best-effort; empty slot without it).
            Hooks::run('order.created', $order);
            // Live updates (Reverb; no-op while off): ids + status only, to the orders / KDS / POS screens of this store
            // and of the order's branch.
            LiveUpdates::orderChanged((int) $order->id, (int) $order->store_id, (string) $order->status, true, $order->branch_id ? (int) $order->branch_id : null);
        });

        static::updated(function (Order $order) {
            if ($order->wasChanged('status') || $order->wasChanged('delivery_status')) {
                LiveUpdates::orderChanged((int) $order->id, (int) $order->store_id, (string) $order->status, false, $order->branch_id ? (int) $order->branch_id : null);
                // Marketplace shopper push (ee; P1-13 hook slot, best-effort).
                Hooks::run('order.status-changed.shopper', $order);
                /* P1: guest customer Web Push (closed-tab "order ready"),
                   order-scoped by track token. Same choke point, same guarded
                   best-effort shape — a push must never fail a transition, and
                   it no-ops gracefully when VAPID keys are unset. */
                try {
                    CustomerPushService::orderStatusChanged($order);
                } catch (\Throwable $e) {
                    // best-effort only
                }
                /* T6-5: WhatsApp lifecycle for the delivery legs (kitchen
                   status stays on the owner-dashboard path — no doubles). */
                Hooks::run('order.status-changed.delivery-whatsapp', $order); // Marketplace (ee; P1-13 hook slot)
                /* PARITY-RUN T2: public order board — late token numbering plus
                   the preparing_at/ready_at stamps the Ready column recycles on.
                   Additive and quiet (saveQuietly), so it can neither change an
                   order's flow nor re-enter this hook. */
                try {
                    OrderBoardService::statusChanged($order);
                } catch (\Throwable $e) {
                    // best-effort only
                }
                /* PARITY-RUN T7: tell the aggregator that owns this order. The
                   AggregatorProvider::pushStatus() seam has existed since the T3
                   hub but NOTHING ever called it, so a kitchen marking an
                   aggregator order ready told the partner nothing. Same guarded
                   shape as its neighbours — a partner outage must never be able
                   to fail an order transition. */
                Hooks::run('order.status-changed.channels', $order); // Channels aggregators (P1-13 hook slot)

                /* PARITY-RUN T9: native mobile-app push. Same choke point, same
                   best-effort shape — a notification must never be able to fail
                   an order transition, and the service itself dedupes on
                   order+status so a replayed save cannot double-notify. */
                try {
                    MobilePushService::orderStatusChanged($order);
                } catch (\Throwable $e) {
                    // best-effort only — push must never break a transition
                }
            }

            /* PARITY-RUN T1: auto-print on settle. `is_paid` is written through
               Eloquent by every settle path (PosService::settle, gateway
               callbacks, staff app), so this hook covers them all without
               forking an order path. The dispatcher is idempotent on job_key, so
               a status replay that re-saves a paid order cannot double-print. */
            if ($order->wasChanged('is_paid') && $order->is_paid) {
                try {
                    PaidOrderPrint::paid($order); // J25-7: auto-print via the outbox, after commit
                } catch (\Throwable $e) {
                    // best-effort only — a printer must never fail a payment
                }
                /* PARITY-RUN T4: auto-release a merged party once the bill on
                   its primary table is fully settled. Same choke point, same
                   best-effort shape — the tables going back to normal must
                   never be able to fail a payment. */
                try {
                    TableMergeService::onOrderSettled($order);
                } catch (\Throwable $e) {
                    // best-effort only
                }
            }

            /* P6-270: a dine-in bill settled or closed ends the table's QR
               sessions once nothing else is open there, so a guest who left
               cannot keep ordering onto the next party's table. */
            if (($order->wasChanged('is_paid') && $order->is_paid)
                || ($order->wasChanged('status') && in_array((string) $order->status, ['completed', 'cancelled'], true))) {
                try {
                    TableSessionService::onTableOrderClosed($order);
                } catch (\Throwable $e) {
                    // best-effort only
                }
            }

            /* R1c: keep the total snapshot fresh when an order-level money field
               changes after creation (a POS discount, an added tip, a service
               charge). Item edits re-save the order through these same paths, and
               a change with no snapshotted row yet is a no-op (snapshotTotals bails
               until the lines exist). saveQuietly here can't re-enter this hook. */
            if ($order->wasChanged(['discount_amount_minor', 'tip_amount_minor', 'donation_amount_minor', 'service_charge_amount_minor', 'type'])) {
                try {
                    $order->snapshotTotals();
                } catch (\Throwable $e) {
                    // best-effort only — a snapshot must never fail a save
                }
            }
        });
    }

    /**
     * T1: is this a scheduled order that has NOT yet been released to the kitchen?
     * Such orders are hidden from every live staff queue until released.
     */
    public function isPendingSchedule(): bool
    {
        return $this->scheduled_at !== null && $this->released_at === null;
    }

    /** T3: is this order parked waiting for a staff approve/reject decision? */
    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * T1: limit a query to orders that should appear in LIVE staff queues —
     * ASAP orders (no scheduled_at) plus scheduled orders already released.
     * Unreleased future-scheduled orders are excluded.
     *
     * PARITY-RUN T3 adds the second clause: an order held for staff approval is
     * not live either. This is the one central place every kitchen/floor query
     * already funnels through (KDS poll + all-day, staff PWA feed and KDS
     * tickets, signage, POS floor), so the new status stays out of all of them
     * without eight separate edits. Written NULL-tolerant on purpose — plain
     * `!=` would silently drop rows whose status is NULL.
     */
    public function scopeLive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('scheduled_at')->orWhereNotNull('released_at');
        })->where(function ($q) {
            $q->whereNull('status')->orWhere('status', '!=', self::STATUS_PENDING_APPROVAL);
        });
    }

    /**
     * H10: THE definition of "an order that counts as revenue", for SQL paths.
     *
     * Adopted verbatim from AnalyticsController's long-standing inline filter
     * (`$o->status === 'completed' || $o->is_paid`) so this change moves ZERO
     * numbers on the Analytics screen. Deliberately NOT "exclude cancelled":
     * a cancelled-but-paid order still counts here, exactly as it always has on
     * Analytics. Narrowing that is a product decision, not part of H10.
     *
     * Plain where() (not the NULL-tolerant style of scopeLive) is correct here:
     * a NULL status / NULL is_paid must be FALSY, which is what the PHP filter
     * this replaces already did.
     *
     * P6-296: also excludes `pending_approval` — staff has not yet approved
     * the order, so it has not joined the revenue stream even when is_paid=1.
     * This keeps `todayOrderCount` and `todaySales` in agreement (both via
     * `scopeCountedToday()` on the dashboard).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeRevenueCounted($query)
    {
        return $query->where(function ($q) {
            $q->where('status', OrderStatus::Completed->value)->orWhere('is_paid', 1);
        })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', self::STATUS_PENDING_APPROVAL);
            });
    }

    /**
     * P6-296: THE "counted today" predicate for the owner dashboard. It is
     * revenue-counted (same status × is_paid filter as scopeRevenueCounted,
     * so "Today's orders" never disagrees with "Today's sales" beside it)
     * MINUS pending_approval: an order parked for staff approval has not
     * reached the kitchen and lights the attention badge instead — it must
     * not count as a settled "today" order even when the diner has paid.
     *
     * Excluded today: cancelled-but-unpaid orders (no money changed hands),
     * pending_approval orders (they light the attention badge instead), and
     * 'unpaid' hosted-checkout orders (not yet in the kitchen).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeCountedToday($query)
    {
        return $query->revenueCounted()
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', self::STATUS_PENDING_APPROVAL);
            });
    }

    /**
     * H10: the collection-side twin of scopeRevenueCounted(). These two MUST
     * stay identical — OrderRevenueDefinitionTest pins that they agree.
     *
     * P6-296: pending_approval is also excluded here so the dashboard's
     * `todayOrderCount` (scopeCountedToday()) matches the per-order PHP
     * filter used in legacy fallbacks and report bodies.
     */
    public function countsAsRevenue(): bool
    {
        if ($this->status === self::STATUS_PENDING_APPROVAL) {
            return false;
        }

        return $this->status === OrderStatus::Completed->value || (bool) $this->is_paid;
    }

    /**
     * H10: the ONE revenue sum for every reporting surface (dashboard KPI +
     * fleet strip, AnalyticsCopilot grounding, reseller Fusion GMV).
     *
     * Sums the SNAPSHOT column, never the accessor. Returns an UNROUNDED float
     * on purpose — every call site keeps its own existing round(..., 2).
     * Rows whose grand_total is still NULL contribute 0, which is why
     * unsnapshottedCount() exists and why the dashboard surfaces it.
     *
     * @param  Builder<Order>  $query
     */
    public static function revenueSum($query): float
    {
        return (float) (clone $query)->revenueCounted()->sum(DB::raw(static::minorMoneySql('grand_total')));
    }

    /**
     * MC-40: revenue split by the order's stamped currency — the same
     * revenue-counted, snapshot-column sum as revenueSum(), but GROUPED so a
     * multi-currency fleet is never blind-summed across currencies. Returns
     * {CODE => sum} (a NULL stamp — legacy unstamped orders — keys under the
     * platform base). A single-currency owner gets exactly one entry whose value
     * equals revenueSum(), so the reporting surfaces stay byte-identical.
     *
     * @param  Builder<Order>  $query
     * @return array<string, float>
     */
    public static function revenueSumByCurrency($query): array
    {
        $base = platform_currency(); // the platform base, not a display swap
        $rows = (clone $query)->revenueCounted()
            ->selectRaw('currency_code, SUM('.static::minorMoneySql('grand_total').') as s')
            ->groupBy('currency_code')
            ->pluck('s', 'currency_code');

        $out = [];
        foreach ($rows as $code => $sum) {
            $code = $code ? strtoupper((string) $code) : $base;
            $out[$code] = ($out[$code] ?? 0) + (float) $sum;
        }

        return $out;
    }

    /**
     * C9: revenue-counted order value CONVERTED to the platform base currency, as
     * a single figure. Prefers each order's stamped `fx_rate_base` (the display-FX
     * rate that was true when the order was placed) via SUM(grand_total *
     * fx_rate_base). Rows with a NULL stamp — legacy orders, or orders placed while
     * the FX feed was empty — are grouped by currency and converted at TODAY's live
     * rate; base/NULL-currency rows are already in base (rate 1); a pair the live
     * feed still can't cross folds in raw (rate 1), preserving the pre-FX blind-sum
     * rather than dropping revenue. Display-only — never a charge or a stored total.
     *
     * @param  Builder<Order>  $query
     */
    public static function revenueInBase($query): float
    {
        $base = platform_currency(); // the base fx_rate_base was stamped against, not a display swap

        // Stamped rows: convert in-SQL at the frozen per-order rate.
        $total = (float) (clone $query)->revenueCounted()
            ->whereNotNull('fx_rate_base')
            ->selectRaw('SUM('.static::minorMoneySql('grand_total').' * fx_rate_base) as s')
            ->value('s');

        // Unstamped rows: bucket by currency, convert at the live rate (fallback).
        $unstamped = (clone $query)->revenueCounted()
            ->whereNull('fx_rate_base')
            ->selectRaw('currency_code, SUM('.static::minorMoneySql('grand_total').') as s')
            ->groupBy('currency_code')
            ->pluck('s', 'currency_code');

        foreach ($unstamped as $code => $sum) {
            $code = $code ? strtoupper((string) $code) : $base;
            $rate = $code === $base ? 1.0 : (ExchangeRateService::rate($code, $base) ?? 1.0);
            $total += (float) $sum * $rate;
        }

        return $total;
    }

    /**
     * H10: how many revenue-counting orders in this window have no snapshot yet.
     * > 0 means revenueSum() under-reports and `orders:snapshot-totals` owes a run.
     *
     * @param  Builder<Order>  $query
     */
    public static function unsnapshottedCount($query): int
    {
        return (int) (clone $query)->revenueCounted()->whereNull('grand_total_minor')->count();
    }

    /**
     * Relationships
     */
    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        // T6: `combo` joins the eager set so a combo line can name itself without
        // an N+1 — every order-list screen walks these items.
        // P5-36: placement order (id) — without an ORDER BY, PostgreSQL returns a line set in any order.
        return $this->hasMany(OrderItem::class)->orderBy((new OrderItem)->getQualifiedKeyName())->with(['menu', 'itemExtras', 'variant', 'combo']);
    }

    /**
     * MC-10: the order's own currency — the value stamped at creation, falling
     * back to the store's LIVE currency for legacy rows created before the stamp.
     * This is what the money derivation rounds to, so a later store currency
     * change never re-rounds a historical order.
     */
    public function currencyCode(): ?string
    {
        return $this->currency_code ?: (store_options($this->store_id, 'currency_code') ?: null);
    }

    /**
     * J52b: the slot a module fills to freeze an order's money (the LegacyCompat importer's history orders). Its hook
     * answers the stored `['subtotal', 'tax', 'grand']` (major units) for an order it owns, null otherwise. Empty
     * slot (no module, or the module off) = every order derives as before.
     */
    public const FROZEN_MONEY_HOOK = 'order.frozen-money';

    /** @return array{subtotal: float, tax: float, grand: float}|null the frozen amounts, or null when the order derives live */
    public function frozenMoney(): ?array
    {
        if (! Hooks::has(self::FROZEN_MONEY_HOOK)) {
            return null;
        }
        $money = Hooks::first(self::FROZEN_MONEY_HOOK, $this);

        return is_array($money) && isset($money['subtotal'], $money['tax'], $money['grand'])
            ? ['subtotal' => (float) $money['subtotal'], 'tax' => (float) $money['tax'], 'grand' => (float) $money['grand']]
            : null;
    }

    /** J52b: imported history (read-only, frozen money): no refund, re-total or status change applies to it. */
    public function isFrozenHistory(): bool
    {
        return $this->frozenMoney() !== null;
    }

    public function getTotalAttribute()
    {
        // R1c: the accessor deliberately ALWAYS derives — it is the live total.
        // An order line WITHOUT a unit_price snapshot re-prices off the current
        // menu price by design (T5: only scheduled/variant prices are frozen onto
        // the line), so trusting a frozen grand_total here would break that
        // documented, tested behaviour and the theme-totals contract. The
        // subtotal/tax_total/grand_total COLUMNS are a point-in-time snapshot for
        // SQL aggregates / reporting (Lane D · D3), written by snapshotTotals();
        // they are intentionally not read back here.
        return $this->computeMoney()['grand'];
    }

    /**
     * The pre-tax, pre-discount item subtotal — of every line, or only of the lines `$only` accepts (P1-23: the
     * medicine lines a promotion may not touch). The ONE derivation computeMoney() also reads.
     *
     * @param  (callable(OrderItem): bool)|null  $only
     */
    public function itemsSubtotal(?callable $only = null): float|int
    {
        // P1-17: one query per relation level for the whole order, not one per line.
        $this->loadMissing(['items.menu', 'items.variant', 'items.itemExtras']);
        $subtotal = 0;
        foreach ($this->items as $item) {
            if ($only !== null && ! $only($item)) {
                continue;
            }
            /* P3-09: a grocery line the picker settled (actual weight of a weighed line, a kept replacement, a removed
               line = 0) is worth its final amount, in the order's currency. NULL on every other line, so they total
               exactly as before. */
            if ($item->getAttribute('final_minor') !== null) {
                $subtotal += Money::majorFromMinor((int) $item->getAttribute('final_minor'), $this->minorMoneyCurrency());

                continue;
            }
            if ($item->menu) {
                $itemPrice = $item->menu->price;
                if ($item->variation) {
                    $variant = $item->variant;
                    if ($variant) {
                        $itemPrice = $variant->price;
                    }
                }
                /* PARITY-RUN T5: a scheduled (happy-hour / day-part) price is
                   resolved server-side at ORDER time and snapshotted onto the
                   line, exactly like the choice-group prices below. Reading it
                   back here is what makes a historical order re-total to what
                   the customer was actually charged after the schedule has
                   changed, ended or been deleted. NULL on every pre-T5 row and
                   on every line no schedule touched, so the derivation above is
                   unchanged for them. It REPLACES the base/variant price;
                   extras and options stay additive. */
                if ($item->unit_price !== null) {
                    $itemPrice = $item->unit_price;
                }
                $subtotal += $itemPrice * $item->quantity;
                foreach ($item->itemExtras as $itemExtra) {
                    /* Charge the snapshotted effective price (the item's per-modifier
                       price_override at order time) so the order re-totals to what the
                       diner was quoted. NULL on pre-snapshot rows → fall back to the live
                       MenuExtra base price, so historical orders re-total unchanged. */
                    $extraPrice = $itemExtra->price ?? optional($itemExtra->extra)->price;
                    if ($extraPrice !== null) {
                        $subtotal += (float) $extraPrice * $item->quantity;
                    }
                }
            }

            /* T6-2: snapshotted choice-group option prices (× quantity).
               PARITY-RUN T6 hoisted this loop OUT of the `if ($item->menu)` guard
               above. The snapshot is the price authority for everything it
               carries, and it does not need a menu row to be valid:

                 · a COMBO line (order_items.combo_id) has item_id = NULL by
                   design — it is a bundle, not a menu item — and its whole price
                   (bundle + per-slot upcharges) lives here;
                 · a normal line whose menu row was since deleted used to lose its
                   snapshotted option money silently, which is the same
                   quoted-≠-charged bug class in miniature.

               Nothing else changes: a line with no snapshot contributes 0, so
               every existing order re-totals bit-identically. */
            foreach ((array) $item->options as $grp) {
                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                    $subtotal += (float) ($opt['price'] ?? 0) * $item->quantity;
                }
            }
        }

        return $subtotal;
    }

    /**
     * R1c: the single derivation of an order's money, returning the three amounts
     * the accessor and the snapshot both need. Extracted verbatim from the old
     * getTotalAttribute() body so the math (tax on pre-discount subtotal,
     * snapshotted unit/option prices, delivery/tray/tip/donation/service, minus
     * discount) is unchanged. P6-271: the tax base is {@see taxBase()} (after the discount) — CONVENTIONS §theme-totals.
     *
     * @return array{subtotal: float, tax: float, grand: float}
     */
    public function computeMoney(): array
    {
        // J52b: an order whose money is frozen (imported history) shows its stored amounts, never a live re-total.
        if (($frozen = $this->frozenMoney()) !== null) {
            return $frozen;
        }
        $postOptions = store_options($this->store_id);
        // P6-99b: an order on the VAT engine (opted-in store, or a stored per-line snapshot) — TAX-ENGINE.md
        if (($vat = TaxEngine::money($this, $postOptions)) !== null) {
            return $vat;
        }

        $subtotal = $this->itemsSubtotal();

        // MC-10: round to the order's stamped currency, not the store's live one.
        $currencyCode = $this->currencyCode();
        // Offline POS (OFFLINE-POS-SPEC §4.3): a sale made on an offline till keeps the tax it charged.
        $taxAmount = $this->getAttribute('offline_tax_minor') !== null
            ? Money::majorFromMinor((int) $this->getAttribute('offline_tax_minor'), $this->minorMoneyCurrency())
            : static::computeTax($this->taxBase($subtotal), $postOptions, $currencyCode);
        $deliveryCharge = $this->deliveryCharge($postOptions);

        // T1 · H8 hotel tray / room-service charge. Mirrors the delivery-charge
        // pattern: auto-added to room_service orders only, from store_options
        // `hotel_tray_charge` (bare amount or "N%" of subtotal). The POS-only
        // `service_charge_amount` above is a separate, untouched channel.
        $trayCharge = static::trayCharge($this->type, $subtotal, $this->store_id, $currencyCode);

        $grand = $subtotal + $deliveryCharge + $taxAmount + $trayCharge
            + ($this->tip_amount ?? 0)
            + ($this->donation_amount ?? 0) // T3-3 marketplace round-up donation (0 elsewhere)
            + ($this->service_charge_amount ?? 0)   // POS service charge (null for non-POS)
            - ($this->discount_amount ?? 0);

        /* An order is never worth less than nothing. Discounts stack from
         * independent sources — a coupon, a loyalty reward redemption, a POS
         * manual discount — and none of them knows what the others already took,
         * so a large coupon plus a reward could drive the grand total negative
         * (a 12.00 basket with a 10.00 coupon and a 5.00 reward computed as
         * -3.00). A negative total propagates straight into the gateway charge,
         * the Z-report and every revenue aggregate.
         *
         * Floored here because this is the one derivation every surface reads.
         * discount_amount is deliberately left untouched: it records what was
         * granted, which is what reporting and any later refund need to see. */
        $grand = max(0.0, $grand);

        return [
            'subtotal' => (float) $subtotal,
            'tax' => (float) $taxAmount,
            'grand' => (float) $grand,
        ];
    }

    /**
     * P6-271: the base the legacy tax is charged on — the items subtotal less the order discount (never below 0). An
     * order placed before P6-271 (`tax_pre_discount`) keeps the old base, the pre-discount subtotal, so its total
     * never changes.
     */
    public function taxBase(?float $subtotal = null): float
    {
        $subtotal ??= (float) $this->itemsSubtotal();
        if ($this->getAttribute('tax_pre_discount')) {
            return $subtotal;
        }

        return max(0.0, $subtotal - (float) ($this->discount_amount ?? 0));
    }

    /**
     * Bug batch 4 · 3: the delivery fee this order charges — the fee stored on the order when one was quoted (a
     * marketplace order charges the zone fee its bag showed), else its branch's own fee (P5-31), else the store's own `restaurant_delivery_charge`
     * (every other order, unchanged) — 0 once the items reach the store's "free delivery over X". 0 for a non-delivery order. Every reader of the order's delivery fee
     * (totals, the free-delivery waiver, receipts, the GL journal, own-fleet courier fee) uses this.
     */
    public function deliveryCharge(?object $postOptions = null): float
    {
        // P3-08: a ship order charges the shipping fee its quote showed (stored with the order); 0 before P3-08.
        if ($this->type === 'ship') {
            return $this->getAttribute('delivery_fee_minor') !== null ? (float) $this->delivery_fee : 0.0;
        }
        if ($this->type !== 'delivery') {
            return 0.0;
        }
        if ($this->getAttribute('delivery_fee_minor') !== null) {
            return (float) $this->delivery_fee;
        }
        $postOptions ??= store_options($this->store_id);
        // P5-31: the order's branch's own delivery fee, else the store's
        $own = $this->pricingBranch()?->deliveryFeeOverride();
        // the store's "free delivery over X": the items subtotal (before discounts) reached X. P5-34: it waives a
        // branch's own fee too unless the owner turned "also covers branch delivery fees" off.
        $threshold = self::freeDeliveryThreshold($postOptions);
        if ($threshold !== null && ($own === null || self::freeDeliveryCoversBranchFee($postOptions)) && $this->itemsSubtotal() >= $threshold) {
            return 0.0;
        }

        return $own ?? (float) ($postOptions->restaurant_delivery_charge ?? 0);
    }

    /**
     * P5-34: whether the store's "free delivery over X" also waives a branch's own delivery fee. Default ON (unset);
     * OFF = a branch's own fee always applies.
     */
    public static function freeDeliveryCoversBranchFee(?object $postOptions): bool
    {
        $v = $postOptions->restaurant_free_delivery_covers_branch_fee ?? null;

        return $v === null || $v === '' || filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * P5-31: the order's branch when it belongs to the order's store (its delivery overrides apply), else null.
     */
    public function pricingBranch(): ?Branch
    {
        if (! $this->branch_id || ! $this->store_id) {
            return null;
        }
        // Not getRelationValue(): a list of orders that did not eager-load `branch` would trip Model::preventLazyLoading() (a 500 in local/testing).
        $branch = $this->relationLoaded('branch') ? $this->getRelation('branch') : Branch::query()->find($this->branch_id);

        return $branch instanceof Branch && (int) $branch->store_id === (int) $this->store_id ? $branch : null;
    }

    /**
     * The store's "free delivery over X" (major units of the store currency), or null when off. It waives the store's
     * own delivery fee only: a fee quoted onto the order (marketplace zone fee, express slot) is the order's own.
     */
    public static function freeDeliveryThreshold(?object $postOptions): ?float
    {
        $v = $postOptions->restaurant_free_delivery_over ?? null;

        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    /**
     * How much more (major units) the items need for free delivery; null when the threshold is off, the order is not a
     * delivery on the store's own fee, or the fee is 0 anyway. 0.0 = reached.
     */
    public function freeDeliveryRemaining(?object $postOptions = null): ?float
    {
        if ($this->type !== 'delivery' || $this->getAttribute('delivery_fee_minor') !== null) {
            return null;
        }
        $postOptions ??= store_options($this->store_id);
        $own = $this->pricingBranch()?->deliveryFeeOverride();
        if ($own !== null && ! self::freeDeliveryCoversBranchFee($postOptions)) {
            return null; // P5-34: the owner kept the branch's own fee outside "free delivery over X"
        }
        $threshold = self::freeDeliveryThreshold($postOptions);
        if ($threshold === null || ($own ?? (float) ($postOptions->restaurant_delivery_charge ?? 0)) <= 0) {
            return null;
        }

        return max(0.0, $threshold - $this->itemsSubtotal());
    }

    /**
     * R1c: freeze subtotal/tax_total/grand_total onto the row. Called at every
     * order-write choke point once the line items exist (OrderApiService::create,
     * the storefront checkout, and any later total-affecting edit via the updated
     * hook). Writes ONLY when the order has persisted items, so a bare-order save
     * before its lines exist can never poison the columns with a 0 the accessor
     * would then trust. saveQuietly (no events → no recursion) and only when a
     * value actually changed, so it costs nothing on a no-op re-save.
     */
    public function snapshotTotals(): void
    {
        // J52b: frozen money (imported history) is never re-snapshotted, not even by `orders:snapshot-totals --force`.
        if (! $this->exists || $this->frozenMoney() !== null || $this->items->isEmpty()) {
            return;
        }

        $money = $this->computeMoney();

        $dirty = false;
        // P1-05: compared in minor units of the order's currency (the stored precision).
        $currency = $this->minorMoneyCurrency();
        foreach (['subtotal' => 'subtotal', 'tax_total' => 'tax', 'grand_total' => 'grand'] as $col => $key) {
            $new = money_round($money[$key], 3);
            $minor = Money::minorFromMajor($new, $currency);
            if ($this->getAttribute($col.'_minor') === null || (int) $this->getAttribute($col.'_minor') !== $minor) {
                $this->{$col} = $new;
                $dirty = true;
            }
        }

        // P6-99b: freeze the per-line VAT (rates, mode, amounts) the order charged — fiscal + journal read it
        $vat = TaxEngine::compute($this);
        if ($vat !== null) {
            $json = TaxEngine::encode($vat);
            if ($this->getAttribute('tax_lines') !== $json) {
                $this->setAttribute('tax_lines', $json);
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->saveQuietly();
        }
    }

    /**
     * Coupons & Wallets P3-3: amount still payable via gateway/cash after store
     * credit. `total` is the order value; `wallet_applied` is a payment, not a
     * discount, so it only lowers what is due.
     */
    public function getAmountDueAttribute(): float
    {
        // T2: gift_card_applied is a tender too (like wallet_applied) — lowers due.
        // Rounds to the restaurant's currency decimals: `total` now carries fils
        // on a 3-decimal currency, so re-rounding to 2 here would discard them
        // again and under-charge. Resolves to 2 (bit-identical) elsewhere.
        return money_round(
            (float) $this->total
                - (float) ($this->wallet_applied ?? 0)
                - (float) ($this->gift_card_applied ?? 0)
                // P9: the Fusion platform wallet is a TENDER like the two above —
                // it reduces what is owed, not the order value. Omitting it here
                // would overstate the amount due and over-charge the gateway.
                - (float) ($this->mk_wallet_applied ?? 0),
            money_decimals(store_options($this->store_id, 'currency_code') ?: null)
        );
    }

    /**
     * D7/D8 (integrations pack): the human channel name an aggregator order arrived
     * through (e.g. "Deliverect"), or null for a native order. Resolved from
     * the channel names the Channels module registers (Deliverect's CHANNEL_NAMES via the 'order.channel-label' hook, P1-13) keyed by the
     * stored `source`. Drives the staff order-card badge and the customer track-page
     * "Ordered via" line — neither renders anything for pos/online/customer_app/api.
     */
    public function channelLabel(): ?string
    {
        $label = Hooks::first('order.channel-label', (string) ($this->source ?? ''));

        return is_string($label) ? $label : null;
    }

    /**
     * Compute order tax honoring the restaurant's tax type. The stored
     * `restaurant_taxx_charge` number is interpreted per `restaurant_tax_type`:
     * a percentage of subtotal (default) or a flat fixed amount. This is the
     * single authoritative tax calculation — every charge/display site routes
     * through it so "Fixed" tax is actually applied instead of silently being
     * treated as a percentage.
     *
     * Rounding follows the RESTAURANT's currency, not a hardcoded 2: the same
     * `$postOptions` bag that carries the tax rate also carries `currency_code`,
     * so the store's own currency resolves here without touching config. On a
     * 3-decimal currency `round($x, 2)` silently discarded fils from the tax and
     * therefore from the order total. Resolves to 2 for every 2-decimal
     * currency, leaving those totals bit-identical.
     *
     * @param  object|null  $postOptions  A store_options() bag (stdClass).
     * @param  string|null  $currencyCode  MC-10: round to this currency instead of
     *                                     the live store_options one (the order's
     *                                     stamped currency). Defaults to the bag's
     *                                     currency, so every existing caller is
     *                                     bit-identical.
     */
    public static function computeTax(float $subtotal, $postOptions, ?string $currencyCode = null): float
    {
        $charge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
        if ($charge <= 0 || $subtotal <= 0) {
            return 0.0;
        }

        $decimals = money_decimals($currencyCode ?: (@$postOptions->currency_code ?: null));

        $type = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
        if ($type === 'fixed') {
            return money_round($charge, $decimals);
        }

        return money_round($subtotal * ($charge / 100), $decimals);
    }

    /**
     * T1 · H8 — the hotel tray / room-service charge for an order, or 0 when it
     * does not apply. Only `room_service` orders carry it; the amount comes from
     * store_options `hotel_tray_charge` (a bare amount, or an "N%" percent of the
     * subtotal) via the hotel RoomCharge::trayCharge(). Kept static so display code and
     * the total attribute compute it identically.
     *
     * Rounds to the restaurant's own currency decimals (see computeTax). The
     * lookup sits below both guards so non-room_service orders — the vast
     * majority — never pay for the extra option read.
     */
    public static function trayCharge(?string $type, float $subtotal, $storeId, ?string $currencyCode = null): float
    {
        if ($type !== 'room_service' || $subtotal <= 0) {
            return 0.0;
        }

        $tray = app(RoomCharge::class)->trayCharge((int) $storeId);
        if ($tray['value'] <= 0) {
            return 0.0;
        }

        // MC-10: round to the order's stamped currency when given, else the store's live one.
        $decimals = money_decimals($currencyCode ?: (store_options($storeId, 'currency_code') ?: null));

        return $tray['type'] === 'percent'
            ? money_round($subtotal * ($tray['value'] / 100), $decimals)
            : money_round((float) $tray['value'], $decimals);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * rev/mv-surface-api P1 — the goods-fulfillment shipment for a `ship` order
     * (Lane B). Null for every food / pickup_slot / booking order; the storefront
     * and API read it back for carrier + tracking number.
     *
     * @return HasOne<Shipment, $this>
     */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'order_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * FEATURES-5 Track C: the customer voice note attached to this order (if any).
     */
    public function voiceNote()
    {
        return $this->hasOne(OrderVoiceNote::class, 'order_id');
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
