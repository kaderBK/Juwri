<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use App\Models\StoreTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;

/**
 * FEATURES-RUN-4 T1 / H6 — owner-facing charge-to-room folio endpoints
 * (check-in, settle, statement, check-out). All scoped to the dashboard's
 * current restaurant; a stay is only actionable if it belongs to that store and
 * hotel_mode is on. The guest-facing charge-to-room tender UI is H8; posting an
 * order to a folio goes through {@see HotelFolioService::chargeOrder} from the
 * order flow, not this controller.
 */
class HotelFolioController extends Controller
{
    /** Resolve + authorize the dashboard's current restaurant with hotel_mode on. */
    protected function hotelRestaurant()
    {
        $store = dashboard_current_store();
        abort_if(! $store, 404);
        abort_unless(HotelService::enabled($store->id), 403, 'Hotel mode is not enabled.');

        return $store;
    }

    /**
     * Wind-down resolver for the DRAIN actions (view stays/statement, settle,
     * check-out). Allows through when the module is live OR — after the owner turned
     * hotel_mode off / the plan lost the `hotel` entitlement mid-stay — while a stay
     * is still checked in, so open folios can always be settled and closed. Strict
     * actions (check-in, manual adjust, stay edit/cancel) keep {@see hotelRestaurant}.
     */
    protected function hotelRestaurantForWindDown(): Store
    {
        $store = dashboard_current_store();
        abort_if(! $store, 404);
        abort_unless(
            HotelService::enabled($store->id) || HotelService::canWindDown($store->id),
            403,
            'Hotel mode is not enabled.'
        );

        return $store;
    }

    /** Load a stay that belongs to the current restaurant, or 404. */
    protected function stayFor($store, $stayId): HotelStay
    {
        $stay = HotelStay::where('id', $stayId)->where('store_id', $store->id)->first();
        abort_if(! $stay, 404);

        return $stay;
    }

    /**
     * H7 — Stays & folio admin. Lists active + recent past stays (each with its
     * live folio balance) and the rooms available for a new check-in.
     */
    public function index(Request $request)
    {
        $store = $this->hotelRestaurantForWindDown();

        $active = HotelStay::where('store_id', $store->id)
            ->where('status', 'checked_in')
            ->with('room')
            ->orderByDesc('checked_in_at')
            ->get();

        $past = HotelStay::where('store_id', $store->id)
            ->where('status', '!=', 'checked_in')
            ->with('room')
            ->orderByDesc('checked_out_at')
            ->limit(50)
            ->get();

        $balances = [];
        foreach ($active->merge($past) as $stay) {
            $balances[$stay->id] = HotelFolioService::balance($stay);
        }

        // Rooms with no active stay are available for check-in.
        $occupied = $active->pluck('table_id')->all();
        $rooms = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->orderByRaw("COALESCE(floor_label, '')")
            ->orderBy('table_no')
            ->get()
            ->map(function ($r) use ($occupied) {
                $r->is_occupied = in_array($r->id, $occupied, true);

                return $r;
            });

        $store = $store;

        return view('owner.hotel.stays', compact('store', 'active', 'past', 'rooms', 'balances'));
    }

    /** H7 — Full folio statement page for one stay (settle / check-out / print). */
    public function show(Request $request, $stay)
    {
        $store = $this->hotelRestaurantForWindDown();
        $stay = $this->stayFor($store, $stay);
        $statement = HotelFolioService::statement($stay);
        $stay->loadMissing('room');
        $store = $store;

        // HPMS — active extra services the folio can post to this stay (lane-B UI
        // posts to hotel.pms.services.post). Empty collection until the owner
        // defines services, so the existing statement page is unaffected.
        $services = HotelExtraService::forRestaurant((int) $store->id)
            ->where('active', true)->orderBy('name')->get();

        return view('owner.hotel.folio-statement', compact('store', 'stay', 'statement', 'services'));
    }

    /** H7 — Printable folio statement (own minimal layout). */
    public function print(Request $request, $stay)
    {
        $store = $this->hotelRestaurantForWindDown();
        $stay = $this->stayFor($store, $stay);
        $statement = HotelFolioService::statement($stay);
        $stay->loadMissing('room');
        $store = $store;

        return view('owner.hotel.folio-print', compact('store', 'stay', 'statement'));
    }

    /** Check a guest into a room, opening its folio. */
    public function checkIn(Request $request)
    {
        $store = $this->hotelRestaurant();
        $data = $request->validate([
            'table_id' => ['required', 'integer'],
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $room = StoreTable::where('id', $data['table_id'])
            ->where('store_id', $store->id)->first();
        abort_if(! $room, 404, 'Room not found.');

        if (HotelFolioService::activeStayForRoom($store->id, $room->id)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'That room already has an active stay.'], 422);
            }
            app_alert_error(___('That room already has an active stay.'));

            return back();
        }

        $stay = HotelFolioService::checkIn(
            $store->id,
            $room,
            $data['guest_name'],
            $data['guest_phone'] ?? null,
            dashboard_current_branch_id()
        );

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'stay_id' => $stay->id, 'folio_account_id' => $stay->folio_account_id]);
        }

        app_alert_success(___('Guest checked in.'));

        return redirect()->route('hotel.stays.show', $stay->id);
    }

    /** Folio statement for a stay (charges, payments, balance). */
    public function statement(Request $request, $stay)
    {
        $store = $this->hotelRestaurantForWindDown();
        $stay = $this->stayFor($store, $stay);

        return response()->json(['success' => true, 'statement' => HotelFolioService::statement($stay)]);
    }

    /** Record a (possibly partial) settlement against a folio. */
    public function settle(Request $request, $stay)
    {
        $store = $this->hotelRestaurantForWindDown();
        $stay = $this->stayFor($store, $stay);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:32'],
            // A4: a per-form-render idempotency key (the settle form embeds a hidden
            // uuid). A double-submitted form (double-click, back-nav re-post) reuses
            // the same key, so the ledger's unique (account,ref) records the payment
            // exactly ONCE instead of twice. Absent (legacy/API callers) → the service
            // auto-generates one, preserving the old non-idempotent behaviour only for
            // callers that don't send it.
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        try {
            HotelFolioService::settle($stay, (float) $data['amount'], $data['method'] ?? 'cash', $data['idempotency_key'] ?? null, auth()->id());
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            app_alert_error($e->getMessage());

            return back();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'balance' => HotelFolioService::balance($stay),
            ]);
        }

        app_alert_success(___('Payment recorded.'));

        return back();
    }

    /**
     * A5 — post a manual folio adjustment (a signed miscellaneous charge or a
     * credit/void). Owner/staff-gated via the `hotel_folio` permission on the route.
     * A negative amount larger than the balance is rejected by the ledger's
     * non-negative guard (surfaced as a 422). Activity-logged with the reason.
     */
    public function adjust(Request $request, string $stay): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $stay = $this->stayFor($store, $stay);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:200'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        try {
            HotelFolioService::adjust($stay, (float) $data['amount'], $data['reason'], auth()->id(), $data['idempotency_key'] ?? null);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            app_alert_error($e->getMessage());

            return back();
        }

        log_activity('hotel.folio.adjust', $stay, [
            'stay_id' => $stay->id, 'amount' => (float) $data['amount'], 'reason' => $data['reason'],
        ], $store->id);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'balance' => HotelFolioService::balance($stay)]);
        }

        app_alert_success(___('Adjustment recorded.'));

        return back();
    }

    /**
     * A11 — correct a stay's guest name / phone (re-authorizes charge-to-room name
     * matching). Owner/staff-gated via the `hotel_folio` route permission.
     */
    public function stayUpdate(Request $request, string $stay): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $stay = $this->stayFor($store, $stay);

        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $stay = HotelFolioService::updateGuest($stay, $data['guest_name'], $data['guest_phone'] ?? null);
        log_activity('hotel.stay.update', $stay, ['stay_id' => $stay->id], $store->id);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => ___('Guest details updated. Charge-to-room now matches the corrected name.'),
            ]);
        }

        app_alert_success(___('Guest details updated.'));

        return back();
    }

    /**
     * A11 — cancel a mistaken stay (zero balance only). Frees the room.
     */
    public function stayCancel(Request $request, string $stay): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $stay = $this->stayFor($store, $stay);

        try {
            $stay = HotelFolioService::cancelStay($stay);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            app_alert_error($e->getMessage());

            return back();
        }

        log_activity('hotel.stay.cancel', $stay, ['stay_id' => $stay->id], $store->id);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'status' => $stay->status]);
        }

        app_alert_success(___('Stay cancelled.'));

        return redirect()->route('hotel.stays');
    }

    /** Check a guest out (requires zero balance unless explicitly writing off). */
    public function checkOut(Request $request, $stay)
    {
        $store = $this->hotelRestaurantForWindDown();
        $stay = $this->stayFor($store, $stay);

        $writeOff = $request->boolean('write_off');

        try {
            $stay = HotelFolioService::checkOut($stay, $writeOff, auth()->id());
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            app_alert_error($e->getMessage());

            return back();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'status' => $stay->status,
                'statement' => HotelFolioService::statement($stay),
            ]);
        }

        app_alert_success(___('Guest checked out.'));

        return redirect()->route('hotel.stays');
    }
}
