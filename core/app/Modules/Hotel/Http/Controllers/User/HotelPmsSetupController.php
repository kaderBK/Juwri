<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use App\Models\StoreTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelEventItem;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\HousekeepingTask;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomRate;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\EventService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;
use Juwri\Modules\Hotel\Services\Hotel\HousekeepingService;
use Juwri\Modules\Hotel\Services\Hotel\RateService;

/**
 * HPMS A9/A11 — PMS setup + operations controller (housekeeping board, out-of-
 * order marking, and the inventory/rate/service/event CRUD added in A11). Scoped
 * to the dashboard's current restaurant with hotel_mode on; per-route staff
 * permissions are enforced by EnsureStaffDashboardPermission (housekeeping →
 * hotel_requests; rooms.oo / room-types / rate-plans / rates / services / events
 * → hotel_admin). GET screens render lane-B blades when present, else JSON.
 */
class HotelPmsSetupController extends Controller
{
    /** Resolve + authorize the dashboard's current restaurant with hotel_mode on. */
    protected function hotelRestaurant(): Store
    {
        $store = dashboard_current_store();
        abort_if(! $store, 404);
        abort_unless(HotelService::enabled($store->id), 403, 'Hotel mode is not enabled.');

        return $store;
    }

    /** A9 — housekeeping board: rooms grouped by cleanliness + open tasks. */
    public function housekeeping(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        $board = HousekeepingService::board((int) $store->id);
        // The blade takes a flat $rooms list and regroups by hk_status itself.
        $rooms = collect($board['grouped']['dirty'] ?? [])
            ->concat($board['grouped']['clean'] ?? [])
            ->concat($board['grouped']['inspected'] ?? []);

        $store = $store;
        $view = 'owner.hotel.housekeeping';
        if (! $request->expectsJson() && View::exists($view)) {
            return view($view, ['store' => $store, 'rooms' => $rooms, 'board' => $board]);
        }

        return response()->json(['success' => true, 'board' => $board]);
    }

    /**
     * A9 — open a new housekeeping task, or complete an existing one. Fields (§5):
     * room_id, type, status, task_id (for done), notes.
     */
    public function housekeepingTask(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:clean,inspect,maintenance'],
            'status' => ['nullable', 'in:open,done'],
            'task_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Complete an existing task.
        if (! empty($data['task_id'])) {
            $task = HousekeepingTask::where('id', $data['task_id'])->where('store_id', $store->id)->first();
            abort_if(! $task, 404);
            if (($data['status'] ?? 'done') === 'done') {
                HousekeepingService::completeTask($task, auth()->id());
            }

            return $this->respond($request, ___('Housekeeping task updated.'));
        }

        // Open a new task — requires a room of this store.
        abort_if(empty($data['room_id']), 422, 'A room is required.');
        $room = StoreTable::where('id', $data['room_id'])->where('store_id', $store->id)->first();
        abort_if(! $room, 404);
        HousekeepingService::openTask((int) $store->id, (int) $room->id, $data['type'] ?? 'clean', $data['notes'] ?? null);

        return $this->respond($request, ___('Housekeeping task created.'));
    }

    /** A9 — set a room's out-of-service marker. Fields (§5): oo_status, oo_until. */
    public function roomOo(Request $request, string $room): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $roomModel = StoreTable::where('id', $room)->where('store_id', $store->id)->where('area_type', 'room')->first();
        abort_if(! $roomModel, 404);

        $data = $request->validate([
            'oo_status' => ['required', 'in:none,out_of_service,out_of_order'],
            'oo_until' => ['nullable', 'date'],
        ]);

        try {
            HousekeepingService::setOutOfOrder($roomModel, $data['oo_status'], $data['oo_until'] ?? null);
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            app_alert_error($e->getMessage());

            return back();
        }

        return $this->respond($request, ___('Room status updated.'));
    }

    // ── A11: room types ──────────────────────────────────────────────────────

    /** A11 — room-types list (renders lane-B blade when present, else JSON). */
    public function types(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        // The room-types blade renders room types AND rate plans on one page; it
        // reads $roomTypes/$ratePlans. Ensure the auto "Standard" plan exists so the
        // plans section is never empty on a fresh store.
        RateService::ensureDefaultPlan((int) $store->id);
        $roomTypes = RoomType::forRestaurant((int) $store->id)->withCount('rooms')->orderBy('sort')->orderBy('id')->get();
        $ratePlans = RatePlan::forRestaurant((int) $store->id)->orderBy('id')->get();

        return $this->screen($request, 'room-types', ['store' => $store, 'roomTypes' => $roomTypes, 'ratePlans' => $ratePlans], ['types' => $roomTypes, 'plans' => $ratePlans]);
    }

    /** A11 — create a room type. */
    public function typesStore(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $this->validateType($request);
        $data['store_id'] = (int) $store->id;
        $type = RoomType::create($data);

        return $this->respondData($request, ['id' => (int) $type->id], ___('Room type created.'));
    }

    /** A11 — update a room type. */
    public function typesUpdate(Request $request, string $type): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = RoomType::where('id', $type)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);
        $model->update($this->validateType($request));

        return $this->respondData($request, ['id' => (int) $model->id], ___('Room type updated.'));
    }

    /**
     * A11 — delete a room type. Guarded: refused while any room is still assigned
     * to it or an active/upcoming booking references it (blocking statuses).
     */
    public function typesDelete(Request $request, string $type): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = RoomType::where('id', $type)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);

        if ($model->rooms()->exists()) {
            return $this->respondError($request, ___('Unassign its rooms before deleting this room type.'));
        }
        if (HotelBooking::where('room_type_id', $model->id)
            ->whereIn('status', HotelBooking::BLOCKING_STATUSES)->exists()) {
            return $this->respondError($request, ___('This room type has active bookings and cannot be deleted.'));
        }

        $model->delete();

        return $this->respondData($request, [], ___('Room type deleted.'));
    }

    // ── A11: rate plans ──────────────────────────────────────────────────────

    /** A11 — rate-plans list. */
    public function plans(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        $plans = RatePlan::forRestaurant((int) $store->id)->orderBy('id')->get();

        return $this->screen($request, 'rate-plans', ['store' => $store, 'plans' => $plans], ['plans' => $plans]);
    }

    /** A11 — create a rate plan. */
    public function plansStore(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $this->validatePlan($request, (int) $store->id);
        $data['store_id'] = (int) $store->id;
        $plan = RatePlan::create($data);

        return $this->respondData($request, ['id' => (int) $plan->id], ___('Rate plan created.'));
    }

    /** A11 — update a rate plan. */
    public function plansUpdate(Request $request, string $plan): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = RatePlan::where('id', $plan)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);
        $data = $this->validatePlan($request, (int) $store->id, (int) $model->id);
        $model->update($data);

        return $this->respondData($request, ['id' => (int) $model->id], ___('Rate plan updated.'));
    }

    /** A11 — delete a rate plan. Guarded: refused while a booking references it. */
    public function plansDelete(Request $request, string $plan): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = RatePlan::where('id', $plan)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);

        if (HotelBooking::where('rate_plan_id', $model->id)->exists()) {
            return $this->respondError($request, ___('Bookings reference this rate plan and it cannot be deleted.'));
        }

        // Detach any per-date price rows and child plans owned by this store so the
        // rate map stays consistent (RateService tolerates a missing parent).
        RoomRate::where('rate_plan_id', $model->id)->delete();
        RatePlan::where('parent_id', $model->id)->where('store_id', $store->id)->update(['parent_id' => null]);
        $model->delete();

        return $this->respondData($request, [], ___('Rate plan deleted.'));
    }

    // ── A11: per-date rates ──────────────────────────────────────────────────

    /**
     * A11 — read the rate matrix for a type (+optional plan) across a date range.
     * Query: room_type_id, rate_plan_id (nullable), from, to.
     */
    public function rates(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        RateService::ensureDefaultPlan((int) $store->id);
        $roomTypes = RoomType::forRestaurant((int) $store->id)->orderBy('sort')->orderBy('id')->get();
        $ratePlans = RatePlan::forRestaurant((int) $store->id)->orderBy('id')->get();

        $typeId = (int) $request->query('room_type_id', (int) ($roomTypes->first()->id ?? 0));
        $planId = $request->filled('rate_plan_id') ? (int) $request->query('rate_plan_id') : null;

        $month = $request->query('month') ?: now()->format('Y-m');
        try {
            $monthStart = Carbon::parse($month.'-01')->startOfMonth();
        } catch (\Throwable $e) {
            $monthStart = now()->startOfMonth();
            $month = $monthStart->format('Y-m');
        }
        $monthEnd = $monthStart->copy()->endOfMonth();
        $days = [];
        for ($d = $monthStart->copy(); $d <= $monthEnd; $d->addDay()) {
            $days[] = $d->toDateString();
        }

        // Per-date override rows for the selected (type, plan) → map[Y-m-d => price].
        $rates = [];
        if ($typeId && RoomType::where('id', $typeId)->where('store_id', $store->id)->exists()) {
            $q = RoomRate::where('store_id', $store->id)->where('room_type_id', $typeId);
            $planId ? $q->where('rate_plan_id', $planId) : $q->whereNull('rate_plan_id');
            $rates = $q->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->get()
                ->mapWithKeys(fn ($r) => [Carbon::parse($r->date)->toDateString() => (float) $r->price])
                ->all();
        }

        return $this->screen($request, 'rates', [
            'store' => $store, 'roomTypes' => $roomTypes, 'ratePlans' => $ratePlans,
            'selectedTypeId' => $typeId, 'selectedPlanId' => $planId,
            'selectedType' => $roomTypes->firstWhere('id', $typeId),
            'month' => $month, 'days' => $days, 'rates' => $rates,
            'currency' => HotelFolioService::currencyFor((int) $store->id),
        ], ['rates' => $rates]);
    }

    /**
     * A11 — bulk-upsert per-date prices. Fields (§5): room_type_id,
     * rate_plan_id (nullable = the type's base row), rates (map Y-m-d → price).
     * Each date must be a valid Y-m-d and each price numeric ≥ 0. The upsert is
     * keyed on (room_type_id, rate_plan_id, date) so re-posting the same date
     * overwrites its price rather than duplicating (RoomRate stores date as a
     * plain Y-m-d string precisely so this match holds — see the model note).
     */
    public function ratesBulk(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'rate_plan_id' => ['nullable', 'integer'],
            'rates' => ['required', 'array', 'min:1'],
        ]);

        $type = RoomType::where('id', $data['room_type_id'])->where('store_id', $store->id)->first();
        abort_if(! $type, 404);

        $planId = null;
        if (! empty($data['rate_plan_id'])) {
            $plan = RatePlan::where('id', $data['rate_plan_id'])->where('store_id', $store->id)->first();
            abort_if(! $plan, 404);
            $planId = (int) $plan->id;
        }

        $count = 0;
        foreach ($data['rates'] as $date => $price) {
            if (! is_string($date) || ! Carbon::hasFormat($date, 'Y-m-d')) {
                return $this->respondError($request, ___('Each rate date must be in YYYY-MM-DD format.'));
            }

            // An empty cell means "no override for this night". The grid posts EVERY
            // day of the month as a native number input, so most cells are blank — the
            // normal sparse case. Treat blank as "clear this night's override" (the
            // blade's own hint: "Clear a cell and save to remove the override") and
            // continue, rather than erroring, which made any sparse grid unsavable.
            if ($price === null || $price === '') {
                $q = RoomRate::where('room_type_id', (int) $type->id)->where('date', $date);
                $planId ? $q->where('rate_plan_id', $planId) : $q->whereNull('rate_plan_id');
                $q->delete();

                continue;
            }

            if (! is_numeric($price) || (float) $price < 0) {
                return $this->respondError($request, ___('Each rate price must be a number of 0 or more.'));
            }

            RoomRate::updateOrCreate(
                ['room_type_id' => (int) $type->id, 'rate_plan_id' => $planId, 'date' => $date],
                ['store_id' => (int) $store->id, 'price' => round((float) $price, 3)],
            );
            $count++;
        }

        return $this->respondData($request, ['updated' => $count], ___('Rates saved.'));
    }

    // ── A11: extra services ──────────────────────────────────────────────────

    /** A11 — extra-services list. */
    public function services(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        $services = HotelExtraService::forRestaurant((int) $store->id)->orderBy('id')->get();

        return $this->screen($request, 'services', [
            'store' => $store, 'services' => $services,
            'currency' => HotelFolioService::currencyFor((int) $store->id),
        ], ['services' => $services]);
    }

    /** A11 — create an extra service. */
    public function servicesStore(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $this->validateService($request);
        $data['store_id'] = (int) $store->id;
        $service = HotelExtraService::create($data);

        return $this->respondData($request, ['id' => (int) $service->id], ___('Service created.'));
    }

    /** A11 — update an extra service. */
    public function servicesUpdate(Request $request, string $service): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = HotelExtraService::where('id', $service)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);
        $model->update($this->validateService($request));

        return $this->respondData($request, ['id' => (int) $model->id], ___('Service updated.'));
    }

    /** A11 — delete an extra service (posted folio lines keep their own copy of the amount). */
    public function servicesDelete(Request $request, string $service): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = HotelExtraService::where('id', $service)->where('store_id', $store->id)->first();
        abort_if(! $model, 404);
        $model->delete();

        return $this->respondData($request, [], ___('Service deleted.'));
    }

    // ── A11: events + items ──────────────────────────────────────────────────

    /** A11 — events list. */
    public function events(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        $events = HotelEvent::forRestaurant((int) $store->id)->orderByDesc('starts_at')->orderByDesc('id')->get();
        // Active (checked-in) stays for the optional "bill to stay folio" picker.
        $stays = HotelStay::where('store_id', $store->id)->where('status', 'checked_in')->orderByDesc('id')->get();

        return $this->screen($request, 'events', [
            'store' => $store, 'events' => $events, 'stays' => $stays,
            'currency' => HotelFolioService::currencyFor((int) $store->id),
        ], ['events' => $events]);
    }

    /** A11 — a single event with its line items. */
    public function eventShow(Request $request, string $event): JsonResponse|\Illuminate\Contracts\View\View
    {
        $store = $this->hotelRestaurant();
        $model = $this->eventFor($store, $event);
        $model->load('items');
        $services = HotelExtraService::forRestaurant((int) $store->id)->where('active', true)->orderBy('id')->get();

        return $this->screen($request, 'event-show', [
            'store' => $store, 'event' => $model, 'items' => $model->items,
            'services' => $services, 'statement' => null,
            'currency' => HotelFolioService::currencyFor((int) $store->id),
        ], ['event' => $model]);
    }

    /** A11 — create an event (enquiry). May be linked to a stay for folio billing. */
    public function eventsStore(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $data = $this->validateEvent($request, (int) $store->id, true);
        $data['store_id'] = (int) $store->id;
        $data['status'] = HotelEvent::STATUS_ENQUIRY;
        $event = HotelEvent::create($data);

        return $this->respondData($request, ['id' => (int) $event->id], ___('Event created.'));
    }

    /**
     * A11 — update an event's details, and/or transition its status. A `status`
     * of confirmed|completed|cancelled delegates to EventService (which bills or
     * reverses the folio); metadata fields are saved either way.
     */
    public function eventsUpdate(Request $request, string $event): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->eventFor($store, $event);

        $data = $this->validateEvent($request, (int) $store->id, false);
        $status = $request->input('status');
        unset($data['status']);
        if (! empty($data)) {
            $model->update($data);
        }

        try {
            if ($status === HotelEvent::STATUS_CONFIRMED) {
                $model = EventService::confirm($model);
            } elseif ($status === HotelEvent::STATUS_COMPLETED) {
                $model = EventService::complete($model);
            } elseif ($status === HotelEvent::STATUS_CANCELLED) {
                $model = EventService::cancel($model);
            }
        } catch (\RuntimeException $e) {
            return $this->respondError($request, $e->getMessage());
        }

        return $this->respondData($request, ['id' => (int) $model->id, 'status' => $model->status], ___('Event updated.'));
    }

    /**
     * A11 — delete an event. Guarded: a standalone event with an outstanding
     * balance must be settled first; its line items are removed with it.
     */
    public function eventsDelete(Request $request, string $event): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->eventFor($store, $event);

        if (! $model->isLinkedToStay() && $model->folio_account_id && HotelFolioService::eventBalance($model) > 0.001) {
            return $this->respondError($request, ___('Settle the event balance before deleting it.'));
        }

        HotelEventItem::where('event_id', $model->id)->delete();
        $model->delete();

        return $this->respondData($request, [], ___('Event deleted.'));
    }

    /** A11 — add a line item to an event. Fields: service_id (nullable), description, qty, unit_price. */
    public function eventItemAdd(Request $request, string $event): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->eventFor($store, $event);

        $data = $request->validate([
            'service_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:160'],
            'qty' => ['nullable', 'numeric', 'min:0.01', 'max:99999'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if (! empty($data['service_id'])) {
            $service = HotelExtraService::where('id', $data['service_id'])->where('store_id', $store->id)->first();
            abort_if(! $service, 404);
        }

        $item = HotelEventItem::create([
            'event_id' => (int) $model->id,
            'service_id' => ! empty($data['service_id']) ? (int) $data['service_id'] : null,
            'description' => $data['description'],
            'qty' => (float) ($data['qty'] ?? 1),
            'unit_price' => round((float) $data['unit_price'], 3),
        ]);

        return $this->respondData($request, ['id' => (int) $item->id], ___('Item added.'));
    }

    /** A11 — remove a line item from an event. */
    public function eventItemRemove(Request $request, string $event, string $item): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $model = $this->eventFor($store, $event);
        $line = HotelEventItem::where('id', $item)->where('event_id', $model->id)->first();
        abort_if(! $line, 404);
        $line->delete();

        return $this->respondData($request, [], ___('Item removed.'));
    }

    // ── A11: room ↔ room-type assignment ─────────────────────────────────────

    /** A11 — assign or clear a room's room type. Field: room_type_id (nullable clears it). */
    public function roomType(Request $request, string $room): JsonResponse|RedirectResponse
    {
        $store = $this->hotelRestaurant();
        $roomModel = StoreTable::where('id', $room)->where('store_id', $store->id)->where('area_type', 'room')->first();
        abort_if(! $roomModel, 404);

        $data = $request->validate(['room_type_id' => ['nullable', 'integer']]);
        $typeId = null;
        if (! empty($data['room_type_id'])) {
            $type = RoomType::where('id', $data['room_type_id'])->where('store_id', $store->id)->first();
            abort_if(! $type, 404);
            $typeId = (int) $type->id;
        }
        $roomModel->room_type_id = $typeId;
        $roomModel->save();

        return $this->respondData($request, ['room_id' => (int) $roomModel->id, 'room_type_id' => $typeId], ___('Room type assigned.'));
    }

    // ── validation helpers ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function validateType(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:20'],
            'base_rate' => ['required', 'numeric', 'min:0'],
            'max_occupancy' => ['nullable', 'integer', 'min:1', 'max:99'],
            'sort' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active', true);
        $data['max_occupancy'] = (int) ($data['max_occupancy'] ?? 2);
        $data['sort'] = (int) ($data['sort'] ?? 0);

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validatePlan(Request $request, int $storeId, ?int $selfId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', 'integer'],
            'modifier_type' => ['nullable', 'in:percent,fixed'],
            'modifier_value' => ['nullable', 'numeric'],
            'refundable' => ['nullable', 'boolean'],
            'cancel_before_hours' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'active' => ['nullable', 'boolean'],
        ]);

        $parentId = null;
        if (! empty($data['parent_id'])) {
            abort_if($selfId !== null && (int) $data['parent_id'] === $selfId, 422, 'A plan cannot be its own parent.');
            $parent = RatePlan::where('id', $data['parent_id'])->where('store_id', $storeId)->first();
            abort_if(! $parent, 404);
            $parentId = (int) $parent->id;
        }

        $data['parent_id'] = $parentId;
        $data['refundable'] = $request->boolean('refundable', true);
        $data['active'] = $request->boolean('active', true);
        $data['cancel_before_hours'] = (int) ($data['cancel_before_hours'] ?? 24);

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validateService(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'charge_mode' => ['nullable', 'in:once,per_night,per_person_per_night'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['charge_mode'] = $data['charge_mode'] ?? HotelExtraService::MODE_ONCE;
        $data['tax_rate'] = (float) ($data['tax_rate'] ?? 0);
        $data['active'] = $request->boolean('active', true);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateEvent(Request $request, int $storeId, bool $requireTitle): array
    {
        $data = $request->validate([
            'title' => [$requireTitle ? 'required' : 'nullable', 'string', 'max:120'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'venue' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'attendees' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'hotel_stay_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:enquiry,confirmed,completed,cancelled'],
        ]);

        if (array_key_exists('hotel_stay_id', $data) && ! empty($data['hotel_stay_id'])) {
            $stay = HotelStay::where('id', $data['hotel_stay_id'])->where('store_id', $storeId)->first();
            abort_if(! $stay, 404);
            $data['hotel_stay_id'] = (int) $stay->id;
        }

        return $data;
    }

    /** Load an event of the current restaurant, or 404. */
    protected function eventFor(Store $store, int|string $id): HotelEvent
    {
        $event = HotelEvent::where('id', $id)->where('store_id', $store->id)->first();
        abort_if(! $event, 404);

        return $event;
    }

    /**
     * Render the owner blade (owner.hotel.<name>) when it exists in
     * this worktree, else return the JSON payload (cross-lane surface guard).
     *
     * @param  array<string, mixed>  $viewData
     * @param  array<string, mixed>  $json
     */
    protected function screen(Request $request, string $name, array $viewData, array $json): JsonResponse|\Illuminate\Contracts\View\View
    {
        $view = 'owner.hotel.'.$name;
        if (! $request->expectsJson() && View::exists($view)) {
            return view($view, $viewData);
        }

        return response()->json(['success' => true] + $json);
    }

    /**
     * JSON payload for AJAX callers (merges $extra), else a redirect back with a
     * success flash for form posts.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function respondData(Request $request, array $extra, string $flash): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true] + $extra);
        }
        app_alert_success($flash);

        return back();
    }

    /** JSON error for AJAX callers (422), else a redirect back with an error flash. */
    protected function respondError(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }
        app_alert_error($message);

        return back();
    }

    /** JSON for AJAX callers; a redirect back with a flash for form posts. */
    protected function respond(Request $request, string $flash): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }
        app_alert_success($flash);

        return back();
    }
}
