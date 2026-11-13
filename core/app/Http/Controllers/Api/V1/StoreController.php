<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Http\Controllers\Api\V1\Concerns\FiltersByDate;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Reservation;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Verticals\Http\Controllers\Api\V1\Concerns\SummarizesVertical;

class StoreController extends Controller
{
    use CapsPerPage; // A5-3

    // P6-339: validated `?from` / `?to` / `?since` query-string date filters,
    // shared with reservations() so the two lists answer identically. The
    // trait throws ValidationException on garbage/array values (renders as 422
    // on api/*) and is DST-correct via StoreClock for date-only inputs.
    use FiltersByDate;

    // rev/mv-surface-api P1: `store_type` + vertical capabilities, shared with
    // the token-scoped StorefrontController so both surfaces return one shape.
    use SummarizesVertical;

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('_api_user_id');
    }

    public function index(Request $request)
    {
        $stores = Store::where('user_id', $this->userId($request))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 20));

        return response()->json($stores->through(fn ($r) => array_merge([
            'id' => $r->id,
            'title' => $r->title,
            'slug' => $r->slug,
            'url' => route('publicView', $r->slug),
            'template' => store_options($r->id, 'store_template'),
        ], $this->verticalSummary($r), [
            'created_at' => $r->created_at,
        ])));
    }

    public function show(Request $request, int $id)
    {
        $store = Store::where('user_id', $this->userId($request))->findOrFail($id);
        $categories = MenuCategory::where('store_id', $id)->orderBy('position')->get();

        return response()->json(array_merge([
            'id' => $store->id,
            'title' => $store->title,
            'slug' => $store->slug,
        ], $this->verticalSummary($store), [
            'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
            'items_count' => Menu::where('store_id', $id)->count(),
        ]));
    }

    public function menu(Request $request, int $id)
    {
        $store = Store::where('user_id', $this->userId($request))->findOrFail($id);
        $isFood = $store->isFoodVertical();
        // rev/mv-surface-api P1: resolve the store's capabilities once so the
        // serializer emits only the attrs this vertical actually has (OTC
        // expiry/age for pharmacy, fitment for car-parts) — same source as the
        // verticalSummary block the payload's callers read.
        $caps = $this->verticalSummary($store)['vertical']['capabilities'];

        $categories = MenuCategory::where('store_id', $id)->orderBy('position')->get();
        $itemsQuery = Menu::where('store_id', $id)->where('active', '1'); // enum('0','1'): string, not index
        if (! $isFood) {
            // Goods/services verticals: eager-load what the non-food branch below
            // reads so it isn't an N+1 per item. Fitments only exist for car-parts,
            // so only pay for that join when the vertical declares the capability.
            $with = ['brand', 'variants'];
            if ($caps['fitment']) {
                $with[] = 'fitments';
            }
            $itemsQuery->with($with);
        }
        // P6-277: paged mode — only when the client asks for a page (`per_page` or
        // `page`), paginate the items in SQL and return the `data`/`meta`/`links`
        // envelope; otherwise keep the legacy bare category array unchanged.
        if ($request->has('per_page') || $request->has('page')) {
            $items = $itemsQuery->orderBy('id')->paginate($this->perPage($request, 50, 200));
            $byCategory = $items->groupBy('category_id');

            return response()->json([
                'data' => $categories
                    ->filter(fn ($c) => $byCategory->has($c->id))
                    ->map(fn ($c): array => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'items' => $byCategory[$c->id]
                            ->map(fn ($m) => $this->serializeMenuItem($m, $isFood, $caps))
                            ->values(),
                    ])
                    ->values(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'last_page' => $items->lastPage(),
                ],
                'links' => [
                    'next' => $items->nextPageUrl(),
                    'prev' => $items->previousPageUrl(),
                ],
            ]);
        }

        $items = $itemsQuery->get();
        $byCategory = $items->groupBy('category_id');

        return response()->json($categories->map(fn ($c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'items' => ($byCategory[$c->id] ?? collect())
                ->map(fn ($m) => $this->serializeMenuItem($m, $isFood, $caps))
                ->values(),
        ]));
    }

    /**
     * Serialize one menu item for the API. Food stores keep the legacy shape
     * (is_vegan/is_halal/calories); goods/services verticals expose the generic
     * attrs that actually exist (brand/weight/stock/variants) plus the
     * capability-gated OTC (expiry/age/batch) and car-parts fitment fields.
     *
     * @param  array<string,bool>  $caps
     * @return array<string, mixed>
     */
    private function serializeMenuItem(Menu $m, bool $isFood, array $caps = []): array
    {
        $row = [
            'id' => $m->id,
            'name' => $m->getRawOriginal('name'),
            'price' => (float) $m->price,
            'is_sold_out' => (bool) $m->is_sold_out,
            'is_popular' => (bool) $m->is_popular,
        ];

        if ($isFood) {
            $row['is_vegan'] = (bool) $m->is_vegan;
            $row['is_halal'] = (bool) $m->is_halal;
            $row['calories'] = $m->calories;

            return $row;
        }

        if ($m->brand) {
            $row['brand'] = ['id' => $m->brand->id, 'name' => $m->brand->name];
        }
        if ($m->weight !== null) {
            $row['weight'] = (float) $m->weight;
        }
        if ($m->track_inventory && $m->stock_qty !== null) {
            $row['stock'] = (int) $m->stock_qty;
        }
        $row['has_variants'] = $m->variants->isNotEmpty();
        if ($row['has_variants']) {
            $row['variant_count'] = $m->variants->count();
            $row['variants_in_stock'] = $m->variants
                ->filter(fn ($v) => $v->stock_qty === null || $v->stock_qty > 0)
                ->count();
        }

        // OTC compliance (pharmacy): only meaningful where the vertical age-gates
        // or tracks expiry, so a retail item never carries empty pharmacy keys.
        if (! empty($caps['age_gate'])) {
            $row['age_restricted'] = (bool) $m->age_restricted;
        }
        if (! empty($caps['expiry'])) {
            $row['expiry_date'] = $m->expiry_date?->toDateString();
            if ($m->batch_no !== null && $m->batch_no !== '') {
                $row['batch_no'] = $m->batch_no;
            }
        }

        // Vehicle fitment (car-parts): the year/make/model rows a YMM filter needs.
        if (! empty($caps['fitment']) && $m->relationLoaded('fitments') && $m->fitments->isNotEmpty()) {
            $row['fitments'] = $m->fitments->map(fn ($f) => [
                'year' => $f->year,
                'make' => $f->make,
                'model' => $f->model,
                'submodel' => $f->submodel,
                'label' => $f->label,
            ])->values();
        }

        return $row;
    }

    public function orders(Request $request, int $id)
    {
        Store::where('user_id', $this->userId($request))->findOrFail($id);

        $query = Order::where('store_id', $id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));
        // P6-339: from/to/since go through the validated DST-aware helper
        // instead of straight into where(); the trait throws ValidationException
        // on garbage/array inputs (renders as 422 on api/*) and treats
        // date-only `to` as < start-of-next-day so the whole day ships.
        // Orders have no updated_at — created_at is the order's fixed timeline
        // anchor for both `from` (lower bound) and `since` (incremental poll).
        $this->applyDateFilters($query, 'created_at', $request, $id);

        $orders = $query
            ->with(['items.menu', 'items.variant'])
            // F0-4: newest-first with a stable `id` tiebreak so orders sharing a
            // created_at second cannot reorder between polls — Zapier/Make dedup on
            // the top-of-list `id`, and a flapping order would double-fire or miss.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 30));

        return response()->json($orders->through(fn ($o) => [
            'id' => $o->id,
            'status' => $o->status,
            'type' => $o->type,
            'table_number' => $o->table_number,
            'customer_name' => $o->customer_name,
            'total' => $o->total,
            'is_paid' => (bool) $o->is_paid,
            'created_at' => $o->created_at,
        ]));
    }

    /**
     * F0-4: owner-token reservations list — the polling source a Zapier/Make/n8n
     * "New Reservation" trigger falls back to (reservation.created). Newest-created
     * first with an `id` tiebreak, capped `per_page`, `status` + `since` (created_at)
     * filters like the orders list. Gated `feature:reservations` at the route.
     */
    public function reservations(Request $request, int $id): JsonResponse
    {
        Store::where('user_id', $this->userId($request))->findOrFail($id);

        $query = Reservation::where('store_id', $id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));
        // P6-339: same validated filter helper as orders() — see FiltersByDate.
        $this->applyDateFilters($query, 'created_at', $request, $id);

        $reservations = $query
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 20));

        return response()->json($reservations->through(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'phone' => $r->phone,
            'email' => $r->email,
            'party_size' => $r->party_size,
            'reserved_at' => $r->reserved_at,
            'table_number' => $r->table_number,
            'status' => $r->status,
            'created_at' => $r->created_at,
        ]));
    }

    public function updateOrderStatus(Request $request, int $storeId, int $orderId)
    {
        Store::where('user_id', $this->userId($request))->findOrFail($storeId);
        $order = Order::where('store_id', $storeId)->findOrFail($orderId);

        $status = $request->validate(['status' => 'required|in:new,preparing,ready,delivered,completed,cancelled'])['status'];
        $order->update(['status' => $status]);

        // Dispatch webhook
        try {
            WebhookService::dispatch($this->userId($request), 'order.status_changed', [
                'order_id' => $orderId,
                'new_status' => $status,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        // F0-7: return the full order ROW (the same shape GET .../orders lists), not
        // a {id, status} stub — a Zapier/Make "Update Order Status" action step feeds
        // its output to later steps, which need the record, not just the new status.
        $order->refresh();

        return response()->json([
            'id' => $order->id,
            'status' => $order->status,
            'type' => $order->type,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'total' => $order->total,
            'currency' => $order->currencyCode(),
            'is_paid' => (bool) $order->is_paid,
            'created_at' => $order->created_at,
        ]);
    }
}
