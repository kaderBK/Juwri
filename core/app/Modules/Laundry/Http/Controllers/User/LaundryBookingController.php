<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Http\Controllers\User;

use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Laundry\Exceptions\LaundryHoldException;
use Juwri\Modules\Laundry\Services\Laundry\ServiceBookingService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Verticals\Http\Controllers\User\GoodsCartController;
use Juwri\Modules\Verticals\Http\Controllers\User\GoodsCheckoutController;

/**
 * rev/multi-vertical Lane D — D2: the storefront service booking flow.
 *
 * A self-contained page (one GET review/add + one POST place), the same
 * minimal-HTML shape Lane B's own storefront controllers use
 * ({@see GoodsCheckoutController}, {@see GoodsCartController} —
 * shop.goods.*), NOT the store's own `bookla` theme
 * (Lane C's file — this pack's hard rule is to consume that theme, never
 * fork/edit it). bookla already renders services + a qty/weight stepper +
 * slot picker (C3) but its "Book" button posts to the legacy
 * `restaurant.submitOrder` endpoint — a documented pre-existing integration
 * gap (see rev/multi-vertical/PROGRESS.md) that Lane V owns closing by
 * pointing bookla's JS at a native endpoint. This controller IS that native
 * endpoint for the laundry vertical: the full booking capability (choose
 * services by qty/weight, pick a slot, give a delivery/pickup address, see
 * the correct weight-aware total, and place the order) is reachable and
 * working end-to-end today — reachable directly, and pickupable by bookla's
 * JS with a one-line change to the fetch target once Lane V does that wiring.
 */
class LaundryBookingController extends Controller
{
    private function store(string $slug): Store
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        abort_unless(app(VerticalRegistry::class)->has($store->store_type ?? null, 'service'), 404);

        return $store;
    }

    public function show(
        string $slug,
        Request $request,
        ServiceBookingService $booking,
        FulfillmentSettingsService $settings,
        FulfillmentRegistry $registry,
        SchedulingService $scheduling,
        VerticalVocab $vocab
    ): View {
        $store = $this->store($slug);
        $token = $booking->cartToken($request);
        $modes = $settings->offeredModes($store);
        $slotMode = collect($modes)->first(fn ($m) => $registry->needsSlot($m));
        $today = Carbon::now($scheduling->timezone((int) $store->id))->format('Y-m-d');

        $services = Menu::where('store_id', $store->id)
            ->where('active', '1') // menu.active is enum('0','1'); int 1 matches the wrong enum index → no rows ([mysql-enum-active-index-trap])
            ->orderBy('category_id')->orderBy('position')
            ->get();

        return view('shop.laundry.book', [
            'store' => $store,
            'services' => $services,
            'booking' => $booking,
            'lines' => $booking->lines($store, $token),
            'total' => $booking->total($store, $token),
            'modes' => $modes,
            'registry' => $registry,
            'slotMode' => $slotMode,
            'slots' => $slotMode ? $scheduling->slotsFor((int) $store->id, null, $slotMode, $today) : [],
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    /**
     * rev/multi-vertical Lane V (integration fix 1) — the `bookla` theme's
     * service list calls this via fetch with `Accept: application/json`
     * (real per-kg/per-garment booking lines) instead of the legacy
     * `restaurant.submitOrder`, which rounded a fractional kg quantity to an
     * integer before it ever reached this vertical's own pricing math. The
     * bare-HTML `laundry.book` form keeps its original redirect-back flow.
     */
    public function addLine(string $slug, Menu $menu, Request $request, ServiceBookingService $booking): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        abort_unless((int) $menu->store_id === (int) $store->id, 404);

        $data = $request->validate([
            'variation' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:999'],
        ]);

        $amount = (float) ($data['quantity'] ?? 1);

        try {
            $line = $booking->addLine($store, $booking->cartToken($request), (int) $menu->id, $data['variation'] ?? null, $amount);
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

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'line_id' => $line->id]);
        }

        return back()->with('success', ___('Added to your booking.'));
    }

    public function place(string $slug, Request $request, ServiceBookingService $booking): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        $mode = (string) $request->input('type');
        $holdId = (string) $request->input('hold', '');

        try {
            // J27-A2: optional client order key (UUID) — a resend returns the same booking, another cart is a 409.
            $clientOrderId = ClientOrderId::fromRequest($request);
            // P3-11 (API-23): a booking made with a slot hold (the `book` theme): the held pickup time is the order's slot
            $hold = $holdId !== '' ? ['hold' => $holdId, 'hold_token' => (string) $request->session()->get('laundry.holds.'.$holdId, '')] : [];
            $order = $booking->book($store, $booking->cartToken($request), $mode, $hold + ['client_order_id' => $clientOrderId] + $request->only([
                'address', 'phone_number', 'customer_name', 'scheduled_at',
                // Discounts — settled server-side against the weight-corrected
                // subtotal in ServiceBookingService::book (never a client amount).
                'coupon_code', 'gift_card_code', 'loyalty_redeem',
            ]));
        } catch (ClientOrderConflict $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'conflict' => true, 'message' => $e->getMessage()], 409);
            }

            return back()->withErrors(['cart' => $e->getMessage()])->withInput();
        } catch (LaundryHoldException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error_code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
            }

            // a lost hold sends the customer back to choose the times again; any other refusal keeps the page
            return $e->errorCode === 'hold_expired' || $e->errorCode === 'hold_not_found'
                ? redirect()->route('publicView', ['slug' => $store->slug])->withErrors(['hold' => $e->getMessage()])
                : back()->withErrors(['hold' => $e->getMessage()])->withInput();
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

        if ($holdId !== '') {
            $request->session()->forget('laundry.holds.'.$holdId);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'track_token' => $order->track_token,
                'redirect' => route('store.orderTrack', $order->track_token),
            ]);
        }

        return redirect()->route('store.orderTrack', $order->track_token)
            ->with('success', ___('Booking confirmed.'));
    }
}
