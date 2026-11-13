<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\GuestRoomCharge;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Contracts\Shipping;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsOrderService;

/**
 * rev/multi-vertical B2/B3 — the storefront checkout for goods/service
 * verticals: address collection when the chosen mode needs one (ship),
 * a slot picker when it needs one (pickup_slot/appointment), reusing
 * SchedulingService end to end (B3) exactly as it reuses the checkout's
 * own cart (B6) and order-money derivation (theme-totals-contract).
 */
class GoodsCheckoutController extends Controller
{
    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    public function show(
        string $slug,
        Request $request,
        GoodsCartService $cart,
        FulfillmentSettingsService $settings,
        FulfillmentRegistry $registry,
        SchedulingService $scheduling
    ): View {
        $store = $this->store($slug);
        $token = $cart->token($request);
        $modes = $settings->offeredModes($store);

        $slotMode = collect($modes)->first(fn ($m) => $registry->needsSlot($m));
        $today = Carbon::now($scheduling->timezone((int) $store->id))->format('Y-m-d');

        $total = $cart->total($store, $token);

        return view('shop.goods.checkout', [
            'store' => $store,
            'lines' => $cart->lines($store, $token),
            'total' => $total,
            // P3-08: the store's shipping methods (the server checks the address's zone on submit) and online payment (F3)
            'shippingMethods' => in_array('ship', $modes, true)
                ? ShippingMethod::query()->where('store_id', $store->id)->where('active', true)->orderBy('position')->orderBy('id')->get()
                : collect(),
            'onlinePayment' => config('settings.admin_allow_online_payment') && StoreSettings::bool((int) $store->id, 'restaurant_online_payment')
                && $total > 0 && app(HostedPayments::class)->storeCanTake($store, (float) $total),
            'modes' => $modes,
            'registry' => $registry,
            'slotMode' => $slotMode,
            'slots' => $slotMode ? $scheduling->slotsFor((int) $store->id, null, $slotMode, $today) : [],
        ]);
    }

    /**
     * P3-08: POST {slug}/shipping/quote — the shipping methods for the cart and an address (country / region / city /
     * postal code): fee in minor units of the store currency (0 from the method's free threshold), the delivery
     * window and the cut-off. The checkout prices the chosen method again on submit; this is the display.
     */
    public function shippingQuote(string $slug, Request $request, GoodsCartService $cart, Shipping $shipping): JsonResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ]);
        $address = array_filter(array_map(fn ($v) => trim((string) $v), $data), fn ($v) => $v !== '');
        $address['country'] = strtoupper($address['country']);
        $currency = strtoupper(store_currency((int) $store->id));
        $subtotal = Money::minorFromMajor((float) $cart->total($store, $cart->token($request)), $currency);
        $zone = $shipping->zoneFor($store, $address);
        $methods = [];
        foreach ($shipping->options($store, $address, $subtotal) as $option) {
            $methods[] = [
                'id' => $option->publicId(),
                'kind' => $option->method->kind,
                'name' => (string) $option->method->name,
                'carrier' => $option->method->carrier,
                'fee_minor' => $option->feeMinor,
                'currency' => $option->currency,
                'fee' => $option->feeMinor === 0 ? ___('Free') : store_price_symbol(Money::majorFromMinor($option->feeMinor, $option->currency), (int) $store->id),
                'free_by_threshold' => $option->freeByThreshold,
                'earliest' => $option->earliest->toDateString(),
                'latest' => $option->latest->toDateString(),
                'cutoff_at' => $option->cutoffAt?->toIso8601String(),
            ];
        }

        return response()->json(['zone' => $zone?->code, 'ships_to_address' => $zone !== null, 'methods' => $methods]);
    }

    /**
     * rev/multi-vertical Lane V (integration fix 1) — the `shelf` theme's
     * checkout sheet calls this via fetch with `Accept: application/json`
     * (real Order, real `type`, real totals) instead of the legacy
     * `restaurant.submitOrder`. The bare-HTML `goods.checkout` form (a normal
     * POST + redirect) keeps working exactly as before.
     */
    public function place(
        string $slug,
        Request $request,
        GoodsCartService $cart,
        GoodsOrderService $orders
    ): RedirectResponse|JsonResponse {
        $store = $this->store($slug);
        $token = $cart->token($request);
        $mode = (string) $request->input('type');

        // Charge-to-room (J57): decided BEFORE any order exists. Room by its QR key → verified room token (required by
        // default) → active stay → full guest name. Anything else is refused; there is no room-number, name-only or
        // "single checked-in stay" fallback any more.
        $chargeStay = null;
        if ($request->input('payment_method') === 'charge_to_room' && app(RoomCharge::class)->enabled($store->id)) {
            $chargeStay = GuestRoomCharge::stay(
                (int) $store->id,
                $request->input('room'),
                $request->input('charge_room_name', $request->input('customer_name'))
            );
            if ($chargeStay === null) {
                $message = ___('Please scan the QR code in your room to charge this order to your room.');
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $message, 'errors' => ['payment_method' => [$message]]], 422);
                }

                return back()->withErrors(['payment_method' => $message])->withInput();
            }
        }

        $replayed = false;
        try {
            // J27-A2: optional client order key (UUID) — a resend returns the same order, another cart is a 409.
            $clientOrderId = ClientOrderId::fromRequest($request);
            [$order, $replayed] = $orders->checkoutOrReplay($store, $token, $mode, ['client_order_id' => $clientOrderId] + $request->only([
                'address', 'phone_number', 'customer_name', 'scheduled_at', 'loyalty_redeem', 'coupon_code', 'gift_card_code',
                // P3-08: shipping method + structured address (zone match), online payment on the hosted page (F3)
                'shipping_method', 'country', 'region', 'city', 'postal_code', 'payment_mode',
            ]));
        } catch (ClientOrderConflict $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'conflict' => true, 'message' => $e->getMessage()], 409);
            }

            return back()->withErrors(['cart' => $e->getMessage()])->withInput();
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->validator->errors()->first(),
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        // P3-08 (F3): an order paid online goes to the core pay page (the store's own gateways) while its window is open.
        if ($order->payment_mode === 'hosted' && $order->status === 'unpaid') {
            $transaction = app(HostedPayments::class)->isOpen($order) ? app(HostedPayments::class)->transactionFor($order) : null;
            $payUrl = $transaction !== null ? route('payment.index', $transaction->getKey()) : null;
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => $payUrl !== null,
                    'track_token' => $order->track_token,
                    'payment_url' => $payUrl,
                    'redirect' => $payUrl ?? route('store.orderTrack', $order->track_token),
                ]);
            }

            return redirect()->to($payUrl ?? route('store.orderTrack', $order->track_token));
        }

        // Charge-to-room: post this order to the stay resolved above (chargeOrderForTender stamps
        // payment_method='charge_to_room' + posts). A replayed order (same client_order_id) was charged to the room
        // the first time — never post it twice.
        if (! $replayed && $chargeStay !== null) {
            try {
                app(RoomCharge::class)->chargeOrderForTender($order->fresh() ?? $order, $chargeStay);
                $order = $order->fresh('items') ?? $order;
            } catch (\Throwable $e) {
                // leave as a normal order; never fail checkout on a folio hiccup
            }
        }

        // P1-23 follow-up: a coupon / loyalty reward refused because the order holds medicines where the market
        // blocks promotions — tell the customer why.
        $promotionsNote = $orders->promotionsNote($order, trim((string) $request->input('coupon_code')) !== '', $request->boolean('loyalty_redeem'));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'track_token' => $order->track_token,
                'redirect' => route('store.orderTrack', $order->track_token),
                'charged_to_room' => $order->payment_method === 'charge_to_room',
                'promotions_restricted' => $promotionsNote !== null,
                'promotions_message' => $promotionsNote,
            ]);
        }

        $redirect = redirect()->route('store.orderTrack', $order->track_token)
            ->with('success', ___('Order placed.'));

        return $promotionsNote !== null ? $redirect->with('warning', $promotionsNote) : $redirect;
    }
}
