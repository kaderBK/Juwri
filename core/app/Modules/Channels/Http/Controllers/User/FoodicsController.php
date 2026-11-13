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
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsClientFactory;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsImporter;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsOAuth;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsTokenExchange;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * N1-P4 — Foodics owner UI: OAuth connect (+ sandbox toggle), item-mapping review,
 * dry-run import wizard, push log. Tenant-scoped; sits behind the pos_bridge gate.
 */
class FoodicsController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $connection = PosBridgeConnection::where('store_id', $rid)->where('provider', 'foodics')->first();

        return view('owner.integrations.posbridge.foodics', [
            'rid' => $rid,
            'connection' => $connection,
            'connected' => $connection && $connection->status === 'active',
            'maps' => PosItemMap::where('provider', 'foodics')->where('store_id', $rid)->get(),
            'menus' => Menu::where('store_id', $rid)->orderBy('name')->get(['id', 'name']),
            'log' => PosOrderPush::where('store_id', $rid)->where('provider', 'foodics')->latest()->limit(50)->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    /** Begin OAuth: store CSRF state + context in session, redirect to Foodics. */
    public function connect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $sandbox = $request->boolean('sandbox');
        $creds = Credential::for('foodics', null) ?? []; // platform OAuth app id/secret
        $clientId = $creds['client_id'] ?? config('services.foodics.client_id');
        if (empty($clientId)) {
            app_alert_error(___('Foodics app credentials are not configured yet.'));

            return back();
        }

        $state = FoodicsOAuth::newState();
        session([
            'foodics_oauth' => ['state' => $state, 'rid' => $rid, 'sandbox' => $sandbox],
        ]);

        return redirect()->away(FoodicsOAuth::authorizeUrl(
            $sandbox, (string) $clientId, route('stores.foodics.callback'), $state
        ));
    }

    /** OAuth callback: verify state, exchange code, store token + connection. */
    public function callback(Request $request)
    {
        $ctx = session('foodics_oauth');
        if (! $ctx || ! FoodicsOAuth::stateMatches($ctx['state'] ?? null, $request->query('state'))) {
            abort(403, 'Invalid OAuth state');
        }
        session()->forget('foodics_oauth');

        $rid = (int) $ctx['rid'];
        abort_unless(in_array($rid, $this->storeIds(), true), 403);
        $sandbox = (bool) $ctx['sandbox'];

        $platform = Credential::for('foodics', null) ?? [];
        $token = FoodicsTokenExchange::exchange(
            $sandbox,
            (string) ($platform['client_id'] ?? config('services.foodics.client_id')),
            (string) ($platform['client_secret'] ?? config('services.foodics.client_secret')),
            (string) $request->query('code'),
            route('stores.foodics.callback')
        );

        if (! $token) {
            app_alert_error(___('Foodics authorization failed.'));

            return redirect()->route('stores.foodics.index', ['store_id' => $rid]);
        }

        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => 'foodics', 'provider' => 'foodics'],
            ['secrets' => ['access_token' => $token['access_token'], 'sandbox' => $sandbox], 'active' => true]
        );
        $connection = PosBridgeConnection::updateOrCreate(
            ['provider' => 'foodics', 'store_id' => $rid],
            ['status' => 'active', 'settings' => ['sandbox' => $sandbox]]
        );
        // Mint the inbound-webhook secret into the encrypted Credential vault (not settings).
        $connection->ensureWebhookToken();

        app_alert_success(___('Foodics connected.'));

        return redirect()->route('stores.foodics.index', ['store_id' => $rid]);
    }

    /** Dry-run import preview. */
    public function importPreview(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $importer = new FoodicsImporter(FoodicsClientFactory::make($rid));

        return response()->json($importer->preview($rid));
    }

    /** Commit the import. */
    public function import(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $importer = new FoodicsImporter(FoodicsClientFactory::make($rid));
        $result = $importer->import($rid);
        CatalogExport::bust($rid);
        app_alert_success(___('Imported.')." {$result['created']} ".___('new').", {$result['updated']} ".___('updated').'.');

        return back();
    }

    public function disconnect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        PosBridgeConnection::where('store_id', $rid)->where('provider', 'foodics')->delete();
        Credential::where('store_id', $rid)->where('feature', 'foodics')->delete();
        app_alert_success(___('Foodics disconnected.'));

        return back();
    }
}
