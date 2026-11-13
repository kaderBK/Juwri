<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use App\Models\StoreTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;
use Juwri\Modules\Hotel\Services\Hotel\HousekeepingService;
use Juwri\Modules\Hotel\Services\Hotel\RateService;
use Juwri\Modules\Hotel\Services\Hotel\RoomUnavailableException;

/**
 * HPMS A5/A6 — owner-facing booking-engine endpoints (availability, bookings
 * list, lifecycle: store / update / confirm / cancel / no-show / check-in).
 * Scoped to the dashboard's current restaurant with hotel_mode on; every money-
 * adjacent POST honours an `idempotency_key` (request-level replay guard for the
 * booking row; the folio ledger's own (account,ref) unique guards check-in).
 * GET screens render lane-B blades when present and fall back to JSON in this
 * lane's worktree (cross-lane surface guard).
 */
class HotelBookingController extends Controller
{
    /** Resolve + authorize the dashboard's current restaurant with hotel_mode on. */
    protected function hotelRestaurant(): Store
    {
        $store = dashboard_current_store();
        abort_if(! $store, 404);
        abort_unless(HotelService::enabled($store->id), 403, 'Hotel mode is not enabled.');

        return $store;
    }

    /** Load a booking that belongs to the current restaurant, or 404. */
    protected function bookingFor(Store $store, int|string $id): HotelBooking
    {
        $booking = HotelBooking::where('id', $id)->where('store_id', $store->id)->first();
        abort_if(! $booking, 404);

        return $booking;
    }

    /** Normalise the booking response contract (§5): {id, code, status, quoted_total, currency}. */
    protected function bookingJson(HotelBooking $booking, int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'id' => (int) $booking->id,
            'code' => $booking->code,
            'status' => $booking->status,
            'quoted_total' => $booking->quoted_total !== null ? (float) $booking->quoted_total : null,
            'currency' => $booking->currency,
        ], $httpStatus);
    }

    /**
     * A5 — room-type availability + nightly quote for a date range.
     * Response (§5): {types: [{id, name, base_rate, available, nightly_total, nights}], currency}.
     */
    public function availability(Request $request): JsonResponse
    {
        $store = $this->hotelRestaurant();
        $data = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'room_type_id' => ['nullable', 'integer'],
        ]);

        $in = Carbon::parse($data['check_in'])->toDateString();
        $out = Carbon::parse($data['check_out'])->toDateString();
        $typeId = ! empty($data['room_type_id']) ? (int) $data['room_type_id'] : null;

        $avail = BookingService::typeAvailability((int) $store->id, $in, $out, $typeId);

        $typesQuery = RoomType::forRestaurant((int) $store->id);
        if ($typeId) {
            $typesQuery->where('id', $typeId);
        } else {
            $typesQuery->where('active', true);
        }
        $types = $typesQuery->orderBy('sort')->get();

        $plan = RateService::ensureDefaultPlan((int) $store->id);
        $currency = HotelFolioService::currencyFor((int) $store->id);

        $rows = [];
        foreach ($types as $type) {
            $quote = RateService::quote($type, $plan, $in, $out);
            $rows[] = [
                'id' => (int) $type->id,
                'name' => $type->name,
                'base_rate' => (float) $type->base_rate,
                'available' => (int) ($avail[(int) $type->id] ?? 0),
                'nightly_total' => $quote['total'],
                'nights' => count($quote['nights']),
            ];
        }

        return response()->json(['types' => $rows, 'currency' => $currency]);
    }

    /** A5 — bookings list (renders lane-B blade when present, else JSON). */
    public function index(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();

        $status = (string) $request->query('status', '');
        $filterCheckIn = (string) $request->query('check_in', '');
        $filterTypeId = (string) $request->query('room_type_id', '');

        $query = HotelBooking::forRestaurant((int) $store->id)->with(['roomType', 'room']);
        $today = now()->toDateString();
        switch ($status) {
            case 'upcoming':
                $query->whereIn('status', HotelBooking::BLOCKING_STATUSES)->whereDate('check_in', '>=', $today);
                break;
            case 'past':
                $query->whereDate('check_out', '<', $today);
                break;
            case 'pending':
            case 'confirmed':
            case 'checked_in':
                $query->where('status', $status);
                break;
        }
        if ($filterCheckIn !== '') {
            $query->whereDate('check_in', Carbon::parse($filterCheckIn)->toDateString());
        }
        if ($filterTypeId !== '') {
            $query->where('room_type_id', (int) $filterTypeId);
        }
        $bookings = $query->orderByDesc('check_in')->limit(200)->get();

        $roomTypes = RoomType::forRestaurant((int) $store->id)->where('active', true)->orderBy('sort')->orderBy('id')->get();
        $ratePlans = RatePlan::forRestaurant((int) $store->id)->orderBy('id')->get();
        $rooms = StoreTable::where('store_id', $store->id)->where('area_type', 'room')->orderBy('table_no')->get();
        $currency = HotelFolioService::currencyFor((int) $store->id);

        $view = 'owner.hotel.bookings';
        if (! $request->expectsJson() && View::exists($view)) {
            return view($view, compact('store', 'bookings', 'roomTypes', 'ratePlans', 'rooms', 'currency', 'status', 'filterCheckIn', 'filterTypeId'));
        }

        return response()->json(['success' => true, 'bookings' => $bookings]);
    }

    /** A5 — a single booking (renders lane-B blade when present, else JSON). */
    public function show(Request $request, string $booking): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        $model = $this->bookingFor($store, $booking);
        $model->loadMissing(['roomType', 'ratePlan', 'room', 'nights', 'stay']);

        $roomTypes = RoomType::forRestaurant((int) $store->id)->where('active', true)->orderBy('sort')->orderBy('id')->get();
        $ratePlans = RatePlan::forRestaurant((int) $store->id)->orderBy('id')->get();
        $rooms = StoreTable::where('store_id', $store->id)->where('area_type', 'room')->orderBy('table_no')->get();
        $currency = HotelFolioService::currencyFor((int) $store->id);

        $view = 'owner.hotel.booking-show';
        if (! $request->expectsJson() && View::exists($view)) {
            return view($view, ['store' => $store, 'booking' => $model, 'roomTypes' => $roomTypes, 'ratePlans' => $ratePlans, 'rooms' => $rooms, 'currency' => $currency]);
        }

        return response()->json(['success' => true, 'booking' => $model]);
    }

    /**
     * A5 — occupancy calendar grid. Builds the lane-B blade contract: a 14-night
     * window ($days), the store's rooms ($rooms, grouped by type in the blade), and
     * $occupancy[room_id][Y-m-d] => null | ['type'=>'ooo'] | ['type'=>'booked', ...].
     * `?start=Y-m-d` drives the window (prev/next nav); defaults to today.
     */
    public function calendar(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();

        $start = $request->query('start');
        $windowStart = is_string($start) && $start !== '' ? Carbon::parse($start)->toDateString() : Carbon::now()->toDateString();
        $span = 14;
        $days = [];
        for ($i = 0; $i < $span; $i++) {
            $days[] = Carbon::parse($windowStart)->addDays($i)->toDateString();
        }
        $windowEnd = $days[$span - 1];

        // Grid rows: the store's rooms with their room-type label.
        $rooms = StoreTable::where('store_id', $store->id)
            ->where('area_type', 'room')
            ->with('roomType')
            ->orderBy('table_no')
            ->get();
        foreach ($rooms as $room) {
            $room->setAttribute('room_type_name', $room->roomType?->getAttribute('name'));
        }
        $roomIds = $rooms->pluck('id')->all();

        // Occupancy: out-of-order markers first, then booked nights (booked wins).
        $occupancy = [];
        foreach ($rooms as $room) {
            if (in_array($room->oo_status, ['out_of_order', 'out_of_service'], true)) {
                $until = $room->oo_until ? Carbon::parse($room->oo_until)->toDateString() : null;
                foreach ($days as $d) {
                    if ($until === null || $d <= $until) {
                        $occupancy[$room->id][$d] = ['type' => 'ooo'];
                    }
                }
            }
        }

        if ($roomIds) {
            $nights = HotelBookingNight::whereIn('room_id', $roomIds)
                ->whereBetween('night', [$windowStart, $windowEnd])
                ->with('booking')
                ->get();
            foreach ($nights as $n) {
                $b = $n->booking;
                if (! $b || ! in_array($b->status, HotelBooking::BLOCKING_STATUSES, true)) {
                    continue;
                }
                $occupancy[$n->room_id][Carbon::parse($n->night)->toDateString()] = [
                    'type' => 'booked',
                    'status' => $b->status,
                    'booking_id' => (int) $b->id,
                    'code' => $b->code,
                    'guest' => $b->guest_name ?? '',
                ];
            }
        }

        $view = 'owner.hotel.calendar';
        if (! $request->expectsJson() && View::exists($view)) {
            return view($view, compact('store', 'rooms', 'days', 'occupancy', 'windowStart'));
        }

        return response()->json([
            'success' => true,
            'windowStart' => $windowStart,
            'days' => $days,
            'rooms' => $rooms,
            'occupancy' => $occupancy,
        ]);
    }

    /**
     * A5 — create a confirmed desk booking (holds a room immediately). Pending
     * bookings come from the online request flow (lane C); this desk endpoint
     * confirms a room. `idempotency_key` de-dupes a double-submitted form.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $this->validateBooking($request, true);

        // Request-level replay guard: a re-submitted form with the same key returns
        // the original booking rather than creating a duplicate reservation.
        $cacheKey = null;
        if (! empty($data['idempotency_key'])) {
            $cacheKey = 'hotel_booking:idem:'.$store->id.':'.$data['idempotency_key'];
            $existingId = Cache::get($cacheKey);
            if ($existingId) {
                $existing = HotelBooking::where('id', $existingId)->where('store_id', $store->id)->first();
                if ($existing) {
                    return $this->respondBooking($request, $existing);
                }
            }
        }

        try {
            $booking = BookingService::create((int) $store->id, $data, HotelBooking::STATUS_CONFIRMED, HotelBooking::SOURCE_DESK);
        } catch (RoomUnavailableException $e) {
            return $this->respondError($request, $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        if ($cacheKey) {
            Cache::put($cacheKey, $booking->id, now()->addHours(6));
        }

        return $this->respondBooking($request, $booking, ___('Booking created.'));
    }

    /** A5 — modify a booking's dates / room / occupancy (re-quotes, re-writes nights). */
    public function update(Request $request, string $booking): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->bookingFor($store, $booking);
        $data = $this->validateBooking($request, false);

        try {
            $model = BookingService::modify($model, $data);
        } catch (RoomUnavailableException $e) {
            return $this->respondError($request, $e->getMessage(), 409);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        return $this->respondBooking($request, $model, ___('Booking updated.'));
    }

    /** A5 — confirm a pending booking (assign a room, write nights). */
    public function confirm(Request $request, string $booking): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->bookingFor($store, $booking);
        $data = $request->validate([
            'room_id' => ['nullable', 'integer', $this->ownRoom((int) $store->id)], // P6-59b
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        try {
            $model = BookingService::confirm($model, ! empty($data['room_id']) ? (int) $data['room_id'] : null);
        } catch (RoomUnavailableException $e) {
            return $this->respondError($request, $e->getMessage(), 409);
        }

        return $this->respondBooking($request, $model, ___('Booking confirmed.'));
    }

    /** A5 — cancel a booking (frees its nights). */
    public function cancel(Request $request, string $booking): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->bookingFor($store, $booking);

        try {
            $model = BookingService::cancel($model);
        } catch (\RuntimeException $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        return $this->respondBooking($request, $model, ___('Booking cancelled.'));
    }

    /** A5 — mark a booking a no-show (frees its nights). */
    public function noShow(Request $request, string $booking): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->bookingFor($store, $booking);

        try {
            $model = BookingService::markNoShow($model);
        } catch (\RuntimeException $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        return $this->respondBooking($request, $model, ___('Booking marked as no-show.'));
    }

    /**
     * A6 — check a booking's guest in: assign the room (race guard), open the stay
     * + folio, post the nightly room charges, link the booking. The response
     * carries `hk_warning` (contract for lane B) — a non-blocking warning when the
     * room is not clean/inspected (A9 refines the housekeeping wiring).
     */
    public function checkIn(Request $request, string $booking): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->bookingFor($store, $booking);
        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        try {
            $stay = BookingService::checkIn($model, ! empty($data['room_id']) ? (int) $data['room_id'] : null);
        } catch (RoomUnavailableException $e) {
            return $this->respondError($request, $e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        $room = StoreTable::find($stay->table_id);
        $hkWarning = HousekeepingService::checkInWarning($room);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'stay_id' => (int) $stay->id,
                'booking' => ['id' => (int) $model->id, 'code' => $model->fresh()->code, 'status' => 'checked_in'],
                'hk_warning' => $hkWarning,
            ]);
        }

        if ($hkWarning) {
            app_alert_error($hkWarning);
        }
        app_alert_success(___('Guest checked in.'));

        return redirect()->route('hotel.stays.show', $stay->id);
    }

    /**
     * A7 — post an extra-service charge onto a stay's folio. Fields (§5):
     * service_id, qty, idempotency_key. The stay and the service must both belong
     * to the current restaurant (cross-tenant → 404).
     */
    public function postService(Request $request, string $stay): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();

        $stayModel = HotelStay::where('id', $stay)->where('store_id', $store->id)->first();
        abort_if(! $stayModel, 404);

        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'qty' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        $service = HotelExtraService::where('id', $data['service_id'])
            ->where('store_id', $store->id)->first();
        abort_if(! $service, 404);

        try {
            $res = HotelFolioService::postServiceCharge(
                $stayModel,
                $service,
                (float) ($data['qty'] ?? 1),
                $data['idempotency_key'] ?? null,
                auth()->id()
            );
        } catch (\Throwable $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'amount' => $res['amount'],
                'units' => $res['units'],
                'balance' => HotelFolioService::balance($stayModel),
            ]);
        }
        app_alert_success(___('Charge added to the room folio.'));

        return back();
    }

    /**
     * A8 — settle a standalone event's outstanding balance (mirrors folio settle;
     * ref `hotel_event:settle:{uuid}`). Fields: amount, method, idempotency_key.
     */
    public function settleEvent(Request $request, string $event): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = HotelEvent::where('id', $event)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        try {
            HotelFolioService::settleEvent($model, (float) $data['amount'], $data['method'] ?? 'cash', $data['idempotency_key'] ?? null, auth()->id());
        } catch (\Throwable $e) {
            return $this->respondError($request, $e->getMessage(), 422);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'balance' => HotelFolioService::eventBalance($model)]);
        }
        app_alert_success(___('Event payment recorded.'));

        return back();
    }

    /** P6-59b: a posted room must be one of THIS store's rooms — another tenant's room id is a 422 (it would otherwise hold that room's nights). */
    private function ownRoom(int $storeId): Exists
    {
        return Rule::exists('store_tables', 'id')->where('store_id', $storeId);
    }

    /**
     * Shared booking-field validation (§5 field names). $requireCore is true for
     * store (all core fields required), false for update (all optional).
     *
     * @return array<string, mixed>
     */
    protected function validateBooking(Request $request, bool $requireCore): array
    {
        $req = $requireCore ? 'required' : 'nullable';
        $storeId = (int) $this->hotelRestaurant()->id;

        return $request->validate([
            'guest_name' => [$requireCore ? 'required' : 'nullable', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'room_type_id' => [$req, 'integer'],
            'rate_plan_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer', $this->ownRoom($storeId)], // P6-59b
            'check_in' => [$req, 'date'],
            'check_out' => [$req, 'date', 'after:check_in'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:30'],
            'children' => ['nullable', 'integer', 'min:0', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
    }

    /** JSON for AJAX callers; a redirect back with a flash for form posts. */
    protected function respondBooking(Request $request, HotelBooking $booking, ?string $flash = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return $this->bookingJson($booking);
        }
        if ($flash) {
            app_alert_success($flash);
        }

        return back();
    }

    protected function respondError(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }
        app_alert_error($message);

        return back();
    }
}
