<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\StoreReview;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * DIRECTORY T2-3: the owner dashboard's "Reviews" panel — read own approved
 * store_reviews and post/edit ONE reply each. Distinct from
 * DashboardController::reviews() (that page moderates item-level MenuReview
 * rows the owner controls directly; store_reviews moderation is
 * superadmin-only per D3, so there is no approve/reject here).
 */
class StoreReviewController extends Controller
{
    /** Restaurant IDs owned by the current user — the tenant-isolation fence every action checks. */
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // BL-036: a staff session is bound to its own store
    }

    public function index(Request $request, $store)
    {
        $store = Store::whereIn('id', dashboard_store_ids()) // BL-036: staff see their own store only
            ->where(function ($q) use ($store) {
                $q->idOrSlug($store);
            })->firstOrFail();

        $reviews = StoreReview::forStore($store->id)->approved()->ranked()
            ->with(['photos', 'customer:id,name'])
            ->paginate(20);

        return view('owner.customers.store-reviews', compact('store', 'reviews'));
    }

    /**
     * One reply per review, editable within 24h of the FIRST reply (the clock
     * never resets on an edit — otherwise "editable within 24h" never closes).
     * Cross-tenant access is a plain 403, never a 404, so the test asserting
     * tenant isolation (house rule) reads as the isolation check it is.
     */
    public function reply(Request $request, $store, int $review)
    {
        $review = StoreReview::findOrFail($review);

        abort_unless(in_array((int) $review->store_id, $this->ownerRestaurantIds(), true), 403);
        abort_unless($review->status === 'approved', 404);

        if ($review->owner_replied_at && $review->owner_replied_at->diffInHours(now()) >= 24) {
            return back()->withErrors(['reply' => ___('The 24-hour reply window has closed.')]);
        }

        $data = $request->validate(['reply' => 'required|string|max:1000']);

        $review->owner_reply = strip_tags($data['reply']);
        $review->owner_replied_at = $review->owner_replied_at ?: now();
        $review->save();

        return back()->with('success', ___('Reply posted.'));
    }
}
