<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Features;

use App\Models\Customer;
use App\Models\MenuReview;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\MenuReviewMedia;

/**
 * F6 · Rich reviews backend.
 *  - GET  /r/{store}/item/{menu}/reviews  — paginated list + distribution + averages
 *  - POST /r/{store}/item/{menu}/reviews  — write a review (rating, text, photos)
 *
 * A logged-in diner (CustomerToken) gets their review linked + verified against
 * their delivered-order history; anonymous diners fall back to session_hash like today.
 */
class MenuReviewController extends Controller
{
    /** Order statuses that count as "delivered" for verified-purchase. */
    private const DELIVERED_STATUSES = ['delivered'];

    public function index(Request $request, Store $store, Menu $menu)
    {
        $this->ensureItemBelongs($store, $menu);

        $filter = $request->query('filter');

        $query = MenuReview::query()
            ->approved()
            ->forItem($menu->id)
            ->applyFilter($filter)
            ->with(['photos', 'customer:id,name'])
            ->orderByDesc('created_at');

        $reviews = $query->paginate(min((int) $request->query('per_page', 10), 50));

        return response()->json([
            'data' => $reviews->through(fn (MenuReview $r) => $this->present($r))->items(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'filter' => $filter ?? 'latest',
            ],
            'distribution' => $this->distribution($menu->id),
            'average' => $this->average($menu->id),
            'count' => $this->approvedCount($menu->id),
        ]);
    }

    public function store(Request $request, Store $store, Menu $menu)
    {
        $this->ensureItemBelongs($store, $menu);

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'text' => 'nullable|string|max:2000',
            'name' => 'nullable|string|max:80',
            'photos' => 'nullable|array|max:6',
            'photos.*' => 'integer',
        ]);

        // A logged-in customer — resolved from the PER-REQUEST attribute that
        // OptionalCustomerToken sets (never the container, whose 'api.customer'
        // binding can leak across requests in tests / long-lived workers).
        $customerId = $request->attributes->get('_customer_id');
        $customer = $customerId ? Customer::find($customerId) : null;

        $verified = $customer
            ? $this->hasDeliveredPurchase($customer->id, $menu->id, $store->id)
            : false;

        $review = new MenuReview;
        $review->menu_id = $menu->id;
        $review->store_id = $store->id;
        $review->customer_id = $customer?->id;
        $review->reviewer_name = $customer->name ?? ($data['name'] ?? null);
        $review->stars = $data['rating'];
        $review->body = $data['text'] ?? null;
        $review->status = 'pending';
        $review->is_verified = $verified;
        $review->session_hash = $customer ? null : $this->sessionHash($request);
        $review->save();

        // Attach photos — only MediaAssets owned by this restaurant may be linked.
        $this->attachPhotos($review, $store->id, $data['photos'] ?? []);

        return response()->json([
            'ok' => true,
            'review' => $this->present($review->fresh(['photos', 'customer:id,name'])),
        ], 201);
    }

    /* -------------------------------------------------------------- helpers */

    /** Distribution: count of approved reviews per 1..5 stars, always all five keys present. */
    private function distribution(int $menuId): array
    {
        $rows = MenuReview::query()
            ->approved()
            ->forItem($menuId)
            ->select('stars', DB::raw('COUNT(*) as total'))
            ->groupBy('stars')
            ->pluck('total', 'stars');

        $out = [];
        for ($star = 5; $star >= 1; $star--) {
            $out[$star] = (int) ($rows[$star] ?? 0);
        }

        return $out;
    }

    private function average(int $menuId): float
    {
        $avg = MenuReview::query()->approved()->forItem($menuId)->avg('stars');

        return round((float) ($avg ?? 0), 2);
    }

    private function approvedCount(int $menuId): int
    {
        return MenuReview::query()->approved()->forItem($menuId)->count();
    }

    /**
     * Verified-purchase check: does this customer have a DELIVERED order that
     * contains a line item for this menu item, within this restaurant?
     * Uses the query builder / relations — no hardcoded raw table names.
     */
    private function hasDeliveredPurchase(int $customerId, int $menuId, int $storeId): bool
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('item_id', $menuId))
            ->exists();
    }

    private function attachPhotos(MenuReview $review, int $storeId, array $assetIds): void
    {
        if (empty($assetIds)) {
            return;
        }

        // Restrict to real, restaurant-owned assets; preserve submitted order.
        $owned = MediaAsset::query()
            ->where('store_id', $storeId)
            ->whereIn('id', $assetIds)
            ->pluck('id')
            ->all();

        $position = 0;
        foreach ($assetIds as $assetId) {
            if (! in_array((int) $assetId, $owned, true)) {
                continue;
            }
            MenuReviewMedia::create([
                'review_id' => $review->id,
                'media_asset_id' => (int) $assetId,
                'position' => $position++,
            ]);
        }
    }

    private function present(MenuReview $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->reviewer_name,
            'stars' => (int) $r->stars,
            'body' => $r->body,
            'is_verified' => (bool) $r->is_verified,
            'created_at' => optional($r->created_at)->toIso8601String(),
            'photos' => $r->relationLoaded('photos')
                ? $r->photos->map(fn (MediaAsset $a) => ['id' => $a->id, 'url' => $a->url])->all()
                : [],
        ];
    }

    private function ensureItemBelongs(Store $store, Menu $menu): void
    {
        if ((int) $menu->store_id !== (int) $store->id) {
            abort(404);
        }
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->ip().'|'.(string) $request->userAgent());
    }
}
