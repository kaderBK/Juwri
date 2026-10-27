<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry;
use Juwri\Modules\Menu\Models\Menu;

/**
 * GAPS-RUN-2 B1-P4: owner POS bridge dashboard — connect Square/Clover (BYOK),
 * review the item mapping (our menu ↔ POS item), and read the push log. Tenant
 * scoped. Toast is partner-gated (shown "coming soon"). The whole surface sits
 * behind the pos_bridge kill-switch (feature: middleware on the route).
 */
class PosBridgeController extends Controller
{
    use ResolvesCurrentStore;

    /** Providers with real drivers this run. */
    private const SUPPORTED = ['square', 'clover'];

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $connections = PosBridgeConnection::where('store_id', $rid)->orderBy('provider')->get()->keyBy('provider');
        $menus = Menu::where('store_id', $rid)->orderBy('name')->get(['id', 'name']);
        $maps = PosItemMap::where('store_id', $rid)->get()->groupBy('menu_id');
        $log = PosOrderPush::where('store_id', $rid)->latest()->limit(50)->get();

        return view('owner.integrations.posbridge.index', [
            'rid' => $rid,
            'connections' => $connections,
            'providers' => self::SUPPORTED,
            'menus' => $menus,
            'maps' => $maps,
            'log' => $log,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function connect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:square,clover'],
            'external_location_id' => ['required', 'string', 'max:191'],
            'access_token' => ['required', 'string', 'max:2048'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $rid)], // P6-59b
        ]);

        abort_unless(PosBridgeRegistry::get($data['provider']) !== null, 422);

        PosBridgeConnection::updateOrCreate(
            ['provider' => $data['provider'], 'store_id' => $rid],
            ['branch_id' => $data['branch_id'] ?? null, 'external_location_id' => $data['external_location_id'], 'status' => 'active']
        );

        // BYOK token → encrypted Credential vault (feature = provider name).
        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => $data['provider'], 'provider' => $data['provider']],
            ['secrets' => ['access_token' => $data['access_token']], 'active' => true]
        );

        return back()->with('success', ___('POS connection saved.'));
    }

    public function disconnect(Request $request, string $provider)
    {
        $rid = $this->currentRestaurantId($request);
        PosBridgeConnection::where('store_id', $rid)->where('provider', $provider)->delete();
        Credential::where('store_id', $rid)->where('feature', $provider)->delete();

        return back()->with('success', ___('POS connection removed.'));
    }

    public function saveMapping(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:square,clover'],
            'menu_id' => ['required', 'integer'],
            'external_id' => ['nullable', 'string', 'max:191'],
        ]);
        // Ensure the menu belongs to this tenant.
        abort_unless(Menu::where('id', $data['menu_id'])->where('store_id', $rid)->exists(), 403);

        if (empty($data['external_id'])) {
            PosItemMap::where('provider', $data['provider'])->where('menu_id', $data['menu_id'])->delete();
        } else {
            PosItemMap::updateOrCreate(
                ['provider' => $data['provider'], 'menu_id' => $data['menu_id']],
                ['store_id' => $rid, 'external_id' => $data['external_id']]
            );
        }

        return back()->with('success', ___('Item mapping saved.'));
    }

    /** M5: re-dispatch a failed POS push. Tenant-scoped; the (order_id, provider) ledger keeps it safe. */
    public function retryPush(Request $request, int $push): RedirectResponse
    {
        $rid = $this->currentRestaurantId($request);
        // pos_order_pushes.store_id is nullable; a legacy NULL row fails the scope
        // and 404s — fail-closed is the correct trade for a tenant-scoped action.
        $row = PosOrderPush::whereKey($push)->where('store_id', $rid)->firstOrFail();

        if ($row->isPushed()) {
            return back()->with('success', ___('This order is already in the POS.'));
        }

        PushPosOrderJob::dispatch((int) $row->order_id, (string) $row->provider);

        return back()->with('success', ___('Retrying the POS push.'));
    }
}
