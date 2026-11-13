<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Data\LineOptionsSnapshot;
use Juwri\Modules\Ordering\Models\OrderItemExtra;

/**
 * @property string $variant_title
 * @property string|float|null $unit_price P1-05: major amount (store-precision string on read)
 * @property int|null $unit_price_minor P1-05: minor units of currency_code (the order's currency)
 * @property string|null $currency_code
 */
class OrderItem extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    /* PARITY-RUN T5: an order item has no store_id — this trait supplies
       storeIdForMoney() below, which HasMinorMoney uses for the row currency
       when the line has none of its own yet (else it takes the order's). */
    use ResolvesStoreForMoney;

    public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = [];

    /**
     * Relationships
     */
    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'item_id');
    }

    /** @return BelongsTo<MenuVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuVariant::class, 'variation');
    }

    /** @return HasMany<OrderItemExtra, $this> */
    public function itemExtras(): HasMany
    {
        return $this->hasMany(OrderItemExtra::class)->with('extra');
    }

    /** @return BelongsTo<KitchenStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'station_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** PARITY-RUN T6: the combo this line sells, when it is a combo line. */
    /** @return BelongsTo<Combo, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class, 'combo_id');
    }

    /**
     * PARITY-RUN T6: is this line a combo bundle rather than a menu item?
     *
     * A combo line carries `combo_id` and a NULL `item_id` — deliberately, so
     * revenue is never mis-attributed to whichever component we might otherwise
     * have anchored it on. Every renderer that walks order items should ask this
     * before assuming `->menu` is there.
     */
    public function isCombo(): bool
    {
        return $this->combo_id !== null;
    }

    /**
     * P3-07: what the guest wrote for this line ("Name on the cup"), without the choice lines the checkout folds into
     * `order_note` for the kitchen ("Milk: Oat (+2.00)"): a receipt prints the choices from the `options` snapshot already,
     * so repeating them under the line would double them. Control characters are removed (a note is printed on thermal
     * printers, which read them as commands); the text is otherwise as stored and escaped where it is shown.
     */
    public function customerNote(): string
    {
        $note = (string) $this->order_note;
        if (trim($note) === '') {
            return '';
        }

        $folded = [];
        foreach ((array) $this->options as $group) {
            foreach ((array) ($group['selected'] ?? []) as $selected) {
                $folded[] = (string) ($group['title'] ?? '').': '.(string) ($selected['option'] ?? '');
            }
        }

        $kept = [];
        foreach (preg_split('/\R/u', $note) ?: [] as $line) {
            $line = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $line));
            if ($line === '' || in_array((string) preg_replace('/ \(\+[\d.,]+\)$/u', '', $line), $folded, true)) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * The name to print/show for this line. A combo has no menu row, so falling
     * through to `menu->name` would render an empty cell or a bare "—".
     */
    public function displayName(): string
    {
        if ($this->isCombo()) {
            $snapshot = $this->comboSnapshot();
            if ($snapshot['name'] !== '') {
                return $snapshot['name'];
            }

            return (string) ($this->combo->name ?? ___('Combo'));
        }

        return (string) ($this->menu?->getRawOriginal('name') ?? '');
    }

    /**
     * Read a combo line's `options` snapshot back into something renderable.
     *
     * Shape out:
     *   ['name' => 'Family Meal', 'base_price' => 15.0,
     *    'components' => [['slot' => 'Choose your drink', 'name' => 'Large Cola',
     *                      'price' => 0.75, 'menu_id' => 33, 'variant_id' => 5]]]
     *
     * The snapshot is the source of truth here on purpose: the owner may have
     * edited or deleted the combo since, and the ticket in the kitchen must
     * still say what the diner ordered.
     */
    public function comboSnapshot(): array
    {
        $name = '';
        $base = 0.0;
        $components = [];

        foreach ((array) $this->options as $grp) {
            $isBase = ! empty($grp['combo']);
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                if ($isBase) {
                    $name = (string) ($grp['title'] ?? ($opt['option'] ?? ''));
                    $base += (float) ($opt['price'] ?? 0);

                    continue;
                }
                $components[] = [
                    'slot' => (string) ($grp['title'] ?? ''),
                    'name' => (string) ($opt['option'] ?? ''),
                    'price' => (float) ($opt['price'] ?? 0),
                    'menu_id' => isset($opt['menu_id']) ? (int) $opt['menu_id'] : null,
                    'variant_id' => isset($opt['variant_id']) ? ($opt['variant_id'] !== null ? (int) $opt['variant_id'] : null) : null,
                ];
            }
        }

        return ['name' => $name, 'base_price' => $base, 'components' => $components];
    }

    /**
     * Unit price carried by the `options` snapshot (bundle + upcharges for a
     * combo, choice-group options for a normal line). This is the SAME sum
     * Order::getTotalAttribute() performs — kept here so receipts and cart views
     * cannot drift from what the server charges.
     */
    public function snapshotUnitPrice(): float
    {
        $sum = 0.0;
        foreach ((array) $this->options as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $sum += (float) ($opt['price'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * BL-049: freeze `unit_price` on a line that has none, at exactly the price Order::itemsSubtotal() derives for it
     * now (the variant price when one is chosen, else the menu price), so the order never re-totals after a menu price
     * edit. No-op on a line that already carries a price, a picker-settled line (`final_minor`) or a line without a
     * menu row (combo). Saves quietly: the price does not change, only stops moving.
     */
    public function freezeUnitPrice(): void
    {
        if ($this->unit_price !== null || $this->getAttribute('final_minor') !== null || ! $this->menu) {
            return;
        }
        $price = $this->menu->price;
        if ($this->variation && $this->variant) {
            $price = $this->variant->price;
        }
        $this->unit_price = (float) $price;
        $this->saveQuietly();
    }

    /**
     * Bug batch 5 · 1: the unit price this line was SOLD at, in its order's currency — exactly the per-unit value
     * Order::computeMoney() charges: the scheduled `unit_price` snapshot if set, else the variant / menu price (only
     * when the menu row still exists), plus the snapshotted extras (falling back to the live modifier price), plus
     * the choice-group / combo options snapshot (P1-06, stored in minor units). Summed in minor units so a KWD
     * 1.125 + 0.250 is exactly 1.375. Since BL-049 every new line carries `unit_price`; NULL only on older lines not yet frozen.
     */
    public function soldUnitPrice(): float
    {
        $currency = $this->minorMoneyCurrency();
        $minor = 0;
        if ($this->menu) {
            $base = $this->menu->price;
            if ($this->variation && $this->variant) {
                $base = $this->variant->price;
            }
            if ($this->unit_price !== null) {
                $base = $this->unit_price;
            }
            $minor += Money::minorFromMajor((float) $base, $currency);
            foreach ($this->itemExtras as $itemExtra) {
                $extraPrice = $itemExtra->price ?? optional($itemExtra->extra)->price;
                if ($extraPrice !== null) {
                    $minor += Money::minorFromMajor((float) $extraPrice, $currency);
                }
            }
        }
        foreach ((array) $this->options as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $minor += Money::minorFromMajor((float) ($opt['price'] ?? 0), $currency);
            }
        }

        return Money::majorFromMinor($minor, $currency);
    }

    /**
     * PARITY-RUN T5: server-resolved unit price at ORDER time. When set it REPLACES the derived menu/variant price
     * in Order::getTotalAttribute(); extras and choice-group options stay additive on top.
     * P1-05: stored as `unit_price_minor` in the order's currency; `unit_price` stays a store-precision string.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['unit_price' => 'unit_price_minor,store'];

    /** P1-05: a line's amounts are in its order's currency (stamped on the order at creation). */
    public function defaultMinorMoneyCurrency(): string
    {
        $orderId = $this->getAttribute('order_id');
        $code = $orderId ? DB::table('orders')->where('id', $orderId)->value('currency_code') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    protected static function booted(): void
    {
        // P1-06: the options snapshot stores its prices as `price_minor` in the line's currency (settled by now).
        static::saving(function (self $item): void {
            if ($item->isDirty('options')) {
                $options = $item->options;
                if (is_array($options)) {
                    $item->options = LineOptionsSnapshot::toStorage($options, $item->minorMoneyCurrency());
                }
            }
        });
    }

    /** P1-05: the order's currency is a fact of the sale — never re-interpreted by a store-currency change. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    protected $casts = [
        'started_at' => 'datetime',
        'item_ready_at' => 'datetime',
        'served_at' => 'datetime',
        'fired_at' => 'datetime',
        'recalled_at' => 'datetime',
        'course' => 'integer',
        'options' => LineOptionsSnapshot::class, // T6-1: snapshotted choice-group selections (P1-06: prices in minor units)
        // rev/multi-vertical Lane D2/D4: the booked/actual weight behind a
        // per-kg service line's unit_price snapshot (see the
        // add_weight_kg_to_order_items_table migration). Null for every
        // each-priced/food line.
        'weight_kg' => 'float',
        // P3-09: weighed line + picking
        'weight_g' => 'integer',
        'cap_minor' => 'integer',
        'actual_g' => 'integer',
        'final_minor' => 'integer',
        'replacement_minor' => 'integer',
        'refunded_minor' => 'integer',
        'picked_at' => 'datetime',
    ];

    /**
     * HasMinorMoney (row currency): an order item reaches its store through its order.
     * Resolved from the ALREADY-KNOWN order_id via one cached lookup — never a
     * lazy relation load, or a 200-line report would cost 200 queries.
     */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('orders', 'order_id', 'store_id');
    }

    /** PARITY-RUN T5: the schedule that produced `unit_price`, if it still exists. */
    /** @return BelongsTo<PriceSchedule, $this> */
    public function priceSchedule(): BelongsTo
    {
        return $this->belongsTo(PriceSchedule::class, 'price_schedule_id');
    }

    public const KDS_STATUSES = ['queued', 'preparing', 'ready', 'served'];

    /** Advance one KDS step: queued → preparing → ready → served. */
    public function bumpForward(): void
    {
        $next = [
            'queued' => 'preparing',
            'preparing' => 'ready',
            'ready' => 'served',
        ][$this->status ?? 'queued'] ?? null;

        if (! $next) {
            return;
        }
        $this->status = $next;
        if ($next === 'preparing' && ! $this->started_at) {
            $this->started_at = now();
        } elseif ($next === 'ready') {
            $this->item_ready_at = now();
        } elseif ($next === 'served') {
            $this->served_at = now();
        }
        $this->save();
    }

    /** Recall (un-bump) one step back and clear the relevant timestamp. */
    public function recallBack(): void
    {
        $prev = [
            'served' => 'ready',
            'ready' => 'preparing',
            'preparing' => 'queued',
        ][$this->status ?? 'queued'] ?? null;

        if (! $prev) {
            return;
        }
        // Clear the timestamp of the step we are leaving.
        if ($this->status === 'served') {
            $this->served_at = null;
        } elseif ($this->status === 'ready') {
            $this->item_ready_at = null;
        } elseif ($this->status === 'preparing') {
            $this->started_at = null;
        }
        $this->status = $prev;
        $this->recalled_at = now();
        $this->save();
    }

    /** Seconds since this item entered the kitchen (fired/created). */
    public function getAgeSecondsAttribute(): int
    {
        $start = $this->fired_at ?: ($this->order->created_at ?? now());

        return max(0, now()->diffInSeconds($start, false) * -1);
    }

    /**
     * Get the formatted variation title (e.g., "Small, Spicy")
     */
    public function getVariationTitleAttribute()
    {
        if (! $this->variant) {
            return '';
        }
        $variant_title = [];
        if (! empty($this->variant->options)) {
            foreach ((array) $this->variant->options as $option_id => $option_key) {
                $menu_variant_option = is_numeric($option_id) ? MenuVariantOption::find((int) $option_id) : null;
                if ($menu_variant_option) {
                    $options = $menu_variant_option->options;
                    if (is_string($options)) {
                        $options = json_decode($options, true);
                    } elseif (is_object($options)) {
                        $options = (array) $options;
                    }
                    if (is_array($options) && isset($options[$option_key])) {
                        $variant_title[] = $options[$option_key];
                    }
                }
            }
        }

        return ! empty($variant_title) ? implode(', ', $variant_title) : '';
    }
}
