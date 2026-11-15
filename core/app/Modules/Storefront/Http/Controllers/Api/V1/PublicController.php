<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\PlanMenuVisibility;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use App\Support\DriverExposure;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;

/**
 * Unauthenticated public endpoints for a customer app: browse a restaurant's
 * menu, and track an order via its unguessable 32-char `track_token` (no login
 * required to follow an order the customer placed).
 */
class PublicController extends Controller
{
    /** GET /menu/{storeId} — public active menu grouped by category. */
    public function menu(int $storeId)
    {
        $store = Store::findOrFail($storeId);

        // WI-M8: answer exactly what the web storefront answers. StoreController::publicView
        // 404s a suspended owner or an inactive plan; this endpoint served them anyway, so a
        // suspended merchant kept a working public menu for the customer app and the AI
        // integrations. MUST run before the If-None-Match branch below: a 304 to a client
        // holding the pre-suspension ETag is as good as serving the menu.
        if (StorefrontAvailability::apiGateEnabled()
            && ! StorefrontAvailability::servable($store)) {
            abort(404);
        }

        // A5-2: conditional GET — agents/headless storefronts poll this. The ETag reuses
        // the P5 menu-cache signature (bumps on any menu write), so a 304 is safe.
        $request = request();
        $etag = '"'.StorefrontMenuCache::signature((int) $store->id, null, get_lang()).'"';
        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304)->setEtag(trim($etag, '"'))->header('Cache-Control', 'public, max-age=120');
        }

        $categories = MenuCategory::where('store_id', $store->id)
            ->where('active', 1)->orderBy('position')->get();

        // rev/mv-surface-api P1: the storefront-token + public menu read is served
        // from HERE (StorefrontController@menu delegates), so it must expose the
        // SAME goods attributes the owner-token menu does — otherwise one store
        // returns two different shapes. Resolve the vertical's capabilities once
        // and eager-load only the goods relations the branch below reads.
        $registry = app(VerticalRegistry::class);
        $vConfig = $registry->config($store->store_type ?: $registry->default());
        $isFood = $store->isFoodVertical();
        $caps = [
            'fitment' => (bool) ($vConfig['fitment'] ?? false),
            'expiry' => (bool) ($vConfig['expiry'] ?? false),
            'age_gate' => (bool) ($vConfig['age_gate'] ?? false),
        ];

        // `active` is enum('0','1'); the string '1' is required — where('active', 1) would
        // be read as the enum INDEX 1 (i.e. the value '0'), matching only inactive items.
        $eager = ['variants', 'attachedExtras', 'extras', 'attachedVariantOptions', 'variantOptions'];
        if (! $isFood) {
            $eager[] = 'brand';
            if ($caps['fitment']) {
                $eager[] = 'fitments';
            }
        }
        $items = Menu::where('store_id', $store->id)->where('active', '1')
            // N+1: eager-load the relations resolved_variant_options / resolved_extras
            // read below, so each item resolves from memory, not 4 queries per item.
            ->with($eager)->get();

        /* Plan caps on what this store PUBLISHES (read-side twin of the write
           limit): a store that holds more than its plan covers — a menu built
           during an unlimited trial, or written by a bulk path — keeps every
           row, but only the rows the plan pays for are served. Nothing is
           deleted, so an upgrade reveals the rest on the next request. */
        [$items, $categories] = PlanMenuVisibility::applyFlat($items, $categories, $store->user);

        $byCategory = $items->groupBy('category_id');

        $rid = $store->id;

        return response()->json([
            'store' => [
                'id' => $store->id,
                'title' => $store->title,
                'slug' => $store->slug,
                'store_type' => $store->store_type ?: $registry->default(),
            ],
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'items' => ($byCategory[$c->id] ?? collect())->where('is_sold_out', '!=', 1)
                    ->map(fn ($m) => $this->publicMenuItemArray($m, $rid, $isFood, $caps))
                    ->values(),
            ])->values(),
        ])->setEtag(trim($etag, '"'))->header('Cache-Control', 'public, max-age=120');
    }

    /**
     * Serialize one public menu item. Food stores keep the legacy shape
     * (is_vegan/is_halal/is_gluten_free/calories); goods/services verticals swap
     * in the attrs they actually have (brand/weight/stock + capability-gated
     * OTC expiry/age/batch and car-parts fitment). Variants / choice groups /
     * extras are shared by every vertical (sizes, colours, add-ons).
     *
     * @param  array<string,bool>  $caps
     * @return array<string,mixed>
     */
    private function publicMenuItemArray(Menu $m, int $rid, bool $isFood, array $caps): array
    {
        $row = [
            'id' => $m->id,
            'name' => $m->getRawOriginal('name'),
            'description' => $m->description,
            'price' => (float) $m->price,
            'image' => $m->image ? media_url('storage/menu/', $m->image, $rid) : null,
            'gallery' => collect($m->gallery_images ?? [])
                ->filter()
                ->map(fn ($g) => media_url('storage/menu/', $g, $rid))->values(),
            'is_popular' => (bool) $m->is_popular,
        ];

        if ($isFood) {
            $row['is_vegan'] = (bool) $m->is_vegan;
            $row['is_halal'] = (bool) $m->is_halal;
            $row['is_gluten_free'] = (bool) $m->is_gluten_free;
            $row['calories'] = $m->calories;
        } else {
            if ($m->brand) {
                $row['brand'] = ['id' => $m->brand->id, 'name' => $m->brand->name];
            }
            if ($m->weight !== null) {
                $row['weight'] = (float) $m->weight;
            }
            if ($m->track_inventory && $m->stock_qty !== null) {
                $row['stock'] = (int) $m->stock_qty;
            }
            if ($caps['age_gate']) {
                $row['age_restricted'] = (bool) $m->age_restricted;
            }
            if ($caps['expiry']) {
                $row['expiry_date'] = $m->expiry_date?->toDateString();
                if ($m->batch_no !== null && $m->batch_no !== '') {
                    $row['batch_no'] = $m->batch_no;
                }
            }
            if ($caps['fitment'] && $m->relationLoaded('fitments') && $m->fitments->isNotEmpty()) {
                $row['fitments'] = $m->fitments->map(fn ($f) => [
                    'year' => $f->year,
                    'make' => $f->make,
                    'model' => $f->model,
                    'submodel' => $f->submodel,
                    'label' => $f->label,
                ])->values();
            }
        }

        // Priced variant combos — `id` is the value OrderApiService expects as `variation`.
        $row['variants'] = $m->variants->where('active', true)->map(fn ($v) => [
            'id' => $v->id,
            'price' => (float) $v->price,
            'options' => $v->options,
        ])->values();
        // Choice groups (pivot + legacy union) with selection rules.
        $row['variant_groups'] = $m->resolved_variant_options->map(fn ($g) => [
            'id' => $g->id,
            'title' => $g->getRawOriginal('title'),
            'options' => $g->options,
            'required' => (bool) ($g->required ?? false),
            'min_select' => (int) ($g->min_select ?? 0),
            'max_select' => (int) ($g->max_select ?? 1),
            'option_prices' => $g->option_prices ?? [],
        ])->values();
        // Extras (pivot + legacy union, price override applied); `id` is the value
        // OrderApiService expects in `extras[]`.
        $row['extras'] = $m->resolved_extras->map(fn ($e) => [
            'id' => $e->id,
            'title' => $e->getRawOriginal('title'),
            'price' => (float) $e->price,
            'image' => ! empty($e->image) ? media_url('storage/menu/', $e->image, $rid) : null,
        ])->values();

        return $row;
    }

    /** GET /track/{token} — live order + delivery status by track token. */
    public function track(string $token)
    {
        $order = Order::where('track_token', $token)
            ->with(['items.menu', 'items.variant', 'driver', 'store', 'shipment'])
            ->firstOrFail();

        // rev/mv-surface-api P1: a `ship` (goods) order tracks by carrier +
        // tracking number, not a driver/delivery timeline. Expose the shipment so
        // the track page shows the right fulfillment story for non-food stores.
        $shipment = $order->shipment ? [
            'status' => $order->shipment->status,
            'carrier' => $order->shipment->carrier,
            'tracking_number' => $order->shipment->tracking_number,
            'shipped_at' => $order->shipment->shipped_at,
            'delivered_at' => $order->shipment->delivered_at,
        ] : null;

        $driver = null;
        // Only expose the driver (and live position) once the order is en route.
        // P6-236: only while the delivery is live (open order, en route, driver on
        // shift); the position only when fresh. Never after delivery/cancel.
        if (DriverExposure::isLive($order)) {
            $fresh = DriverExposure::locationVisible($order);
            $driver = [
                'name' => $order->driver->name,
                'phone' => $order->driver->phone,
                'lat' => $fresh ? $order->driver->last_lat : null,
                'lng' => $fresh ? $order->driver->last_lng : null,
                'location_updated_at' => $fresh ? $order->driver->last_location_at : null,
            ];
        }

        // T4: a third-party courier (Uber Direct / DoorDash) delivering this order —
        // expose its live position, ETA and tracking link so one track page serves all.
        $courier = null;
        $courierDelivery = CourierDelivery::where('order_id', $order->id)
            ->whereNotIn('status', ['canceled', 'failed'])->latest('id')->first();
        if ($courierDelivery) {
            $courier = [
                'provider' => $courierDelivery->provider,
                'status' => $courierDelivery->status,
                'eta_minutes' => $courierDelivery->eta_minutes,
                'tracking_url' => $courierDelivery->tracking_url,
                'lat' => $courierDelivery->courier_lat,
                'lng' => $courierDelivery->courier_lng,
                'location_updated_at' => $courierDelivery->courier_updated_at,
            ];
        }

        return response()->json([
            'id' => $order->id,
            'status' => $order->status,
            'type' => $order->type,
            'type_label' => app(FulfillmentRegistry::class)->label((string) $order->type),
            'store_type' => $order->store?->store_type,
            'delivery_status' => $order->delivery_status,
            'is_paid' => (bool) $order->is_paid,
            'total' => $order->total,
            'placed_at' => $order->created_at,
            'shipment' => $shipment,
            'timeline' => array_filter([
                'preparing_at' => $order->preparing_at,
                'ready_at' => $order->ready_at,
                'picked_up_at' => $order->picked_up_at,
                'out_for_delivery_at' => $order->out_for_delivery_at,
                'delivered_at' => $order->delivered_at,
                'completed_at' => $order->completed_at,
            ]),
            'items' => $order->items->map(fn ($it) => [
                'name' => $it->menu?->getRawOriginal('name'),
                'quantity' => $it->quantity,
            ])->values(),
            'driver' => $driver,
            'courier' => $courier,
        ]);
    }
}
