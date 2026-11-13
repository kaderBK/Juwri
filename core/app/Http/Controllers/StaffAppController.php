<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use App\Services\KdsService;
use App\Services\OrderApiService;
use App\Services\OrderStatusNotifier;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Concerns\ScopesToBranch;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Hr\Models\StaffTask;
use Juwri\Modules\Hr\Models\StaffTaskCompletion;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Rota\RotaService;
use Juwri\Modules\Kitchen\Models\FoodSafetyCheck;
use Juwri\Modules\Kitchen\Services\FoodSafety\FoodSafetyService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Ordering\Services\Orders\OrderCancellation;
use Juwri\Modules\Pos\Services\Waiter\WaiterService;
use Juwri\Modules\Tables\Models\WaiterCall;
use Juwri\Modules\Team\Models\StaffMessage;
use Juwri\Modules\Team\Models\StaffSyncedOp;

/**
 * #100: Staff mobile PWA. Same-origin app served under /staff-app, authenticated
 * with the existing session `staff` guard (#75). Provides an installable shell,
 * a polling live-orders feed scoped to the staff member's restaurant, big-tap
 * status advance, and Web Push subscription.
 */
class StaffAppController extends Controller
{
    use ScopesToBranch;

    /**
     * Branch isolation for order WRITES.
     *
     * The tenant guard on these endpoints only compares store_id, so a
     * staff member pinned to branch A could advance, complete, cancel, transfer
     * or request the bill on an order belonging to branch B of the same
     * restaurant simply by knowing its id — isolation was enforced on reads and
     * not on writes. Reuses the shared scoping rule, so a legacy NULL branch_id
     * still resolves to the restaurant's default branch, and staff who are not
     * pinned to a branch keep restaurant-wide access.
     */
    protected function orderIsInStaffBranch(Request $request, StaffMember $staff, Order $order): bool
    {
        $branchId = $this->resolveBranchId($request, $staff);
        if ($branchId === null) {
            return true;
        }

        return $this->scopeToBranch(
            Order::whereKey($order->id),
            (int) $staff->store_id,
            $branchId
        )->exists();
    }

    private const ACTIVE_STATUSES = [
        OrderStatus::New->value,
        OrderStatus::Preparing->value,
        OrderStatus::Ready->value,
        OrderStatus::CallBill->value,
    ];

    // R1a: single source of truth — the settable statuses (excludes pending_approval).
    private const VALID_STATUSES = [
        OrderStatus::New->value,
        OrderStatus::Preparing->value,
        OrderStatus::Ready->value,
        OrderStatus::Delivered->value,
        OrderStatus::Completed->value,
        OrderStatus::Cancelled->value,
        OrderStatus::CallBill->value,
    ];

    /** SP4 #18: orderable menu for the tableside create screen (scoped to the staff's restaurant). */
    public function menu(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $items = Menu::with('category:id,name')
            ->where('store_id', $staff->store_id)
            ->where('active', '1')
            ->orderBy('position')
            ->get(['id', 'category_id', 'name', 'price_minor', 'currency_code', 'is_sold_out']);

        return response()->json(['items' => $items->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->getRawOriginal('name'),
            'price' => (float) $m->price,
            'category' => optional($m->category)->name,
            'sold_out' => (bool) $m->is_sold_out,
        ])->values()]);
    }

    /** SP4 #19: table-occupancy snapshot for the phone floor view (staff-scoped). */
    public function floor(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $branchId = $this->resolveBranchId($request, $staff);
        $q = StoreTable::where('store_id', $staff->store_id);
        // A9: hotel rooms/zones are store_tables rows too, but they are NOT dining
        // tables — exclude them from the floor grid (a property can have hundreds).
        // Mirrors TableSessionService / TableVerificationController which already fence
        // rooms out.
        $q->where(fn ($w) => $w->whereNull('area_type')->orWhereNotIn('area_type', ['room', 'zone']));
        // SP11 #49: scope tables to the active branch.
        $this->scopeToBranch($q, (int) $staff->store_id, $branchId);
        // FP7 #42: a server can ask for only their own section's tables.
        if ($request->boolean('mine')) {
            $q->where('server_staff_id', $staff->id);
        }
        $tables = $q->orderBy('table_no')->get(['id', 'table_no', 'seats', 'status', 'map_x', 'map_y', 'section', 'server_staff_id', 'note', 'min_covers', 'max_covers']);

        return response()->json(['tables' => $tables->map(function ($t) use ($staff, $branchId) {
            // The live order must be scoped to the SAME branch — two branches can reuse
            // a table number, so an unscoped lookup would show the wrong branch's order.
            $orderQ = Order::where('store_id', $staff->store_id)
                ->where('table_number', $t->table_no)
                // A9: a room_service order stores table_number = the room's table_no, so
                // an unscoped match lit up the same-numbered DINE-IN table (and vice
                // versa). Only on-table/dine-in orders may occupy a floor table.
                ->where('type', '!=', 'room_service')
                ->live() // T1: unreleased scheduled orders don't light up a table yet
                ->whereIn('status', self::ACTIVE_STATUSES);
            $this->scopeToBranch($orderQ, (int) $staff->store_id, $branchId);
            $order = $orderQ->orderByDesc('id')->first();
            // The table's own status column wins; else derive from a live order.
            $tableStatus = $t->status ?: 'free';

            return [
                'table_no' => $t->table_no,
                'seats' => $t->seats,
                'status' => $tableStatus !== 'free' ? $tableStatus : ($order ? $order->status : 'free'),
                'order_id' => $order?->id,
                'bill' => $order && $order->status === 'call_bill',
                'section' => $t->section,
                'note' => $t->note, // FP8 #46
                'min_covers' => $t->min_covers, // FP4 #26
                'max_covers' => $t->max_covers,
                'mine' => (int) $t->server_staff_id === (int) $staff->id, // FP7 #42
            ];
        })->values()]);
    }

    /** SP4 #21: request the bill for a table's order — marks it call_bill (stays in the feed). */
    public function requestBill(Request $request, Order $order)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if ((int) $order->store_id !== (int) $staff->store_id || ! $staff->hasPermission('orders')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $this->orderIsInStaffBranch($request, $staff, $order)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $order->status = 'call_bill';
        $order->save();
        log_activity('order.request_bill', $order, ['via' => 'staff_pwa', 'order_id' => $order->id], $order->store_id);

        return response()->json(['id' => $order->id, 'status' => 'call_bill']);
    }

    /** SP4 #22: move an order to another table (permission-gated, tenant-scoped). */
    public function transferOrder(Request $request, Order $order)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if ((int) $order->store_id !== (int) $staff->store_id || ! $staff->hasPermission('orders')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $this->orderIsInStaffBranch($request, $staff, $order)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate(['table_number' => 'required|string|max:20']);

        // A9: a charge-to-room order was already captured onto a guest folio (with the
        // tray charge). Flipping it to type=on-table re-snapshots totals WITHOUT the
        // tray term (Order::trayCharge is 0 off room_service) and turns a folio-paid
        // order into an apparently-unpaid table bill floor staff would re-settle.
        // Refuse — the folio must be settled or reversed first.
        if ($order->payment_method === 'charge_to_room') {
            return response()->json(['error' => 'This order is charged to a room folio — settle or reverse it first.'], 422);
        }

        $from = $order->table_number;
        $order->table_number = $data['table_number'];
        // WIRING-RUN D5: a room-service order moved to a table really does become an
        // on-table order, but its service_location still named the room — leaving a
        // stale room label on a table order. Clear it as part of the same transition.
        if ($order->type === 'room_service') {
            $order->service_location = null;
        }
        $order->type = 'on-table';
        $order->save();
        log_activity('order.transfer', $order, ['via' => 'staff_pwa', 'from' => $from, 'to' => $data['table_number']], $order->store_id);

        return response()->json(['id' => $order->id, 'table_number' => $order->table_number]);
    }

    /**
     * SP5 #24/#25: KDS tickets for the phone kitchen view. Surfaces the SAME open
     * OrderItem set the desktop KDS uses (queued/preparing/ready), grouped into
     * per-order tickets, plus an all-day tally (#25). Read-only + tenant-scoped;
     * KDS behavior itself is untouched. Optional ?station_id filter, else the
     * staff member's own station.
     */
    public function kdsTickets(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if (! $staff->hasPermission('kds')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $stationId = $request->query('station_id') ?: $staff->station_id;

        /* SP11 #49: the rail is branch-scoped like every other staff surface in
           this controller (tables at :95, orders at :109, floor at :758). Left
           unscoped, a cook at branch A saw — and could bump — branch B's
           tickets, which is both a cross-branch data leak and a way to mark
           another kitchen's food ready. */
        $branchId = $this->resolveBranchId($request, $staff);

        /* A voided order must leave the rail. Its line items keep their last
           kitchen status ('queued'/'preparing'), and scopeLive() only filters
           unreleased scheduled orders and ones awaiting approval — so a
           cancelled ticket sat on the rail indefinitely, and bumping it pushed
           a cancelled order back through the flow. Excluded here rather than in
           scopeLive() because that scope has many other callers. */
        $items = OrderItem::whereHas('order', fn ($q) => $this->scopeToBranch(
            $q->where('store_id', $staff->store_id)
                ->live()
                // P6-09 V-15: an `unpaid` (pay-link / hosted) order reaches the kitchen only once paid.
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['cancelled', 'unpaid'])),
            (int) $staff->store_id,
            $branchId
        ))
            ->whereIn('status', ['queued', 'preparing', 'ready'])
            ->when($stationId, fn ($q, $st) => $q->where('station_id', $st))
            ->with(['menu', 'order'])
            ->orderBy('fired_at')
            ->get();

        $tickets = $items->groupBy('order_id')->map(fn ($rows) => [
            'order_id' => $rows->first()->order_id,
            'table_number' => $rows->first()->order?->table_number,
            'type' => $rows->first()->order?->type,
            'phone_paid' => $rows->first()->order->source === 'phone' && (bool) $rows->first()->order->is_paid, // V-15 badge
            'placed_at' => optional($rows->first()->order?->created_at)->toIso8601String(),
            'items' => $rows->map(fn ($it) => [
                'id' => $it->id,
                'name' => $it->menu?->getRawOriginal('name'),
                'quantity' => (int) $it->quantity,
                'status' => $it->status,
                'note' => $it->order_note,
            ])->values()->all(),
        ])->values()->all();

        // SP5 #25: all-day counts — open units per item name across the tickets.
        $allDay = $items->groupBy(fn ($it) => $it->menu?->getRawOriginal('name') ?? '—')
            ->map(fn ($rows) => (int) $rows->sum('quantity'))
            ->sortDesc()
            ->map(fn ($qty, $name) => ['name' => $name, 'qty' => $qty])
            ->values();

        return response()->json(['tickets' => $tickets, 'all_day' => $allDay]);
    }

    /** SP5 #27: advance one KDS step from the phone (recall is the undo). */
    public function kdsBump(Request $request, int $itemId)
    {
        return $this->kdsStep($request, $itemId, 'bump');
    }

    public function kdsRecall(Request $request, int $itemId)
    {
        return $this->kdsStep($request, $itemId, 'recall');
    }

    private function kdsStep(Request $request, int $itemId, string $action)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if (! $staff->hasPermission('kds')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $item = OrderItem::whereHas('order', fn ($q) => $q->where('store_id', $staff->store_id))
            ->with('order')
            ->findOrFail($itemId);

        // Branch-scope the WRITE, matching the read rail (kdsTickets) and the sibling
        // order writes (updateStatus/requestBill/transferOrder). Scoped by store_id
        // only, a cook pinned to branch A could bump/recall branch B's ticket by item id.
        abort_unless($item->order && $this->orderIsInStaffBranch($request, $staff, $item->order), 403);

        $action === 'bump'
            ? KdsService::bumpItem($item)
            : KdsService::recallItem($item);

        return response()->json(['id' => $item->id, 'status' => $item->fresh()->status]);
    }

    /**
     * SP8 #36/#37: the staff member's checklist — active tasks for their restaurant
     * that target their role (or everyone), each annotated with whether it's been
     * ticked for today and by whom. Tenant + role scoped.
     */
    /** B7-P2: this staff member's PUBLISHED shifts (this week + next). */
    public function myShifts(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        /* FIX-2 F2-7 (gap audit): the staff PWA is a side door onto the rota —
           it authenticates a STAFF member, so there is no dashboard owner and
           the entitlement subject is the employing store's owner. Kill veto
           plus, once `staff_rota` is plan_gated, the owner's entitlement. */
        if (! app(RotaService::class)->enabledFor((int) $staff->store_id)) {
            return response()->json(['shifts' => []]);
        }

        $shifts = app(RotaService::class)->publishedShiftsForStaff((int) $staff->id);

        return response()->json([
            'shifts' => $shifts->map(fn ($s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'role' => $s->role,
                'note' => $s->note,
            ])->values(),
        ]);
    }

    /**
     * FIX-2 (gap audit): the staff-PWA side door on plan-gated SKUs.
     *
     * The owner dashboard reaches these features through `feature:<key>`, which
     * applies the kill veto AND — once the key is `plan_gated` — the owner's
     * entitlement. The staff PWA authenticates on the `staff` guard, so there is
     * no dashboard owner for that middleware to read and these endpoints only
     * ever consulted the kill-switch: a staff member of an unentitled store kept
     * full access to a paid capability. Resolve the STORE OWNER from the store
     * row (canonical, same shape as CrossCartService) and ask exactly the
     * question `EnsureFeatureEnabled` asks.
     *
     * Kill veto always wins; an un-gated key keeps today's kill-only behavior.
     * `FeatureAccess::storeAllows` is that shared serve-time twin — one
     * vocabulary, not a third scheme.
     */
    private static function storeFeatureAllowed(int $storeId, string $key): bool
    {
        return FeatureAccess::storeAllows($storeId, $key);
    }

    /** B10-P2: food-safety checks due today for this staff member's restaurant. */
    public function foodSafetyChecks(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        // FIX-2: was `feature_enabled()` (kill-only) returning an empty list. Now
        // the same 403 its sibling write endpoints give, so an unentitled store
        // cannot read the paid feature's data either.
        abort_unless(self::storeFeatureAllowed((int) $staff->store_id, 'food_safety'), 403);

        $due = app(FoodSafetyService::class)->dueToday((int) $staff->store_id);

        return response()->json([
            'checks' => $due->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'kind' => $c->kind,
                'target_min' => $c->target_min,
                'target_max' => $c->target_max,
            ])->values(),
        ]);
    }

    /** B10-P2: log a food-safety reading (out-of-range temp → breach). */
    public function logFoodSafety(Request $request, int $checkId)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        abort_unless(self::storeFeatureAllowed((int) $staff->store_id, 'food_safety'), 403); // FIX-2: kill + entitlement

        $check = FoodSafetyCheck::where('store_id', $staff->store_id)->findOrFail($checkId);
        $data = $request->validate([
            'value' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $log = app(FoodSafetyService::class)
            ->log($check, (int) $staff->id, isset($data['value']) ? (float) $data['value'] : null, $data['note'] ?? null);

        return response()->json(['ok' => true, 'breach' => (bool) $log->breach]);
    }

    /** B10-P2: log a free-form food-safety incident. */
    public function logFoodSafetyIncident(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        abort_unless(self::storeFeatureAllowed((int) $staff->store_id, 'food_safety'), 403); // FIX-2: kill + entitlement

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        app(FoodSafetyService::class)
            ->logIncident((int) $staff->store_id, (int) $staff->id, $data['note'], $staff->branch_id ? (int) $staff->branch_id : null);

        return response()->json(['ok' => true]);
    }

    public function tasks(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $today = StoreClock::today($staff->store_id);
        $tasks = StaffTask::where('store_id', $staff->store_id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', $staff->role)) // #37 by-role
            ->orderBy('position')->orderBy('id')
            ->get();

        $done = StaffTaskCompletion::where('store_id', $staff->store_id)
            ->whereIn('staff_task_id', $tasks->pluck('id'))
            ->where('task_date', $today)
            ->get()->keyBy('staff_task_id');

        return response()->json(['date' => $today, 'tasks' => $tasks->map(function ($t) use ($done) {
            $c = $done->get($t->id);

            return [
                'id' => $t->id,
                'title' => $t->title,
                'role' => $t->role,
                'section' => $t->section,
                'done' => (bool) $c,
                'completed_at' => optional($c?->completed_at)->toIso8601String(),
                'by_staff_id' => $c?->staff_id,
            ];
        })->values()]);
    }

    /** SP8 #36/#37: a manager defines a checklist item (restaurant-scoped). */
    public function createTask(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if ($staff->role !== 'manager') {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'role' => 'nullable|string|max:30',
            'section' => 'nullable|string|max:30',
            'position' => 'nullable|integer',
        ]);

        $task = StaffTask::create([
            'store_id' => $staff->store_id,
            'title' => $data['title'],
            'role' => $data['role'] ?? null,
            'section' => $data['section'] ?? null,
            'position' => $data['position'] ?? 0,
            'active' => true,
        ]);

        return response()->json(['id' => $task->id, 'title' => $task->title], 201);
    }

    /** SP8 #36: tick a task for today (idempotent per business-date). */
    public function completeTask(Request $request, int $taskId)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $task = StaffTask::where('store_id', $staff->store_id)->findOrFail($taskId);
        $today = StoreClock::today($staff->store_id);
        $attrs = ['staff_task_id' => $task->id, 'task_date' => $today];

        try {
            // Savepoint: on PostgreSQL a failed insert would abort an enclosing transaction.
            $completion = DB::transaction(fn () => StaffTaskCompletion::firstOrCreate(
                $attrs,
                ['store_id' => $staff->store_id, 'staff_id' => $staff->id, 'completed_at' => now()]
            ));
        } catch (QueryException $e) {
            // Concurrent double-tap / offline replay raced the INSERT and lost to the
            // unique(staff_task_id, task_date) constraint — that's the idempotent
            // outcome, so treat it as already-done rather than a 500.
            $completion = StaffTaskCompletion::where($attrs)->firstOrFail();
        }

        return response()->json(['id' => $task->id, 'done' => true, 'completed_at' => optional($completion->completed_at)->toIso8601String()]);
    }

    /** SP8 #36: undo today's tick (e.g. mistaken check). */
    public function uncompleteTask(Request $request, int $taskId)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $task = StaffTask::where('store_id', $staff->store_id)->findOrFail($taskId);
        StaffTaskCompletion::where('staff_task_id', $task->id)
            ->where('task_date', StoreClock::today($staff->store_id))->delete();

        return response()->json(['id' => $task->id, 'done' => false]);
    }

    /**
     * SP9 #41/#42: the staff comms thread for this restaurant (recent-first, capped).
     * Tenant-scoped; every staff member of the restaurant can read it.
     */
    public function messages(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $messages = StaffMessage::where('store_id', $staff->store_id)
            ->orderByDesc('id')->limit(50)->get();

        return response()->json(['messages' => $messages->map(fn ($m) => [
            'id' => $m->id,
            'body' => $m->body,
            'kind' => $m->kind,
            'author' => $m->author_name,
            'mine' => (int) $m->staff_id === (int) $staff->id,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ])->values()]);
    }

    /** SP9 #41: post a message to the staff thread. */
    public function postMessage(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        $data = $request->validate(['body' => 'required|string|max:1000']);

        $msg = StaffMessage::create([
            'store_id' => $staff->store_id,
            'staff_id' => $staff->id,
            'author_name' => $staff->name,
            'kind' => 'message',
            'body' => $data['body'],
        ]);

        return response()->json(['id' => $msg->id], 201);
    }

    /**
     * SP9 #42: manager broadcast — an announcement posted to the thread that also
     * fires a web-push to the team. Manager-only. Push is a no-op without VAPID
     * (DEFERRED live step), so this works in dev/test without a key.
     */
    public function broadcast(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if ($staff->role !== 'manager') {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $data = $request->validate(['body' => 'required|string|max:1000']);

        $msg = StaffMessage::create([
            'store_id' => $staff->store_id,
            'staff_id' => $staff->id,
            'author_name' => $staff->name,
            'kind' => 'broadcast',
            'body' => $data['body'],
        ]);

        // Push to the team (seam: no-op until web-push/VAPID is configured).
        app(MessageQueue::class)->staffPush(
            (int) $staff->store_id,
            __('Team announcement'),
            $data['body'],
            '/staff-app'
        );

        return response()->json(['id' => $msg->id, 'kind' => 'broadcast'], 201);
    }

    /**
     * SP4 #18: create a tableside order. Server-priced via OrderApiService (client
     * never sends prices). Permission-gated + tenant-scoped; stamped source=staff_pwa
     * and created_by_staff_id for attribution.
     */
    public function createTableOrder(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        if (! $staff->hasPermission('orders')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'table_number' => 'nullable|string|max:20',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'nullable|integer|min:1|max:30',
            'note' => 'nullable|string|max:500',
            'client_uuid' => 'nullable|string|max:64',
            'client_order_id' => 'nullable|string|max:64',
        ]);
        // J27-A2: UUID order key (body client_order_id / client_uuid or Idempotency-Key); same key + same cart
        // returns the same order, another cart is a 409.
        $clientOrderId = ClientOrderId::fromRequest($request);

        $owner = Store::where('id', $staff->store_id)->value('user_id');

        try {
            [$order, $replayed] = OrderApiService::createOrReplay((int) $owner, (int) $staff->store_id, [
                'type' => 'on-table',
                'table_number' => $data['table_number'] ?? null,
                'items' => $data['items'], // ids + qty only; priced server-side
                'note' => $data['note'] ?? null,
                'source' => 'staff_pwa',
                'created_by_staff_id' => $staff->id,
                'client_uuid' => $clientOrderId,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first() ?: 'Invalid order.'], 422);
        } catch (ClientOrderConflict $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        if (! $replayed) {
            log_activity('order.create', $order, ['via' => 'staff_pwa', 'table' => $data['table_number'] ?? null], $staff->store_id);
        }

        return response()->json(['id' => $order->id, 'status' => $order->status, 'table_number' => $order->table_number], 201);
    }

    // ---- SYSTEMS-RUN W: waiter tableside ----

    private function waiterGuard()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        // FIX-2 (gap audit): was kill-only — a staff member of an UNENTITLED store
        // drove the whole waiter-mode surface (floor, add-items, fire, split,
        // merge, calls) for free. Now kill veto + the store owner's entitlement.
        abort_unless(self::storeFeatureAllowed((int) $staff->store_id, 'waiter_mode'), 403);
        abort_unless($staff->hasPermission('orders'), 403);

        return $staff;
    }

    public function waiterFloor(Request $request)
    {
        $staff = $this->waiterGuard();
        $states = (new WaiterService)->tableStates($staff->store_id, $staff->branch_id);

        return response()->json(['tables' => $states]);
    }

    public function waiterAddItems(Request $request, Order $order)
    {
        $staff = $this->waiterGuard();
        abort_unless((int) $order->store_id === (int) $staff->store_id, 404);
        abort_unless($this->orderIsInStaffBranch($request, $staff, $order), 404);
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'nullable|integer|min:1|max:30',
            'items.*.seat' => 'nullable|integer|min:1|max:99',
            'items.*.course' => 'nullable|integer|min:1|max:9',
            'hold' => 'nullable|boolean',
        ]);
        $created = (new WaiterService)->addItems($order, $data['items'], (bool) ($data['hold'] ?? false));

        return response()->json(['added' => count($created)], 201);
    }

    public function waiterFireCourse(Request $request, Order $order)
    {
        $staff = $this->waiterGuard();
        abort_unless((int) $order->store_id === (int) $staff->store_id, 404);
        abort_unless($this->orderIsInStaffBranch($request, $staff, $order), 404);
        $data = $request->validate(['course' => 'required|integer|min:1|max:9']);
        $fired = (new WaiterService)->fireCourse($order, (int) $data['course']);

        return response()->json(['fired' => $fired]);
    }

    public function waiterSplitBySeat(Request $request, Order $order)
    {
        $staff = $this->waiterGuard();
        abort_unless((int) $order->store_id === (int) $staff->store_id, 404);
        abort_unless($this->orderIsInStaffBranch($request, $staff, $order), 404);

        return response()->json(['seats' => (new WaiterService)->splitBySeat($order)]);
    }

    public function waiterMerge(Request $request, Order $order)
    {
        $staff = $this->waiterGuard();
        abort_unless((int) $order->store_id === (int) $staff->store_id, 404);
        abort_unless($this->orderIsInStaffBranch($request, $staff, $order), 404);
        $data = $request->validate(['into_order_id' => 'required|integer']);
        $to = Order::where('store_id', $staff->store_id)->findOrFail($data['into_order_id']);
        // The merge target must also be in the staff's branch — otherwise a branch-A
        // waiter could siphon a branch-B order into their own, or vice versa.
        abort_unless($this->orderIsInStaffBranch($request, $staff, $to), 404);
        try {
            (new WaiterService)->merge($order, $to);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'into' => $to->id]);
    }

    public function waiterCalls(Request $request)
    {
        $staff = $this->waiterGuard();
        $calls = WaiterCall::where('store_id', $staff->store_id)
            ->where('seen', false)->orderByDesc('id')->get(['id', 'table_no', 'type']);

        return response()->json(['calls' => $calls]);
    }

    public function waiterAckCall(Request $request, int $callId)
    {
        $staff = $this->waiterGuard();
        $call = WaiterCall::where('store_id', $staff->store_id)->findOrFail($callId);
        $call->update(['seen' => true]);

        return response()->json(['ok' => true]);
    }

    /** Installable app shell. */
    public function shell()
    {
        $staff = Auth::guard('staff')->user();

        return view('staff-app.index', [
            'vapidPublicKey' => (string) config('webpush.public_key', ''),
            'staffRole' => $staff?->role, // SP9: gate the manager-only broadcast button
            'notifSounds' => notification_sounds_config($staff?->store_id ? (int) $staff->store_id : null, $staff?->branch_id ? (int) $staff->branch_id : null),
            // UI-W: the Tableside tab mirrors waiterGuard() exactly — store entitlement
            // (kill veto + plan gate) AND the `orders` staff permission. A staff member
            // who would only get a 403 never sees the tab.
            'canWaiter' => $staff
                ? (self::storeFeatureAllowed((int) $staff->store_id, 'waiter_mode') && $staff->hasPermission('orders'))
                : false,
            // UI-FS: food-safety endpoints gate on the store entitlement only (no
            // per-staff permission), so the tab does the same.
            'canFoodSafety' => $staff
                ? self::storeFeatureAllowed((int) $staff->store_id, 'food_safety')
                : false,
        ]);
    }

    /** PWA manifest. */
    public function manifest()
    {
        return response()->json([
            'name' => 'juwri Staff',
            'short_name' => 'Staff',
            'start_url' => '/staff-app',
            'scope' => '/staff-app',
            'display' => 'standalone',
            'background_color' => '#12121a',
            'theme_color' => '#12121a',
            'orientation' => 'portrait',
            'icons' => [
                ['src' => asset('assets/img/staff-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('assets/img/staff-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /** Service worker (served at /staff-app/sw.js so its scope is /staff-app). */
    public function serviceWorker()
    {
        return response()
            ->view('staff-app.sw')
            ->header('Content-Type', 'application/javascript')
            ->header('Service-Worker-Allowed', '/staff-app');
    }

    /**
     * SP11 #49: the branches this staff member can view (their whole restaurant's
     * branches), plus which one is currently active. Drives the PWA branch switcher.
     */
    public function branches(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $branches = Branch::where('store_id', $staff->store_id)
            ->orderBy('id')->get(['id', 'name']);

        return response()->json([
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'active' => $this->resolveBranchId($request, $staff),
        ]);
    }

    /**
     * SP11 #49: resolve which branch to scope data to. A ?branch= that belongs to the
     * staff's restaurant wins (a foreign branch id is rejected 404 — no cross-branch
     * reach); otherwise the staff member's own branch_id; else null (whole restaurant).
     */
    private function resolveBranchId(Request $request, $staff): ?int
    {
        $requested = $request->integer('branch');
        if ($requested) {
            $ok = Branch::where('id', $requested)->where('store_id', $staff->store_id)->exists();
            abort_unless($ok, 404);

            return $requested;
        }

        return $staff->branch_id ? (int) $staff->branch_id : null;
    }

    // SP11 #49: the branch-scoping rule lives in the ScopesToBranch trait (shared with
    // FloorMapController) so the tenant-isolation logic has a single source of truth.

    /** Live orders feed, scoped to the staff member's restaurant (and branch, SP11 #49). */
    public function orders(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        $branchId = $this->resolveBranchId($request, $staff);

        $q = Order::with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'voiceNote'])
            ->where('store_id', $staff->store_id)
            ->live() // T1: unreleased scheduled orders excluded from the live PWA feed
            ->whereIn('status', self::ACTIVE_STATUSES);
        $this->scopeToBranch($q, (int) $staff->store_id, $branchId);

        $orders = $q->orderBy('created_at')->limit(100)->get();

        return response()->json([
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'status' => $o->status,
                'type' => $o->type,
                'table_number' => $o->table_number,
                'customer_name' => $o->customer_name,
                'total' => $o->total,
                'created_at' => optional($o->created_at)->toIso8601String(),
                // FEATURES-5 Track C: signed voice-note URL (<audio> can't send bearer/session on native).
                'voice_note_url' => $o->voiceNote
                    ? URL::temporarySignedRoute('voiceNote.signedStream', now()->addHours(6), ['order' => $o->id])
                    : null,
                'voice_note_duration' => $o->voiceNote?->duration_seconds,
                // ZERO-TOUCH Track B3: transcript text under the play button.
                'voice_note_transcript' => $o->voiceNote?->transcript,
                'items' => $o->items->map(fn ($it) => [
                    'name' => optional($it->menu)->name ?? '—',
                    'qty' => (int) $it->quantity,
                ])->values(),
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Advance an order's status (permission-gated, tenant-scoped). */
    public function updateStatus(Request $request, Order $order)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        if ((int) $order->store_id !== (int) $staff->store_id || ! $staff->hasPermission('orders')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $this->orderIsInStaffBranch($request, $staff, $order)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', self::VALID_STATUSES),
            'client_op_id' => 'nullable|string|max:64', // SP2: offline outbox dedupe key
            'reason' => 'nullable|string|max:200', // SP3 #13: reject reason (audit only)
        ]);
        $status = $data['status'];
        $reason = trim((string) ($data['reason'] ?? ''));

        // SP2: a replayed offline op (same client_op_id) applies exactly once — no
        // duplicate status write, SMS, or activity log on reconnect. P6-206: the key is
        // per store (two stores' devices may mint the same id).
        $opId = (string) ($data['client_op_id'] ?? '');
        if ($opId !== '' && StaffSyncedOp::where('store_id', $order->store_id)->where('op_id', $opId)->exists()) {
            return response()->json(['id' => $order->id, 'status' => $order->status, 'duplicate' => true]);
        }

        // P6-207: cancelled is terminal — stock and tender already went back.
        if (OrderCancellation::blocksTransition($order, $status)) {
            return response()->json(['error' => ___('A cancelled order cannot be changed.')], 422);
        }
        if ($status === 'cancelled' && $order->status !== 'cancelled') {
            // P6-206: cancelling a PAID order gives money back — same gate as POS refund.
            if ((bool) $order->is_paid && ! $staff->hasPermission('pos_refund')) {
                return response()->json(['error' => ___('Manager permission required.')], 403);
            }
            // P6-205: paid split shares must be refunded / settled first (as the dashboard).
            if (OrderCancellation::hasPaidSplitShares($order)) {
                return response()->json(['error' => ___('This bill already has paid split shares — refund those or finish settling it before cancelling.')], 422);
            }
        }

        if ($opId !== '') {
            StaffSyncedOp::create([
                'op_id' => $opId, 'order_id' => $order->id,
                'store_id' => $order->store_id, 'status' => $status, 'created_at' => now(),
            ]);
        }

        $order->status = $status;
        foreach (['preparing' => 'preparing_at', 'ready' => 'ready_at', 'completed' => 'completed_at', 'cancelled' => 'cancelled_at'] as $s => $col) {
            if ($status === $s) {
                $order->{$col} = now();
            }
        }
        $order->save();

        // P6-205: the shared cancel routine — stock, folio, gift card, wallet, loyalty
        // (each idempotent, so a retried cancel never double-credits).
        if ($status === 'cancelled') {
            OrderCancellation::reverse($order);
        }

        log_activity('order.status', $order, array_filter([
            'status' => $status,
            'order_id' => $order->id,
            'via' => 'staff_pwa',
            'reason' => $reason !== '' ? $reason : null,
        ], fn ($v) => $v !== null), $order->store_id);

        // Reuse the customer status SMS hook (owner opt-in). Routed through
        // OrderStatusNotifier so the same locale handling, template resolution,
        // and per (order, status) idempotency is shared with the owner dashboard
        // (ManagesOrders::updateOrderStatus) and the KDS (KdsController::bump /
        // KdsService::syncOrderStatus). P6-213: the locale wrap + status-word
        // translation still happen INSIDE the notifier, exactly as before —
        // computing them before the closure would bind them to the current staff
        // locale (English "ready") and an Arabic order would ship the English word.
        OrderStatusNotifier::notify($order, $status);

        return response()->json(['id' => $order->id, 'status' => $status]);
    }

    /** SP6: clock in (one open shift at a time; PIN session + optional GPS/selfie). */
    public function clockIn(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        $open = StaffTimeClock::where('staff_id', $staff->id)->whereNull('clock_out_at')->first();
        if ($open) {
            return response()->json(['error' => 'Already clocked in', 'shift_id' => $open->id], 422);
        }
        $d = $request->validate(['method' => 'nullable|in:pin,gps,selfie', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric']);
        $shift = StaffTimeClock::create([
            'staff_id' => $staff->id, 'store_id' => $staff->store_id,
            'clock_in_at' => now(), 'method' => $d['method'] ?? 'pin',
            'gps_lat' => $d['lat'] ?? null, 'gps_lng' => $d['lng'] ?? null,
        ]);

        return response()->json(['shift_id' => $shift->id], 201);
    }

    /** SP6: clock out — closes the open shift. */
    public function clockOut(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        $shift = StaffTimeClock::where('staff_id', $staff->id)->whereNull('clock_out_at')->latest('id')->first();
        if (! $shift) {
            return response()->json(['error' => 'Not clocked in'], 422);
        }
        $shift->clock_out_at = now();
        $shift->save();

        return response()->json(['shift_id' => $shift->id, 'minutes' => $shift->clock_in_at->diffInMinutes($shift->clock_out_at)]);
    }

    /** SP7: this staff member's tips for orders they served, in a range. */
    public function tipsSummary(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);
        // rescue(): a garbage ?from=xyz would make Carbon::parse throw and 500 the endpoint.
        $from = ($request->query('from')
            ? rescue(fn () => Carbon::parse($request->query('from')), now(), false)
            : now())->startOfDay();
        $orders = Order::where('store_id', $staff->store_id)
            ->where('served_by_staff_id', $staff->id)->where('created_at', '>=', $from)->get();

        return response()->json(['orders' => $orders->count(), 'tips' => money_round($orders->sum(fn ($o) => (float) ($o->tip_amount ?? 0)), money_decimals(store_currency((int) $staff->store_id)))]);
    }

    /** Register a Web Push subscription for the logged-in staff member. */
    public function subscribe(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $sub = $request->validate([
            'endpoint' => 'required|string',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'contentEncoding' => 'nullable|string',
        ]);

        PushSubscription::store($sub, (int) $staff->store_id, (int) $staff->id);

        return response()->json(['success' => true]);
    }

    /**
     * T8-P0: register a native app device token (FCM/APNs) for push.
     *
     * PARITY-RUN T9: gated with FeatureAccess (via MobilePushService) INSIDE the
     * method rather than by `feature:` middleware. This route is called by the
     * app on every cold start on the staff guard, where the requester is not the
     * buyer — middleware here would refuse every install. Device metadata is
     * accepted so the owner's device list can name what it is revoking.
     */
    public function registerDevice(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 401);

        $data = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'required|in:fcm,apns',
            'device_name' => 'nullable|string|max:80',
            'app_version' => 'nullable|string|max:24',
            'os_version' => 'nullable|string|max:24',
        ]);

        $storeId = (int) $staff->store_id;
        if (! MobilePushService::enabled($storeId)) {
            return response()->json(['success' => false, 'error' => 'feature_disabled'], 403);
        }

        PushSubscription::storeNative(
            $data['token'],
            $data['platform'],
            $storeId,
            (int) $staff->id,
            null,
            [
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'os_version' => $data['os_version'] ?? null,
            ]
        );

        return response()->json(['success' => true]);
    }
}
