<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Http\Controllers\User;

use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\KdsService;
use App\Services\OrderStatusNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Tables\Services\TableMergeService;

class KdsController extends Controller
{
    /** P6-36c: max tickets per /kds/poll response (rush first, then oldest first); ?page=N pages further. */
    public const KDS_WINDOW = 200;

    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids();
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id');

        if ($storeId && ! in_array((int) $storeId, $storeIds)) {
            abort(403);
        }

        $activeId = $storeId ? (int) $storeId : (dashboard_current_store()->id ?? $storeIds[0] ?? null);
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        $mode = $activeId ? kds_mode($activeId) : 'basic';
        // Stations (incl. an implicit "Expo" + "All") for the advanced board's tabs.
        $stations = $activeId
            ? KitchenStation::where('store_id', $activeId)->where('active', true)->orderBy('position')->get()
            : collect();

        return view('owner.kitchen.kds', compact('stores', 'activeId', 'mode', 'stations'));
    }

    /** Switch a restaurant between 'basic' (order-level) and 'advanced' (stations/item-level) KDS. */
    public function setMode(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'mode' => ['required', 'in:basic,advanced'],
        ]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }

        StoreOption::updateStoreOption($data['store_id'], 'kds_mode', $data['mode']);

        return back()->with('success', ___('Kitchen mode updated.'));
    }

    /**
     * Poll endpoint — open orders for the KDS screen as JSON (mode + station aware).
     */
    public function poll(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->query('store_id', 0);

        if ($storeId && ! in_array($storeId, $storeIds)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $stationId = (int) $request->query('station_id', 0); // #77
        $branchId = (int) (dashboard_request_branch_id($storeId ?: null) ?? 0); // #76 + sidebar selection
        $advanced = $storeId ? kds_mode($storeId) === 'advanced' : false;

        // Advanced board also shows 'ready' tickets (expo serves them); basic stays new/preparing.
        $statuses = $advanced ? ['new', 'preparing', 'ready'] : ['new', 'preparing'];

        $query = Order::with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'items.station', 'voiceNote'])
            ->whereIn('status', $statuses)
            ->whereNull('held_at') // POS: parked orders never reach the kitchen
            ->live() // T1: unreleased scheduled orders stay out of the kitchen until fire time
            ->orderByDesc('is_rush') // D5: rush tickets jump the queue
            ->orderBy('created_at', 'asc');

        if ($storeId) {
            $query->where('store_id', $storeId);
        } else {
            $query->whereIn('store_id', $storeIds);
        }
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($stationId) {
            $query->whereHas('items', fn ($q) => $q->where('station_id', $stationId));
        }

        // P6-36c: bounded window. A busy kitchen can hold thousands of open tickets; every poll used to load all of them
        // with 5 eager loads. The board gets at most KDS_WINDOW tickets per request in the existing order (rush first,
        // then oldest first); ?page=N pages further. One extra row is fetched to tell whether more exist (X-Kds-Has-More),
        // so there is no COUNT query. Store / branch / station scoping above is untouched.
        $page = max(1, min(1000, (int) $request->query('page', 1)));
        $query->offset(($page - 1) * self::KDS_WINDOW)->limit(self::KDS_WINDOW + 1);

        // P6-58: delta mode. A client that sends ?cursor=<token> gets only the tickets whose lean fingerprint
        // changed since its previous poll (+ the ordered id list so closed tickets drop off); no cursor, an
        // expired one, or a different scope -> the full legacy array. The per-client fingerprint map lives in
        // the cache under a key bound to the signed-in user + the exact filters, so one user can never be
        // handed another scope's tickets, and the query above (store / branch / station scoping) is unchanged.
        $cursor = (string) $request->query('cursor', '');
        if ($cursor !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $cursor)) {
            $lean = (clone $query)->setEagerLoads([])->select(['id', 'updated_at', 'status', 'is_rush'])->toBase()->get();
            $hasMore = $lean->count() > self::KDS_WINDOW;
            $lean = $lean->take(self::KDS_WINDOW);
            $ids = $lean->pluck('id')->all();
            $itemSig = [];
            if ($ids) {
                foreach (OrderItem::whereIn('order_id', $ids)->toBase()->get(['id', 'order_id', 'status', 'fired_at', 'station_id', 'quantity']) as $it) {
                    $itemSig[$it->order_id][] = $it->id.':'.$it->status.':'.($it->fired_at ? 1 : 0).':'.$it->station_id.':'.$it->quantity;
                }
            }
            $sigs = [];
            foreach ($lean as $o) {
                $sigs[$o->id] = substr(hash('sha256', (string) $o->updated_at.'|'.$o->status.'|'.(int) $o->is_rush.'|'.implode(',', $itemSig[$o->id] ?? [])), 0, 12);
            }
            $key = 'kds.poll.'.hash('sha256', implode('|', [
                (int) (auth()->id() ?? 0), $storeId, $branchId, $stationId, $advanced ? 1 : 0, $page, $cursor,
            ]));
            $prev = Cache::get($key);
            if ($prev !== $sigs) {
                Cache::put($key, $sigs, 900);
            }

            if (is_array($prev)) {
                $changedIds = array_keys(array_filter($sigs, fn ($sig, $id) => ($prev[$id] ?? null) !== $sig, ARRAY_FILTER_USE_BOTH));
                $changed = $changedIds ? (clone $query)->offset(0)->limit(self::KDS_WINDOW)->whereIn('id', $changedIds)->get() : collect();

                return response()->json([
                    'delta' => true,
                    'order' => array_map('intval', $ids),
                    'removed' => array_values(array_map('intval', array_diff(array_keys($prev), array_keys($sigs)))),
                    'orders' => $this->kdsPayload($changed, $stationId)->values(),
                ])->header('X-Kds-Has-More', $hasMore ? '1' : '0');
            }
            // first poll for this cursor: fall through to the full array (the sig map is now stored).
        }

        $fetched = $query->get();
        $hasMore = $fetched->count() > self::KDS_WINDOW;
        $orders = $this->kdsPayload($fetched->take(self::KDS_WINDOW), $stationId);

        return response()->json($orders)->header('X-Kds-Has-More', $hasMore ? '1' : '0');
    }

    /**
     * Shape a set of loaded kitchen orders into the board's ticket payload (shared by full + delta polls).
     *
     * @param  Collection<int, mixed>  $fetched
     * @return Collection<int, mixed>
     */
    private function kdsPayload(Collection $fetched, int $stationId): Collection
    {

        // ZERO-TOUCH Track C: per-store p50 prep target for the ticket footer.
        // Reads the cached 30-day aggregate (5-min TTL) — null without history.
        $prepTargets = [];
        foreach ($fetched->pluck('store_id')->unique() as $rid) {
            $stats = EtaEstimator::stats((int) $rid);
            $prepTargets[(int) $rid] = ($stats['ok'] ?? false) ? (int) round($stats['p50']) : null;
        }

        $orders = $fetched->map(function ($order) use ($stationId) {
            // Held (unfired) course lines must stay off the kitchen board until fired —
            // they carry status 'held'/fired_at null. allDay() already excludes them; poll
            // did not, so a held course was shown and prepared, defeating coursing.
            $items = $order->items->reject(fn ($i) => $i->status === 'held');
            if ($stationId) {
                $items = $items->where('station_id', $stationId);
            }
            $order->setRelation('items', $items->values());

            return $order;
        })->map(function ($order) use ($prepTargets) {
            return [
                'id' => $order->id,
                'store_id' => $order->store_id,
                'branch_id' => $order->branch_id,
                // ZERO-TOUCH Track C: store's p50 prep minutes (target hint).
                'prep_target_min' => $prepTargets[$order->store_id] ?? null,
                'status' => $order->status,
                'is_rush' => (bool) $order->is_rush, // D5: rush chip + queue jump
                'type' => $order->type,
                'table_number' => $order->table_number,
                // C7 — the human room label for a room_service order. Without it the
                // kitchen reads a room-305 ticket as "Table 305" (or a blank), and a
                // room labelled "Penthouse" is unidentifiable. The card prefixes by type.
                'service_location' => $order->service_location,
                // PARITY-RUN T4: when the table is a merged party's primary, the
                // ticket names every table in it ("T5 + T7") so a runner does not
                // walk past half the guests. Null for an ordinary order.
                'party_label' => TableMergeService::partyLabelForOrder($order),
                // PARITY-RUN T2: the customer's board number, so the ticket and
                // the screen the customer is watching say the same thing.
                'token_number' => $order->token_number ? (int) $order->token_number : null,
                'customer_name' => $order->customer_name,
                'is_curbside' => (bool) $order->is_curbside,
                'source' => $order->source,
                'is_kiosk' => $order->source === 'kiosk',
                'phone_paid' => $order->source === 'phone' && (bool) $order->is_paid, // P6-09 V-15: "Phone · paid" badge
                'vehicle' => $order->vehicle_details,
                'arrived' => $order->curbside_arrived_at !== null,
                'message' => $order->message,
                // FEATURES-5 Track C: voice note (authorized streaming URL + duration).
                'voice_note_url' => $order->voiceNote ? route('stores.orders.voiceNote', $order->id) : null,
                'voice_note_duration' => $order->voiceNote?->duration_seconds,
                // ZERO-TOUCH Track B3: transcript text under the play button.
                'voice_note_transcript' => $order->voiceNote?->transcript,
                'created_at' => $order->created_at?->toIso8601String(),
                'preparing_at' => $order->preparing_at?->toIso8601String(),
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        // PARITY-RUN T6: a combo line has no menu row — displayName()
                        // reads its name back out of the order-time snapshot, so an
                        // edited or deleted combo still shows what the diner ordered.
                        'name' => $item->displayName() ?: '—',
                        'is_combo' => $item->isCombo(),
                        'qty' => $item->quantity,
                        'variant' => $item->variant?->name,
                        'extras' => $item->itemExtras->map(fn ($e) => $e->extra?->name)->filter()->values(),
                        // T6-4: choice-group selections as "Title: opt1, opt2" strings.
                        // T6 (parity): the combo's own bundle group is skipped — it
                        // repeats the line name and carries only the price, and a cook
                        // needs the components.
                        'options' => collect((array) $item->options)
                            ->reject(fn ($g) => ! empty($g['combo']))
                            ->map(fn ($g) => ($g['title'] ?? '').': '.collect($g['selected'] ?? [])->pluck('option')->join(', '))
                            ->values(),
                        'note' => $item->order_note,
                        'status' => $item->status ?: 'queued',
                        'course' => (int) ($item->course ?: 1),
                        'fired' => $item->fired_at !== null,
                        'station_id' => $item->station_id,
                        'station' => $item->station?->name,
                        'station_color' => $item->station?->display_color,
                        'target' => $item->station->target_minutes ?? 10,
                    ];
                })->values(),
            ];
        });

        return $orders;
    }

    /** SYSTEMS-RUN K-P1: all-day summary — open-item counts per menu item, per station. */
    public function allDay(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->query('store_id', 0);
        if ($storeId && ! in_array($storeId, $storeIds)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $stationId = (int) $request->query('station_id', 0);

        $items = OrderItem::whereHas('order', function ($q) use ($storeId, $storeIds) {
            $q->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('held_at')->live();
            $storeId ? $q->where('store_id', $storeId) : $q->whereIn('store_id', $storeIds);
        })
            ->whereIn('status', ['queued', 'preparing', 'ready'])
            ->when($stationId, fn ($q) => $q->where('station_id', $stationId))
            ->with(['menu', 'combo']) // T6: combo lines name themselves
            ->get();

        // T6: combo lines group under the combo's name, not under a bare "—".
        $summary = $items->groupBy(fn ($it) => $it->displayName() ?: '—')
            ->map(fn ($rows, $name) => ['name' => $name, 'qty' => (int) $rows->sum('quantity')])
            ->sortByDesc('qty')->values();

        return response()->json(['all_day' => $summary]);
    }

    /**
     * Bump an ORDER to the next status (basic mode / whole-ticket).
     */
    public function bump(Request $request, int $orderId)
    {
        $storeIds = $this->ownerRestaurantIds();
        $order = Order::whereIn('store_id', $storeIds)->findOrFail($orderId);

        $transition = ['new' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'];
        $newStatus = $transition[$order->status] ?? null;
        if (! $newStatus) {
            return response()->json(['error' => 'Nothing to bump.'], 422);
        }

        $timestamps = ['preparing' => 'preparing_at', 'ready' => 'ready_at', 'delivered' => 'completed_at'];
        $updates = ['status' => $newStatus];
        if (isset($timestamps[$newStatus])) {
            $updates[$timestamps[$newStatus]] = now();
        }
        $order->update($updates);

        // P6-292: the kitchen bump used to skip the customer SMS / WhatsApp entirely
        // (only the owner route + staff PWA dispatched it). Notify from the single
        // shared service so a kitchen-only flow tells the diner the order is ready
        // when customer_status_sms is on — and so two KDS screens bumping the same
        // order to the same status do not double-send.
        OrderStatusNotifier::notify($order->fresh(), $newStatus);

        // P6-292: also stamp the shared 'order.status' activity entry (mirrors
        // ManagesOrders::updateOrderStatus + StaffAppController::updateStatus so
        // audit queries no longer need a per-source action branch).
        if (function_exists('log_activity')) {
            log_activity('order.status', $order->fresh(), [
                'status' => $newStatus,
                'order_id' => $orderId,
                'via' => 'kds',
            ], $order->store_id);
        }

        return response()->json(['status' => $newStatus]);
    }

    /**
     * T1: upcoming scheduled (pre-)orders not yet released to the kitchen.
     * Feeds the KDS "Scheduled" panel with a fire-time countdown.
     */
    public function scheduledUpcoming(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id');
        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }
        $branchId = $request->query('branch_id');

        // scheduled_at / fire_at are stored as store-local WALL-CLOCK (validate() parses
        // them in the store tz, with no conversion). The plain 'datetime' cast rehydrates
        // them in config('app.timezone'), so ->toIso8601String() would stamp the app-tz
        // offset onto a store-local time — a store in +3 whose app runs UTC would show
        // every pre-order 3h off. Re-anchor to the store tz before serializing, exactly
        // as releaseDue()/slotCounts() do when they bucket by the same wall clock.
        $scheduling = app(SchedulingService::class);
        $tzIso = function ($dt, string $tz): ?string {
            return $dt ? Carbon::parse($dt->format('Y-m-d H:i:s'), $tz)->toIso8601String() : null;
        };

        $orders = Order::with(['items.menu', 'branch'])
            ->whereIn('store_id', $storeId ? [(int) $storeId] : $storeIds)
            ->whereNotNull('scheduled_at')
            ->whereNull('released_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('fire_at')
            ->limit(100)
            ->get()
            ->map(function ($o) use ($scheduling, $tzIso) {
                // getRelationValue (not the ->branch magic property) so static analysis
                // can resolve the type; the relation is eager-loaded above.
                $branch = $o->getRelationValue('branch');
                $branch = $branch instanceof Branch ? $branch : null;
                $tz = $scheduling->timezone((int) $o->store_id, $branch);

                return [
                    'id' => $o->id,
                    'type' => $o->type,
                    'table_number' => $o->table_number,
                    'service_location' => $o->service_location, // C7 — room label for room_service
                    'customer_name' => $o->customer_name,
                    'scheduled_at' => $tzIso($o->scheduled_at, $tz),
                    'fire_at' => $tzIso($o->fire_at, $tz),
                    'item_count' => $o->items->sum('quantity'),
                ];
            });

        return response()->json($orders);
    }

    /** T1: manually release a scheduled order to the kitchen now ("release now" button). */
    public function releaseScheduled(Request $request, int $orderId)
    {
        $storeIds = $this->ownerRestaurantIds();
        $order = Order::whereIn('store_id', $storeIds)->findOrFail($orderId);

        $released = app(SchedulingService::class)->release($order);
        if ($released && function_exists('log_activity')) {
            log_activity('kds.release_scheduled', ['order_id' => $orderId]);
        }

        return response()->json(['released' => $released, 'status' => $order->fresh()->status]);
    }

    /** Bump a single ITEM forward (advanced mode) — the order status re-syncs from its items. */
    public function bumpItem(Request $request, int $item)
    {
        $item = $this->ownedItem($item);
        KdsService::bumpItem($item);

        return response()->json([
            'item_status' => $item->fresh()->status,
            'order_status' => $item->order?->fresh()->status,
        ]);
    }

    /** Recall (un-bump) a single item back a step. */
    public function recallItem(Request $request, int $item)
    {
        $item = $this->ownedItem($item);
        KdsService::recallItem($item);

        return response()->json([
            'item_status' => $item->fresh()->status,
            'order_status' => $item->order?->fresh()->status,
        ]);
    }

    /** Kitchen prep-time analytics report (advanced). */
    public function analytics(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;
        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        $days = (int) $request->query('days', 7);
        $days = in_array($days, [1, 7, 30], true) ? $days : 7;
        $since = now()->subDays($days);

        $report = null;
        if ($storeId) {
            $rid = (int) $storeId;

            // Orders in range for this restaurant (completed flow).
            $orders = Order::where('store_id', $rid)
                ->where('created_at', '>=', $since)
                ->whereNotNull('ready_at')
                ->get(['id', 'created_at', 'ready_at', 'completed_at']);

            $ticketTimes = $orders->map(fn ($o) => $o->ready_at && $o->created_at ? $o->created_at->diffInSeconds($o->ready_at) : null)->filter();
            $avgTicket = $ticketTimes->count() ? round($ticketTimes->avg() / 60, 1) : 0;

            // Item-level prep metrics + on-time buckets vs station target.
            $items = OrderItem::with('station')
                ->whereHas('order', fn ($q) => $q->where('store_id', $rid)->where('created_at', '>=', $since))
                ->whereNotNull('item_ready_at')->whereNotNull('started_at')
                ->get();

            $prepSecs = $items->map(fn ($i) => $i->started_at->diffInSeconds($i->item_ready_at))->filter();
            $avgPrep = $prepSecs->count() ? round($prepSecs->avg() / 60, 1) : 0;

            $green = $yellow = $red = 0;
            foreach ($items as $i) {
                $target = ($i->station->target_minutes ?? 10) * 60;
                $total = $i->item_ready_at && $i->fired_at ? $i->fired_at->diffInSeconds($i->item_ready_at)
                       : ($i->item_ready_at && $i->started_at ? $i->started_at->diffInSeconds($i->item_ready_at) : 0);
                if ($total <= $target * 0.6) {
                    $green++;
                } elseif ($total <= $target) {
                    $yellow++;
                } else {
                    $red++;
                }
            }
            $totalBumped = $green + $yellow + $red;
            $onTimePct = $totalBumped ? round(($green / $totalBumped) * 100, 1) : 0;

            // Order counts last 15 / 30 min.
            $last15 = Order::where('store_id', $rid)->where('created_at', '>=', now()->subMinutes(15))->count();
            $last30 = Order::where('store_id', $rid)->where('created_at', '>=', now()->subMinutes(30))->count();

            // Slowest items (by avg prep) + per-station avg.
            $byItem = $items->groupBy('item_id')->map(function ($g) {
                $avg = $g->map(fn ($i) => $i->started_at->diffInSeconds($i->item_ready_at))->avg();

                return ['name' => optional($g->first()->menu)->getRawOriginal('name') ?? '—', 'avg' => round($avg / 60, 1), 'count' => $g->count()];
            })->sortByDesc('avg')->take(8)->values();

            $byStation = $items->groupBy('station_id')->map(function ($g) {
                $avg = $g->map(fn ($i) => $i->started_at->diffInSeconds($i->item_ready_at))->avg();

                return ['name' => optional($g->first()->station)->name ?? ___('Unassigned'), 'avg' => round($avg / 60, 1), 'count' => $g->count()];
            })->sortByDesc('avg')->values();

            $report = compact('avgTicket', 'avgPrep', 'green', 'yellow', 'red', 'onTimePct', 'last15', 'last30', 'byItem', 'byStation') + ['orders' => $orders->count()];
        }

        return view('owner.kitchen.analytics', compact('stores', 'storeId', 'report', 'days'));
    }

    /** Fire a held course on an order (advanced mode / coursing). */
    public function fireCourse(Request $request, int $order, int $course)
    {
        $storeIds = $this->ownerRestaurantIds();
        $model = Order::whereIn('store_id', $storeIds)->findOrFail($order);
        $fired = KdsService::fireCourse($model->id, $course);

        return response()->json(['fired' => $fired]);
    }

    /** Resolve an order item that belongs to one of the owner's restaurants, or 404. */
    private function ownedItem(int $itemId): OrderItem
    {
        $storeIds = $this->ownerRestaurantIds();

        return OrderItem::with('order')
            ->whereHas('order', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->findOrFail($itemId);
    }
}
