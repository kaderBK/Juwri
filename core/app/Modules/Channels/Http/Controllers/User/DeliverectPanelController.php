<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Services\Aggregators\DeliverectProvider;
use Juwri\Modules\Menu\Models\Menu;

/**
 * D2 (integrations pack): the owner-facing Deliverect panel, living under the
 * Integrations hub. Per-location link rows, menu-sync trigger, PLU health (items
 * with no `external_sku` mapping), busy-mode toggle, and the snoozed-item list.
 *
 * Gate: `feature:aggregator_deliverect` (a standalone per-provider key — the page
 * is under the `integrations` hub, deliberately NOT coupled to `aggregator_hub`).
 * Every mutation re-resolves the connection/menu against the owner's restaurant
 * ids, so a tampered id from another tenant 404s rather than acting.
 *
 * V1 is manual-only: "Sync now" records the intent (menu_sync_state) and the
 * outbound Deliverect Store-API call fires once staging creds land (lane A / A9);
 * busy + snooze persist onto the connection through the same no-op-safe seams the
 * staff PWA uses (DeliverectStaffController).
 */
class DeliverectPanelController extends Controller
{
    /** @return array<int, int> restaurant ids the acting owner controls. */
    private function storeIds(): array
    {
        return dashboard_store_ids();
    }

    private function currentRestaurantId(Request $request): int
    {
        $ids = $this->storeIds();
        $rid = (int) ($request->query('store_id') ?: (dashboard_current_store()->id ?? $ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        return $rid;
    }

    /** A Deliverect connection the owner actually controls, or 404. */
    private function ownedConnection(int $id): AggregatorConnection
    {
        return AggregatorConnection::where('provider', 'deliverect')
            ->whereIn('store_id', $this->storeIds())
            ->findOrFail($id);
    }

    /** A menu item the owner actually controls, or 404. */
    private function ownedMenu(int $id): Menu
    {
        return Menu::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $rid = $this->currentRestaurantId($request);

        $connections = AggregatorConnection::where('provider', 'deliverect')
            ->where('store_id', $rid)->orderBy('id')->get();

        // PLU health: items with no partner SKU mapped. Deliverect still resolves
        // these by numeric id, so this is a "tidy your catalog" signal, not an error.
        $missing = Menu::where('store_id', $rid)->whereNull('external_sku')
            ->orderBy('name')->get(['id', 'name']);
        $totalItems = Menu::where('store_id', $rid)->count();

        // Busy + snooze are channel-wide; read them off the first connection.
        // optional() keeps this null-safe when the store has no connection yet.
        $settings = (array) (optional($connections->first())->settings ?? []);
        $busy = (bool) ($settings['busy'] ?? false);
        $snoozedIds = array_keys((array) ($settings['snoozed_plus'] ?? []));
        $snoozed = $snoozedIds
            ? Menu::whereIn('id', $snoozedIds)->get(['id', 'name'])
            : collect();

        return view('owner.integrations.deliverect', [
            'rid' => $rid,
            'connections' => $connections,
            'missing' => $missing,
            'totalItems' => $totalItems,
            'busy' => $busy,
            'snoozed' => $snoozed,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    /** POST — manual "Sync now": record the intent (outbound push deferred to A9). */
    public function sync(Request $request, int $id): RedirectResponse
    {
        $conn = $this->ownedConnection($id);
        $conn->menu_sync_state = 'pending';
        $conn->last_synced_at = now();
        $conn->sync_error = null;
        $conn->save();

        return back()->with('success', ___('Menu sync requested.'));
    }

    /** POST — inline PLU fix: map one menu item's partner SKU. */
    public function setSku(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'menu_id' => 'required|integer',
            'external_sku' => 'nullable|string|max:191',
        ]);
        $menu = $this->ownedMenu((int) $data['menu_id']);
        $menu->external_sku = $data['external_sku'] !== null && $data['external_sku'] !== ''
            ? $data['external_sku'] : null;
        $menu->save();

        return back()->with('success', ___('SKU updated.'));
    }

    /** POST — flip busy mode across every Deliverect channel for the restaurant. */
    public function busy(Request $request): RedirectResponse
    {
        $rid = $this->currentRestaurantId($request);
        $busy = $request->boolean('busy');
        $provider = new DeliverectProvider;
        AggregatorConnection::where('provider', 'deliverect')->where('store_id', $rid)
            ->get()->each(fn ($conn) => $provider->setBusy($conn, $busy));

        return back()->with('success', $busy ? ___('Busy mode on.') : ___('Busy mode off.'));
    }

    /** POST — un-snooze (un-86) one item on every Deliverect channel. */
    public function unsnooze(Request $request): RedirectResponse
    {
        $rid = $this->currentRestaurantId($request);
        $menuId = (int) $request->input('menu_id');
        $provider = new DeliverectProvider;
        AggregatorConnection::where('provider', 'deliverect')->where('store_id', $rid)
            ->get()->each(fn ($conn) => $provider->snoozeProduct($conn, $menuId, false));

        return back()->with('success', ___('Item un-snoozed.'));
    }
}
