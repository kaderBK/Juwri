<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnClientFactory;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnImporter;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * N4-P2 — Marn owner UI: connect (BYOK ClientID/Secret + BrandKey + LocationKey),
 * hold-mode toggle, dry-run import wizard, item-mapping review, push log. Tenant-scoped,
 * behind the pos_bridge gate.
 */
class MarnController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $connection = PosBridgeConnection::where('store_id', $rid)->where('provider', 'marn')->first();

        return view('owner.integrations.posbridge.marn', [
            'rid' => $rid,
            'connection' => $connection,
            'connected' => $connection && $connection->status === 'active',
            'maps' => PosItemMap::where('provider', 'marn')->where('store_id', $rid)->get(),
            'menus' => Menu::where('store_id', $rid)->orderBy('name')->get(['id', 'name']),
            'log' => PosOrderPush::where('store_id', $rid)->where('provider', 'marn')->latest()->limit(50)->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function connect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:191'],
            'client_secret' => ['nullable', 'string', 'max:191'],
            'brand_key' => ['required', 'string', 'max:191'],
            'location_key' => ['required', 'string', 'max:191'],
            'hold_mode' => ['nullable', 'boolean'],
        ]);

        $existingSecrets = Credential::where('store_id', $rid)->where('feature', 'marn')->first()->secrets ?? [];

        $secrets = [
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'] ?: ($existingSecrets['client_secret'] ?? ''),
            'brand_key' => $data['brand_key'],
        ];
        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => 'marn', 'provider' => 'marn'],
            ['secrets' => $secrets, 'active' => true]
        );
        $connection = PosBridgeConnection::updateOrCreate(
            ['provider' => 'marn', 'store_id' => $rid],
            ['status' => 'active', 'external_location_id' => $data['location_key'],
                'settings' => ['brand_key' => $data['brand_key'], 'hold_mode' => (bool) ($data['hold_mode'] ?? false)]]
        );
        // Mint the inbound-webhook secret into the encrypted Credential vault (not settings).
        $connection->ensureWebhookToken();

        app_alert_success(___('Marn connected.'));

        return back();
    }

    public function importPreview(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $conn = PosBridgeConnection::where('store_id', $rid)->where('provider', 'marn')->firstOrFail();
        $importer = new MarnImporter(MarnClientFactory::make($rid));

        return response()->json($importer->preview($rid, (string) $conn->external_location_id));
    }

    public function import(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $conn = PosBridgeConnection::where('store_id', $rid)->where('provider', 'marn')->firstOrFail();
        $importer = new MarnImporter(MarnClientFactory::make($rid));
        $result = $importer->import($rid, (string) $conn->external_location_id);
        CatalogExport::bust($rid);
        app_alert_success(___('Imported.')." {$result['created']} ".___('new').", {$result['updated']} ".___('updated').'.');

        return back();
    }

    public function disconnect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        PosBridgeConnection::where('store_id', $rid)->where('provider', 'marn')->delete();
        Credential::where('store_id', $rid)->where('feature', 'marn')->delete();
        app_alert_success(___('Marn disconnected.'));

        return back();
    }
}
