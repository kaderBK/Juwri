<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Ordering\Models\CustomerNotification;

/**
 * Customer notification-center inbox reads (rev/notif-center-spec.md).
 *
 * Behind auth:customer. Every query is scoped to the authenticated customer's id
 * — a diner can only ever see or mutate their own notifications, never another's
 * (the {storeId} route segment is structural only and is NOT trusted for
 * scoping). Guests hit neither endpoint; their bell is built client-side from
 * localStorage recent orders.
 */
class CustomerNotificationController extends Controller
{
    private const LIST_LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['notifications' => [], 'unread' => 0], 401);
        }

        $rows = CustomerNotification::where('customer_id', $customer->id)
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json([
            'notifications' => $rows->map(fn ($n) => [
                'id' => (int) $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'read' => $n->read_at !== null,
                'at' => optional($n->created_at)->toIso8601String(),
            ])->all(),
            'unread' => $this->unreadCount($customer->id),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['ok' => false], 401);
        }

        $query = CustomerNotification::where('customer_id', $customer->id)->whereNull('read_at');

        // Optional id filter — mark a specific subset, else the whole inbox.
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if ($ids) {
            $query->whereIn('id', $ids);
        }
        $query->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => $this->unreadCount($customer->id)]);
    }

    /**
     * The bell's badge count — a LIGHTWEIGHT poll: no rows, just the number,
     * so a storefront can keep the badge live without pulling the whole feed
     * every minute. Scoped to the authenticated customer exactly like the
     * feed above.
     */
    public function unread(Request $request): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['unread' => 0], 401);
        }

        return response()->json(['unread' => $this->unreadCount($customer->id)]);
    }

    private function unreadCount(int $customerId): int
    {
        return (int) CustomerNotification::where('customer_id', $customerId)->whereNull('read_at')->count();
    }
}
