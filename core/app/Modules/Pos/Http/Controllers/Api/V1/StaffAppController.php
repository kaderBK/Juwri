<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\KdsService;
use App\Services\SalesAnalyticsService;
use App\Services\StaffTwoFactorService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Team\Http\Middleware\StaffTokenAuth;
use Juwri\Modules\Team\Models\StaffToken;

/**
 * Staff app (POS / KDS / waiter), authenticated by {@see StaffTokenAuth}.
 * Actions are scoped to the staff member's restaurant and gated by their role
 * permissions (orders, kds, …).
 */
class StaffAppController extends Controller
{
    use CapsPerPage; // A5-3
    use FormatsOrders;

    private function staff(): StaffMember
    {
        return app('api.staff');
    }

    /** abort(403) unless the staff member holds the permission. */
    private function require(string $permission): void
    {
        if (! $this->staff()->hasPermission($permission)) {
            abort(response()->json(['error' => "Your role lacks the '{$permission}' permission."], 403));
        }
    }

    /** GET /staff/me */
    public function me()
    {
        $s = $this->staff();
        $all = ['orders', 'menu_edit', 'coupons', 'analytics', 'kds', 'customers'];

        return response()->json([
            'id' => $s->id,
            'name' => $s->name,
            'role' => $s->role,
            'store_id' => $s->store_id,
            'branch_id' => $s->branch_id,
            'station_id' => $s->station_id,
            'permissions' => array_values(array_filter($all, fn ($p) => $s->hasPermission($p))),
        ]);
    }

    // ── Orders (permission: orders) ────────────────────────────────────────────

    public function orders(Request $request)
    {
        $this->require('orders');

        $orders = Order::where('store_id', $this->staff()->store_id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 30));

        return response()->json($orders->through(fn ($o) => $this->orderRow($o)));
    }

    public function updateOrderStatus(Request $request, int $orderId)
    {
        $this->require('orders');
        $order = Order::where('store_id', $this->staff()->store_id)->findOrFail($orderId);

        $status = $request->validate([
            'status' => 'required|in:new,preparing,ready,delivered,completed,cancelled',
        ])['status'];
        $order->update(['status' => $status]);

        try {
            WebhookService::dispatch($this->staff()->owner_id, 'order.status_changed', [
                'order_id' => $order->id,
                'new_status' => $status,
                'by_staff' => $this->staff()->id,
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['id' => $order->id, 'status' => $status]);
    }

    // ── KDS (permission: kds) ──────────────────────────────────────────────────

    /** GET /staff/kds — live kitchen tickets, optionally scoped to a station. */
    public function kdsTickets(Request $request)
    {
        $this->require('kds');
        $s = $this->staff();
        $stationId = $request->query('station_id') ?: $s->station_id;

        // P6-09 V-15: the same kitchen set as the web rail — no parked, unreleased, unpaid (pay-link) or voided orders.
        $items = OrderItem::whereHas('order', fn ($q) => $q->where('store_id', $s->store_id)->live()->whereNull('held_at')
            ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['cancelled', 'unpaid'])))
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
            'placed_at' => $rows->first()->order?->created_at,
            'items' => $rows->map(fn ($it) => [
                'id' => $it->id,
                'name' => $it->menu?->getRawOriginal('name'),
                'quantity' => $it->quantity,
                'status' => $it->status,
                'note' => $it->order_note,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['data' => $tickets]);
    }

    /** PUT /staff/kds/items/{itemId}/bump — advance one KDS step. */
    public function kdsBump(int $itemId)
    {
        $this->require('kds');
        $item = $this->ownedItem($itemId);
        KdsService::bumpItem($item);

        return response()->json(['id' => $item->id, 'status' => $item->fresh()->status]);
    }

    /** PUT /staff/kds/items/{itemId}/recall — step one KDS status back. */
    public function kdsRecall(int $itemId)
    {
        $this->require('kds');
        $item = $this->ownedItem($itemId);
        KdsService::recallItem($item);

        return response()->json(['id' => $item->id, 'status' => $item->fresh()->status]);
    }

    // ── Menu editing (permission: menu_edit) ───────────────────────────────────

    public function storeMenuItem(Request $request)
    {
        $this->require('menu_edit');
        $item = MenuAdminService::createItem($this->staff()->store_id, $request->validate(MenuAdminService::itemRules(true)));

        return response()->json(MenuAdminService::itemArray($item), 201);
    }

    public function updateMenuItem(Request $request, int $itemId)
    {
        $this->require('menu_edit');
        $item = Menu::where('store_id', $this->staff()->store_id)->findOrFail($itemId);
        $item = MenuAdminService::updateItem($item, $request->validate(MenuAdminService::itemRules(false)));

        return response()->json(MenuAdminService::itemArray($item));
    }

    public function deleteMenuItem(int $itemId)
    {
        $this->require('menu_edit');
        Menu::where('store_id', $this->staff()->store_id)->findOrFail($itemId)->delete();

        return response()->json(['deleted' => true]);
    }

    /** PATCH /staff/menu/items/{itemId}/sold-out — quick 86 from the floor. */
    public function toggleSoldOut(Request $request, int $itemId)
    {
        $this->require('menu_edit');
        $item = Menu::where('store_id', $this->staff()->store_id)->findOrFail($itemId);
        $force = $request->has('is_sold_out') ? $request->boolean('is_sold_out') : null;
        $item = MenuAdminService::toggleSoldOut($item, $force);

        return response()->json(['id' => $item->id, 'is_sold_out' => (bool) $item->is_sold_out]);
    }

    public function storeMenuCategory(Request $request)
    {
        $this->require('menu_edit');
        $category = MenuAdminService::createCategory($this->staff()->store_id, $request->validate(MenuAdminService::categoryRules(true)));

        return response()->json(['id' => $category->id, 'name' => $category->name], 201);
    }

    // ── Analytics (permission: analytics) ───────────────────────────────────────

    public function analytics(Request $request)
    {
        $this->require('analytics');

        return response()->json(
            SalesAnalyticsService::summary($this->staff()->store_id, $request->query('from'), $request->query('to'))
        );
    }

    /** POST /staff/logout — revoke this device's token. */
    public function logout()
    {
        /** @var StaffToken $token */
        $token = app('api.staff_token');
        // P6-269: this device's push token goes with its sign-in.
        app(MobilePush::class)->revokeDevices(['staff_token_id' => (int) $token->id]);
        $token->delete();

        return response()->json(['success' => true]);
    }

    /** HPMS E11: register this staff device for native push (Bearer clients). */
    public function registerDevice(Request $request): JsonResponse
    {
        $s = $this->staff();
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);
        try {
            $sub = app(MobilePush::class)->registerDevice(
                (int) $s->store_id, $data['token'], $data['platform'] ?? 'fcm',
                ['staff_token_id' => (int) app('api.staff_token')->id], (int) $s->id, null
            );
        } catch (ValidationException $e) {
            return response()->json(['error' => 'feature_disabled', 'message' => $e->getMessage()], 403);
        }

        return response()->json(['ok' => true, 'id' => $sub->id]);
    }

    /** HPMS E11: unregister a staff device by its push token. */
    public function unregisterDevice(Request $request): JsonResponse
    {
        $s = $this->staff();
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $removed = PushSubscription::where('store_id', $s->store_id)
            ->where('endpoint_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['ok' => true, 'removed' => (bool) $removed]);
    }

    /** HPMS E12(b): begin TOTP enrolment — returns secret + otpauth + inline QR. */
    public function twoFactorSetup(): JsonResponse
    {
        $data = StaffTwoFactorService::enrollStart($this->staff());

        return response()->json(['secret' => $data['secret'], 'otpauth' => $data['otpauth'], 'qr' => $data['qr']]);
    }

    /** HPMS E12(b): confirm enrolment with a code; returns single-use recovery codes. */
    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $codes = StaffTwoFactorService::confirm($this->staff(), $code);
        if ($codes === null) {
            return response()->json(['error' => 'invalid_code'], 422);
        }

        return response()->json(['ok' => true, 'recovery_codes' => $codes]);
    }

    private function ownedItem(int $itemId): OrderItem
    {
        return OrderItem::whereHas('order', fn ($q) => $q->where('store_id', $this->staff()->store_id))
            ->findOrFail($itemId);
    }
}
