<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use App\Models\KitchenStation;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;

/**
 * FEATURES-RUN-4 T1/H5 — Service requests staff board.
 *
 * Guest requests (housekeeping / maintenance / amenity / staff_call / custom)
 * raised from a room QR land in `service_requests` and are routed to a
 * department — a {@see KitchenStation} row referenced by `department_id`
 * (H1's design: kitchen_stations double as hotel departments). The board
 * mirrors the KDS open→in_progress→done column flow. Owner + staff create
 * requests and advance their status.
 *
 * Public guest submission lives on {@see \Juwri\Modules\Hotel\Http\Controllers\HotelGuestController}.
 */
class HotelServiceRequestController extends Controller
{
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
        abort_unless(HotelService::enabled($store->id), 404);

        return $store;
    }

    /**
     * The staff board — open / in-progress / done columns.
     *
     * HP-B5: queried PER STATUS so a wall of 'done' cards can never push an older
     * still-open request out of a shared 300-row window (the silent-drop bug).
     * open/in_progress are effectively unbounded; done is the most recent 50.
     * Returns JSON (same shape) for the Alpine auto-refresh poll (mirrors kds.poll).
     */
    public function board(Request $request)
    {
        $store = $this->resolveStore($request);

        $open = $this->column($store->id, 'open', 500);
        $inProgress = $this->column($store->id, 'in_progress', 500);
        $done = $this->column($store->id, 'done', 50);

        // Name map for the assignee display: active staff + the owner (legacy rows
        // auto-assigned request->user()->id, which is the owner User).
        $staff = StaffMember::where('store_id', $store->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $people = $staff->pluck('name', 'id')->toArray();
        if ($store->user_id) {
            $people[$store->user_id] = optional($store->user)->name ?: ($people[$store->user_id] ?? '—');
        }

        // Lookup maps keyed by the request's own column ids (avoids per-row relation loads).
        $roomLabels = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->pluck('label', 'id')
            ->toArray();
        $roomNumbers = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->pluck('table_no', 'id')
            ->toArray();
        $deptNames = KitchenStation::where('store_id', $store->id)
            ->pluck('name', 'id')
            ->toArray();

        $serialize = fn ($r) => $this->serializeRequest($r, $people, $roomNumbers, $roomLabels, $deptNames);

        if ($request->wantsJson()) {
            return response()->json([
                'open' => $open->map($serialize)->values(),
                'in_progress' => $inProgress->map($serialize)->values(),
                'done' => $done->map($serialize)->values(),
                'counts' => ['open' => $open->count(), 'in_progress' => $inProgress->count(), 'done' => $done->count()],
            ]);
        }

        $columns = ['open' => $open, 'in_progress' => $inProgress, 'done' => $done];

        // Pre-serialized initial state so the Alpine board paints before the first poll.
        $initial = [
            'open' => $open->map($serialize)->values(),
            'in_progress' => $inProgress->map($serialize)->values(),
            'done' => $done->map($serialize)->values(),
        ];

        $rooms = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->orderBy('table_no')
            ->get();

        $departments = KitchenStation::where('store_id', $store->id)
            ->where('active', true)
            ->orderBy('position')
            ->get();

        return view('owner.hotel.requests', compact('store', 'columns', 'rooms', 'departments', 'staff', 'people', 'initial'));
    }

    /**
     * One status column, newest first, capped.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ServiceRequest>
     */
    private function column(int $storeId, string $status, int $limit): Collection
    {
        return ServiceRequest::where('store_id', $storeId)
            ->where('status', $status)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Card payload for the board (HTML initial render + JSON poll share this shape).
     *
     * @param  array<int|string, string>  $people  staff/owner id → name
     * @param  array<int|string, int|string>  $roomNumbers  table_id → room number
     * @param  array<int|string, string|null>  $roomLabels  table_id → room label
     * @param  array<int|string, string>  $deptNames  station id → name
     * @return array<string, mixed>
     */
    private function serializeRequest(ServiceRequest $r, array $people, array $roomNumbers, array $roomLabels, array $deptNames): array
    {
        $room = null;
        if ($r->table_id && isset($roomNumbers[$r->table_id])) {
            $label = $roomLabels[$r->table_id] ?? null;
            $room = '#'.$roomNumbers[$r->table_id].($label ? ' · '.$label : '');
        }

        return [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'room' => $room,
            'department' => $r->department_id ? ($deptNames[$r->department_id] ?? null) : null,
            'note' => $r->note,
            'assigned_to' => $r->assigned_staff_id ? (int) $r->assigned_staff_id : null,
            'assignee' => $r->assigned_staff_id ? ($people[$r->assigned_staff_id] ?? null) : null,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    /** Owner/staff manually create a request. */
    public function store(Request $request)
    {
        $store = $this->resolveStore($request);

        $data = $request->validate([
            'table_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:'.implode(',', ServiceRequest::TYPES)],
            'note' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer'],
        ]);

        // Validate ownership of the referenced room / department (prefix-safe).
        $tableId = null;
        if (! empty($data['table_id'])) {
            $tableId = StoreTable::where('id', $data['table_id'])
                ->where('store_id', $store->id)
                ->value('id');
        }

        $departmentId = $this->resolveDepartment($store->id, $data['type'], $data['department_id'] ?? null);

        $serviceRequest = ServiceRequest::create([
            'store_id' => $store->id,
            'branch_id' => dashboard_request_branch_id($store->id),
            'table_id' => $tableId,
            'type' => $data['type'],
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'department_id' => $departmentId,
        ]);

        // D4: alert the desk, exactly like the guest QR path (was fire-and-forget here).
        $room = $tableId ? StoreTable::find($tableId) : null;
        $this->dispatchStaffRequestAlert($store, $serviceRequest, $room);

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    /** Advance / change a request's status. */
    public function updateStatus(Request $request)
    {
        $store = $this->resolveStore($request);

        $data = $request->validate([
            'id' => ['required', 'integer'],
            'status' => ['required', 'in:'.implode(',', ServiceRequest::STATUSES)],
            // HP-B5: optional (re)assignment from the board's assign control.
            'assigned_staff_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $req = ServiceRequest::where('id', $data['id'])
            ->where('store_id', $store->id)
            ->firstOrFail();

        $req->status = $data['status'];

        if ($request->has('assigned_staff_id')) {
            // Explicit assign/unassign — the staff member must belong to this store.
            $sid = $data['assigned_staff_id'] ?: null;
            if ($sid && ! StaffMember::where('id', $sid)->where('store_id', $store->id)->exists()) {
                $sid = null;
            }
            $req->assigned_staff_id = $sid;
        } elseif ($data['status'] === 'in_progress' && ! $req->assigned_staff_id) {
            // Auto-assign the actor on Start: prefer the acting staff member, fall
            // back to the owner (web guard) so the existing owner path is unchanged.
            $req->assigned_staff_id = auth('staff')->id() ?? optional($request->user())->id;
        }

        $req->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'status' => $req->status]);
        }

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    /**
     * Map a request type to a department (kitchen_station) using the
     * hotel_settings.departments map, falling back to an explicit choice.
     *
     * D4 — only route to an ACTIVE department (kitchen_stations double as hotel
     * departments; a disabled one would leave the request on a screen nobody
     * watches). A mapped-but-inactive department resolves to null (unrouted, shows
     * on the general board) rather than to a dead station.
     */
    private function resolveDepartment(int $storeId, string $type, $explicit): ?int
    {
        if ($explicit) {
            $ok = KitchenStation::where('id', $explicit)
                ->where('store_id', $storeId)
                ->where('active', true)
                ->value('id');
            if ($ok) {
                return (int) $ok;
            }
        }

        $map = HotelService::settings($storeId)['departments'] ?? [];
        if (is_array($map) && ! empty($map[$type])) {
            $ok = KitchenStation::where('id', $map[$type])
                ->where('store_id', $storeId)
                ->where('active', true)
                ->value('id');
            if ($ok) {
                return (int) $ok;
            }
        }

        return null;
    }

    /**
     * D4 — a request created from the STAFF board must alert the desk exactly like
     * the guest QR path (HotelGuestController::dispatchRequestAlerts): before this
     * the dashboard-create path was fire-and-forget, so a manager logging a request
     * for a guest never rang the notification centre. Best-effort — the row is
     * already saved, so a channel outage must not fail the create. Same option
     * key + default as the guest path (notification centre ON pre-merge).
     */
    private function dispatchStaffRequestAlert(Store $store, ServiceRequest $sr, ?StoreTable $room): void
    {
        $value = store_options($store->id, 'hotel_alerts_notif');
        $notifOn = ($value === null || $value === false || $value === '') ? true : ((string) $value === '1');
        if (! $notifOn) {
            return;
        }

        $roomLabel = $room ? ($room->label ?: ('Room '.$room->table_no)) : '—';
        // Escape []() so a bracket/paren in the owner-set room label can't break the notif link.
        $esc = fn (string $t) => str_replace(['[', ']', '(', ')'], ['\\[', '\\]', '\\(', '\\)'], $t);
        $title = ___('Room').' '.$esc($roomLabel).': '.$esc($this->requestTypeLabel($sr->type));

        try {
            CreateNotificationJob::dispatch($title, 'hotel_request', route('hotel.requests', [], false))
                ->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('hotel staff request notif dispatch failed', ['request' => $sr->id, 'error' => $e->getMessage()]);
        }
    }

    /** Human, localized label for a request type (mirrors the guest path). */
    private function requestTypeLabel(string $type): string
    {
        return match ($type) {
            'housekeeping' => ___('Housekeeping'),
            'amenity' => ___('Extra towels / amenities'),
            'maintenance' => ___('Maintenance'),
            'staff_call' => ___('Call staff'),
            default => ___('Request'),
        };
    }
}
