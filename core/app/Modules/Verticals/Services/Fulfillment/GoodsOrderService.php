<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fulfillment;

use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\Verticals\FulfillmentRegistry;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Contracts\Shipping;
use Juwri\Modules\Ordering\Data\ShippingOption;
use Juwri\Modules\Ordering\Models\ShippingMethod;

/**
 * rev/multi-vertical B2/B3 — ship-to-address + pickup-slot checkout.
 *
 * Converts a store's current goods cart (GoodsCartService) into an Order, for
 * the non-food fulfillment modes (ship/pickup_slot/appointment/in_store).
 * Deliberately its OWN small order-writer rather than a fork of
 * OrderApiService::create() — that method hard-codes food concepts (KDS
 * station routing, table/room-service branching, advanced-inventory recipe
 * deduction) and SILENTLY COERCES an unrecognised type to 'takeaway'
 * (`self::TYPES` private const), so it cannot safely carry a 'ship' order
 * without either forking it or widening a food-only seam. This keeps the
 * restaurant order-creation path byte-identical while still landing on the
 * SAME `orders`/`order_items` tables and the SAME Order::computeMoney()
 * total derivation (theme-totals-contract) — an OrderItem row with just
 * item_id/variation/quantity re-prices live off the menu/variant, exactly
 * like every pre-snapshot line already does.
 */
class GoodsOrderService
{
    public function __construct(
        private FulfillmentRegistry $fulfillment,
        private FulfillmentSettingsService $settings,
        private GoodsCartService $cart,
        private SchedulingService $scheduling,
        private Shipping $shipping,
        private HostedPayments $hostedPayments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  address, phone_number, customer_name,
     *                                      scheduled_at (Y-m-d H:i / any Carbon-parsable string)
     */
    public function checkout(Store $store, string $cartToken, string $mode, array $data): Order
    {
        return $this->checkoutOrReplay($store, $cartToken, $mode, $data)[0];
    }

    /**
     * J27-A2: checkout() that honours a client order key — `$data['client_order_id']` (already format-checked by
     * the controller). The first request with a key places the order (key + cart fingerprint stored on it); a
     * resend returns that SAME order — also once its cart is converted, which is exactly the lost-answer retry —
     * and never a second one; a resend with a different cart (or another fulfilment mode) is a 409. Concurrent
     * twins meet the UNIQUE (store_id, client_uuid) index: the loser gets the winner's order.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Order, 1: bool} [order, replayed] — a replayed order must not be charged/processed again
     *
     * @throws ClientOrderConflict
     */
    public function checkoutOrReplay(Store $store, string $cartToken, string $mode, array $data): array
    {
        $this->assertModeAllowed($store, $mode);

        $lines = $this->cart->lines($store, $cartToken);
        $key = isset($data['client_order_id']) && $data['client_order_id'] !== '' ? (string) $data['client_order_id'] : null;
        $hash = $key !== null ? $this->cartFingerprint($store, $cartToken, $mode) : null;
        if ($key !== null) {
            $existing = Order::where('store_id', $store->id)->where('client_uuid', $key)->first();
            if ($existing) {
                if ($lines->isEmpty()) {
                    // The cart this key placed is converted — a retry of that order, unless the mode changed.
                    if ((string) $existing->type !== $mode) {
                        throw new ClientOrderConflict((int) $existing->id);
                    }
                } else {
                    ClientOrderId::assertSameCart($existing, $hash);
                }

                return [$existing->fresh('items'), true];
            }
        }

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ___('Your cart is empty.')]);
        }
        $this->cart->assertSalesAllowed($store, $this->cart->currentCart($store, $cartToken)); // P1-23: AUP 3.1
        $this->cart->assertAvailable($store, $this->cart->currentCart($store, $cartToken)); // sold out / no stock since it was added

        // P3-08: the shipping method of a ship order (fee + delivery window, server-quoted) and online payment on the
        // core-hosted page (F3, API-2) — both checked before anything is written.
        $shipping = $this->resolveShipping($store, $mode, $data, (float) $lines->sum('line_total'));
        $hosted = ($data['payment_mode'] ?? null) === 'hosted';
        if ($hosted) {
            $this->assertHostedAvailable($store, (float) $lines->sum('line_total'));
        }
        $address = $shipping !== null && $shipping->method->kind === 'pickup_in_store' ? null : $this->resolveAddress($mode, $data);
        [$scheduledAt, $lock] = $this->resolveSlot($store, $mode, $data);

        $replayed = false;
        try {
            $order = DB::transaction(function () use ($store, $mode, $address, $scheduledAt, $lines, $data, $key, $hash, $shipping, $hosted, &$replayed) {
                $row = [
                    'store_id' => $store->id,
                    'client_uuid' => $key,
                    'client_order_hash' => $hash,
                    'type' => $mode,
                    // P3-08 (F3): an order paid online waits `unpaid` (kept out of the kitchen) for the hosted payment
                    // window; the gateway's confirmation (PaymentController::paySuccess) places it.
                    'status' => $hosted ? 'unpaid' : 'new',
                    'payment_mode' => $hosted ? 'hosted' : null,
                    'payment_expires_at' => $hosted ? now()->addMinutes(max(1, (int) config('api.storefront.hosted_window_minutes', 15))) : null,
                    // Link an authenticated customer so this order shows in their
                    // history and can earn/redeem loyalty (guests stay null).
                    'customer_id' => Auth::guard('customer')->id(),
                    'customer_name' => $data['customer_name'] ?? null,
                    'phone_number' => $data['phone_number'] ?? null,
                    'address' => $address,
                    'scheduled_at' => $scheduledAt,
                    'track_token' => $this->uniqueTrackToken(),
                    'created_at' => now(),
                ];
                if ($shipping !== null) {
                    $row += [
                        'shipping_method_id' => (int) $shipping->method->id,
                        'delivery_fee' => Money::majorFromMinor($shipping->feeMinor, $shipping->currency),
                        'shipping_eta_from' => $shipping->earliest->toDateString(),
                        'shipping_eta_to' => $shipping->latest->toDateString(),
                    ];
                    if ($shipping->method->kind === 'pickup_in_store' && $shipping->method->pickup_branch_id !== null) {
                        $row['branch_id'] = (int) $shipping->method->pickup_branch_id;
                    }
                }
                try {
                    // Savepoint: on PostgreSQL a failed insert would abort the enclosing transaction.
                    $order = DB::transaction(fn () => Order::create($row));
                } catch (QueryException $e) {
                    $winner = $key !== null ? Order::where('store_id', $store->id)->where('client_uuid', $key)->first() : null;
                    if (! $winner) {
                        throw $e;
                    }
                    // A concurrent twin with the same key won the insert: hand back its order (or 409 for another cart).
                    ClientOrderId::assertSameCart($winner, $hash);
                    $replayed = true;

                    return $winner;
                }

                $pricing = app(ItemPricing::class);
                foreach ($lines as $line) {
                    /* P1-12 (bug): snapshot the scheduled price the cart showed (sale / happy hour), as the order API
                       does for every other channel; the line used to re-price at the list price. */
                    $menu = Menu::find((int) $line['menu_id']);
                    $snapshot = $menu !== null
                        ? $pricing->snapshot($menu, $line['variant_id'] ? MenuVariant::find((int) $line['variant_id']) : null)
                        : [];
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'item_id' => $line['menu_id'],
                        'variation' => $line['variant_id'],
                        'quantity' => $line['quantity'],
                    ] + $snapshot);

                    // Add-ons snapshotted on the cart line become OrderItemExtra
                    // rows (effective price), exactly like OrderApiService — so
                    // Order::computeTotals() adds them into the subtotal.
                    foreach ((array) ($line['extras'] ?? []) as $extra) {
                        if (! empty($extra['id'])) {
                            OrderItemExtra::create([
                                'order_item_id' => $orderItem->id,
                                'extra_id' => (int) $extra['id'],
                                'price' => (float) ($extra['price'] ?? 0),
                            ]);
                        }
                    }

                    // rev/multi-vertical Lane V (V3 attribute-integrity fix) —
                    // this native writer created Order/OrderItem rows but never
                    // touched stock at all, unlike OrderApiService (POS/legacy
                    // storefront), which deducts both the item's simple-mode
                    // stock_qty and (independently) the SELECTED variant's own
                    // stock_qty (Lane A2). Mirror that exact pair of calls —
                    // both are no-ops for a null stock_qty / untracked item, so
                    // this changes nothing for a store that never opted into
                    // stock tracking.
                    /* P3-08 (REVIEW, Opus: stock race): a shopper's order takes its stock or is refused — one
                       conditional UPDATE per row (`where stock_qty >= qty`, run under the row lock against the
                       latest committed value), so two shoppers racing for the last unit cannot both get it. The
                       loser's whole order rolls back (we are inside its transaction); nothing is taken for it. */
                    if (! Menu::reserveStock((int) $line['menu_id'], (int) $line['quantity'])
                        || ($line['variant_id'] && ! MenuVariant::reserveStock((int) $line['variant_id'], (int) $line['quantity']))) {
                        throw ValidationException::withMessages(['cart' => ___(':item does not have enough stock left.', ['item' => (string) ($line['name'] ?? '')])]);
                    }
                }

                $this->applyDiscounts($order, $store, $data, (float) $lines->sum('line_total'));

                $order->unsetRelation('items');
                $order->snapshotTotals();

                return $order;
            });
        } finally {
            optional($lock)->release();
        }

        if ($replayed) {
            return [$order->fresh('items'), true];
        }

        $this->cart->convert($store, $cartToken);

        if ($hosted) {
            // F3: the pending payment on the core pay page (the store's own gateways only); points are earned once paid.
            $this->hostedPayments->open($store, $order, null, request()->ip());

            return [$order->fresh('items'), false];
        }

        /* P1-12 (bug): a goods order (paid at collection / delivery — this checkout takes no online payment) earns its
           loyalty points at placement through the ONE accrual seam, like a cash order of the restaurant checkout. It
           never earned: the goods checkout let customers REDEEM points but not earn them. Idempotent per order;
           P1-23 promotion block applies (no points on medicines where the market blocks promotions). */
        LoyaltyAccrual::earnForOrder($order->fresh() ?? $order);

        return [$order->fresh('items'), false];
    }

    /** J27-A2: the cart as the client resends it — mode + lines (item, variant, quantity, weight, add-on ids). */
    private function cartFingerprint(Store $store, string $cartToken, string $mode): string
    {
        $lines = $this->cart->currentCart($store, $cartToken)->lines()->orderBy('id')->get()
            ->map(fn ($line) => [
                'menu_id' => $line->menu_id,
                'variation' => $line->variation,
                'quantity' => $line->quantity,
                'weight_kg' => $line->weight_kg,
                'extras' => array_values(array_map(fn ($e) => is_array($e) ? ($e['id'] ?? null) : $e, (array) ($line->extras ?? []))),
            ])->all();

        return ClientOrderId::fingerprint(['type' => $mode, 'items' => $lines]);
    }

    /**
     * P1-23 follow-up: the short reason to show when a coupon or loyalty reward the customer asked for was refused
     * because the order holds medicines in a market that blocks promotions (null otherwise).
     */
    public function promotionsNote(Order $order, bool $couponAsked, bool $loyaltyAsked): ?string
    {
        return OrderPricing::promotionsNote($order, $couponAsked, $loyaltyAsked);
    }

    /**
     * Apply the opt-in loyalty redemption + coupon/gift-card to an order against a
     * given pre-discount `$subtotal`, both server-derived (never a client amount).
     * Extracted so a wrapper that rewrites line prices AFTER checkout (e.g. the
     * laundry per-kg writer, the Laundry module's ServiceBookingService)
     * can apply discounts against the CORRECTED subtotal instead of the cart's
     * pre-correction one. Idempotent per order (OrderPricing claims usage atomically);
     * a no-op when no code/redeem was requested. Caller snapshots totals afterwards.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyDiscounts(Order $order, Store $store, array $data, float $subtotal): void
    {
        // Loyalty redemption — opt-in via `loyalty_redeem`, server-derived. Mirrors
        // the guarded block in OrderApiService; a no-op for guests (no customer_id)
        // and for stores without an active program.
        if (! empty($data['loyalty_redeem'])) {
            // The ONE implementation (OrderPricing::redeemLoyalty); P1-23: never on medicine lines.
            OrderPricing::redeemLoyalty($order, (int) $store->id);
        }

        // Coupon + gift-card — settled through the SAME canonical path the restaurant
        // pipeline uses (OrderPricing): re-resolves the coupon server-side against the
        // store owner's coupons, claims per-customer AND global usage atomically (no
        // over-spend / double-discount, idempotent per order), stacks the discount onto
        // discount_amount, and redeems a gift card as a tender that only reduces amount
        // due. Never trusts a client-sent amount.
        if (! empty($data['coupon_code']) || ! empty($data['gift_card_code'])) {
            OrderPricing::applyCouponAndCredit($order, [
                'coupon_code' => $data['coupon_code'] ?? null,
                'gift_card_code' => $data['gift_card_code'] ?? null,
                'subtotal' => $subtotal,
                'owner_id' => $store->user_id,
                'customer_id' => $order->customer_id,
                'branch_id' => $order->branch_id,
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function resolveAddress(string $mode, array $data): ?string
    {
        if (! $this->fulfillment->needsAddress($mode)) {
            return null;
        }

        $address = trim((string) ($data['address'] ?? ''));
        if ($address === '') {
            throw ValidationException::withMessages(['address' => ___('An address is required for this fulfillment method.')]);
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?Carbon, 1: ?Lock}
     */
    private function resolveSlot(Store $store, string $mode, array $data): array
    {
        if (! $this->fulfillment->needsSlot($mode)) {
            return [null, null];
        }

        $requested = $data['scheduled_at'] ?? null;
        if (empty($requested)) {
            throw ValidationException::withMessages(['scheduled_at' => ___('Please pick a time slot.')]);
        }

        try {
            $scheduledAt = $this->scheduling->validate((int) $store->id, null, $mode, (string) $requested);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scheduled_at' => $e->getMessage()]);
        }

        if ($scheduledAt === null) {
            throw ValidationException::withMessages(['scheduled_at' => ___('Please pick a time slot.')]);
        }

        try {
            $lock = $this->scheduling->lockSlot((int) $store->id, null, $scheduledAt);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scheduled_at' => $e->getMessage()]);
        }

        return [$scheduledAt, $lock];
    }

    /**
     * P3-08: the shipping option of a ship checkout. A store that has set up shipping methods requires one of those
     * offered for the address (country, city, region, postal code fields); a store without any keeps the old ship
     * checkout (no fee, no dates).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveShipping(Store $store, string $mode, array $data, float $subtotal): ?ShippingOption
    {
        if ($mode !== 'ship' || ! ShippingMethod::query()->where('store_id', $store->id)->where('active', true)->exists()) {
            return null;
        }
        $methodId = trim((string) ($data['shipping_method'] ?? ''));
        if ($methodId === '') {
            throw ValidationException::withMessages(['shipping_method' => ___('Choose a shipping method.')]);
        }
        $address = array_filter([
            'country' => strtoupper(trim((string) ($data['country'] ?? ''))),
            'region' => trim((string) ($data['region'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'postal_code' => trim((string) ($data['postal_code'] ?? '')),
        ], fn ($v) => $v !== '');
        $currency = strtoupper(store_currency((int) $store->id));
        $option = $this->shipping->find($store, $methodId, $address, Money::minorFromMajor($subtotal, $currency));
        if ($option === null) {
            throw ValidationException::withMessages(['shipping_method' => ___('This shipping method is not available for this address.')]);
        }

        return $option;
    }

    /** F3: online payment needs the platform switch, the store's online payment and a store gateway for the total. */
    private function assertHostedAvailable(Store $store, float $total): void
    {
        if (! config('settings.admin_allow_online_payment')
            || ! StoreSettings::bool((int) $store->id, 'restaurant_online_payment')
            || $total <= 0
            || ! $this->hostedPayments->storeCanTake($store, $total)) {
            throw ValidationException::withMessages(['payment_mode' => ___('Online payment is not available for this order.')]);
        }
    }

    private function assertModeAllowed(Store $store, string $mode): void
    {
        if (! $this->fulfillment->isValid($mode)) {
            throw ValidationException::withMessages(['type' => ___('Unknown fulfillment method.')]);
        }
        if (! in_array($mode, $this->fulfillment->modesFor($store->store_type ?? null), true)) {
            throw ValidationException::withMessages(['type' => ___('This store does not offer that fulfillment method.')]);
        }
        if (! $this->settings->isEnabled($store, $mode)) {
            throw ValidationException::withMessages(['type' => ___('This fulfillment method is currently unavailable.')]);
        }
    }

    private function uniqueTrackToken(): string
    {
        do {
            $token = Str::lower(Str::random(32));
        } while (Order::where('track_token', $token)->exists());

        return $token;
    }
}
