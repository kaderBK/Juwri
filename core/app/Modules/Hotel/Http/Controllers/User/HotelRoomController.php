<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use App\Models\StoreTable;
use App\Support\SearchTerm;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;

/**
 * FEATURES-RUN-4 T1/H3 — Rooms & areas admin.
 *
 * A hotel room (or a zone like Pool Deck) is a `store_tables` row with
 * `area_type` in (room|zone) and a human `label`. This screen is the owner
 * CRUD over those rows: single create, bulk create (Rooms 101–120), floor/wing
 * grouping via `floor_label`, and a printable QR sheet that reuses the existing
 * server-rendered `restaurants.qrSvg` image endpoint.
 *
 * Everything is gated on {@see HotelService::enabled()} — hidden/403 when the
 * per-restaurant hotel_mode flag is off (H11 formalises plan gating).
 */
class HotelRoomController extends Controller
{
    /** Resolve the active owned restaurant, or 403 if none / not owned. */
    private function resolveStore(Request $request): Store
    {
        $ids = dashboard_store_ids();
        $requested = $request->integer('store_id') ?: null;

        if ($requested && ! in_array($requested, $ids, true)) {
            abort(403);
        }

        $id = $requested ?: (dashboard_current_store()->id ?? ($ids[0] ?? null));
        if (! $id) {
            abort(404);
        }

        $store = Store::findOrFail($id);
        // hotel module must be enabled for this restaurant
        abort_unless(HotelService::enabled($store->id), 404);

        return $store;
    }

    public function index(Request $request)
    {
        $store = $this->resolveStore($request);

        // HP-B2 — search (number/label), floor/wing filter and zone/room type filter,
        // paginated so large properties (bulk create allows 500 at a time) stay usable.
        $q = trim((string) $request->string('q'));
        $floor = trim((string) $request->string('floor'));
        $type = (string) $request->string('type');

        $base = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone']);

        $rooms = (clone $base)
            ->with('roomType')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    SearchTerm::apply($w, ['table_no', 'label'], $q);
                });
            })
            ->when($floor !== '', fn ($query) => $query->where('floor_label', $floor))
            ->when(in_array($type, ['room', 'zone'], true), fn ($query) => $query->where('area_type', $type))
            ->orderByRaw("COALESCE(floor_label, '')")
            ->orderBy('table_no')
            ->paginate(100)
            ->withQueryString();

        // Distinct floor/wing labels for the filter dropdown (unfiltered set).
        $floors = (clone $base)
            ->whereNotNull('floor_label')
            ->where('floor_label', '!=', '')
            ->orderBy('floor_label')
            ->distinct()
            ->pluck('floor_label');

        // HPMS — the PMS room types available to assign to a room (lane-B rooms UI
        // posts the choice to hotel.pms.rooms.type). Empty until the owner defines
        // types; the blade falls back to a plain room list when none exist.
        $roomTypes = RoomType::forRestaurant((int) $store->id)->orderBy('sort')->orderBy('name')->get();

        return view('owner.hotel.rooms', compact('store', 'rooms', 'floors', 'q', 'floor', 'type', 'roomTypes'));
    }

    /** Create or update a single room/zone row. */
    public function store(Request $request)
    {
        $store = $this->resolveStore($request);

        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'table_no' => ['required', 'integer', 'min:1', 'max:100000'],
            'area_type' => ['required', 'in:room,zone'],
            'label' => ['nullable', 'string', 'max:120'],
            'floor_label' => ['nullable', 'string', 'max:120'],
            'room_type_id' => ['nullable', 'integer'],
        ]);

        // Persist the room-type assignment (the form always posted it, but the
        // controller used to drop it — leaving every UI-created room typeless, so
        // availableForType() found zero rooms and online booking read "sold out").
        // Only rooms carry a type; a zone never does. The id is confirmed to belong
        // to this store before it is trusted.
        $roomTypeId = null;
        if (($data['area_type'] ?? '') === 'room' && ! empty($data['room_type_id'])) {
            $roomTypeId = RoomType::where('id', (int) $data['room_type_id'])
                ->where('store_id', $store->id)->value('id');
        }

        if (! empty($data['id'])) {
            $row = StoreTable::where('id', $data['id'])
                ->where('store_id', $store->id)
                ->firstOrFail();

            // HP-B2: renumbering onto an existing room number in the same branch is
            // a duplicate too — guard it (update was previously unchecked).
            if ($this->roomNumberTaken($store->id, $row->branch_id, (int) $data['table_no'], (int) $row->id)) {
                app_alert_error(___('A room or area with that number already exists.'));

                return back();
            }

            $row->update([
                'table_no' => $data['table_no'],
                'area_type' => $data['area_type'],
                'label' => $data['label'] ?? null,
                'floor_label' => $data['floor_label'] ?? null,
                'room_type_id' => $roomTypeId,
            ]);
            app_alert_success(___('Saved Successfully'));

            return back();
        }

        // HP-B2: single-create now dedupes per (restaurant, branch) like bulkStore,
        // which previously allowed two "Room 101" rows on one branch.
        $branchId = dashboard_request_branch_id($store->id);
        if ($this->roomNumberTaken($store->id, $branchId, (int) $data['table_no'])) {
            app_alert_error(___('A room or area with that number already exists.'));

            return back();
        }

        StoreTable::create([
            'store_id' => $store->id,
            'branch_id' => $branchId,
            'table_no' => $data['table_no'],
            'area_type' => $data['area_type'],
            'label' => $data['label'] ?? null,
            'floor_label' => $data['floor_label'] ?? null,
            'room_type_id' => $roomTypeId,
            'key' => uniqid('', true),
        ]);

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    /**
     * HP-B2 — is $tableNo already used by a room/zone in the same (restaurant,
     * branch)? Branch NULL ("All branches") is its own bucket. $exceptId skips a
     * row (for renumber-in-place). Consistent rule for single + bulk create.
     */
    private function roomNumberTaken(int $storeId, ?int $branchId, int $tableNo, ?int $exceptId = null): bool
    {
        return StoreTable::where('store_id', $storeId)
            ->whereIn('area_type', ['room', 'zone'])
            ->where('table_no', $tableNo)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /** Bulk create a numeric range of rooms (e.g. 101–120 on floor 1). */
    public function bulkStore(Request $request)
    {
        $store = $this->resolveStore($request);

        $data = $request->validate([
            'from' => ['required', 'integer', 'min:1', 'max:100000'],
            'to' => ['required', 'integer', 'min:1', 'max:100000'],
            'area_type' => ['required', 'in:room,zone'],
            'floor_label' => ['nullable', 'string', 'max:120'],
            'prefix' => ['nullable', 'string', 'max:40'],
            'room_type_id' => ['nullable', 'integer'],
        ]);

        // Same room-type persistence as single create — a bulk range of rooms must
        // carry its type or none of them are bookable. Verified to belong to the store.
        $roomTypeId = null;
        if (($data['area_type'] ?? '') === 'room' && ! empty($data['room_type_id'])) {
            $roomTypeId = RoomType::where('id', (int) $data['room_type_id'])
                ->where('store_id', $store->id)->value('id');
        }

        $from = min($data['from'], $data['to']);
        $to = max($data['from'], $data['to']);
        if (($to - $from) > 500) {
            app_alert_error(___('Range too large (max 500 at once).'));

            return back();
        }

        // HP-B2: dedupe within THIS branch only — previously a second branch could
        // never bulk-create numbers the first branch already used (silently skipped).
        $branchId = dashboard_request_branch_id($store->id);
        $existing = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->pluck('table_no')
            ->all();
        $existing = array_flip($existing);

        $created = 0;
        for ($n = $from; $n <= $to; $n++) {
            if (isset($existing[$n])) {
                continue; // never duplicate an existing room number
            }
            StoreTable::create([
                'store_id' => $store->id,
                'branch_id' => $branchId,
                'table_no' => $n,
                'area_type' => $data['area_type'],
                'label' => trim(($data['prefix'] ?? '').' '.$n) ?: null,
                'floor_label' => $data['floor_label'] ?? null,
                'room_type_id' => $roomTypeId,
                'key' => uniqid('', true),
            ]);
            $created++;
        }

        app_alert_success(___('Created').': '.$created);

        return back();
    }

    public function destroy(Request $request)
    {
        $store = $this->resolveStore($request);
        $id = $request->integer('id');

        // HP-B2: restrict to rooms/zones so the hotel endpoint can't delete an
        // ordinary dining table, and refuse while a guest is checked into the room —
        // a dangling HotelStay.table_id 500s the Stays page and strands the folio.
        $row = StoreTable::where('id', $id)
            ->where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->firstOrFail();

        if (HotelStay::where('table_id', $row->id)->where('status', 'checked_in')->exists()) {
            app_alert_error(___('This room has a guest checked in. Check them out before deleting it.'));

            return back();
        }

        $row->delete();

        app_alert_success(___('Deleted Successfully'));

        return back();
    }

    /** Printable QR sheet — one card per room, image from restaurants.qrSvg. */
    public function printSheet(Request $request)
    {
        $store = $this->resolveStore($request);

        $rooms = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->orderByRaw("COALESCE(floor_label, '')")
            ->orderBy('table_no')
            ->get();

        return view('owner.hotel.rooms-print', compact('store', 'rooms'));
    }
}
