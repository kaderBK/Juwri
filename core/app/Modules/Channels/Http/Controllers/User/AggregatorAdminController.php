<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorRegistry;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezMenuPusher;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaMenuPusher;

/**
 * T3-P4 unified aggregator dashboard: connect marketplaces (Deliverect, Uber Eats),
 * pause/resume channels, and see per-channel order volume + commission. Tenant-scoped.
 */
class AggregatorAdminController extends Controller
{
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

    private function ownedConnection(int $id): AggregatorConnection
    {
        return AggregatorConnection::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $connections = AggregatorConnection::where('store_id', $rid)->orderBy('provider')->get();

        // Per-channel commission report: order count + channel_fee sum by provider.
        // Raw column expressions are NOT run through Laravel's table-prefix wrapper,
        // so on a prefixed DB they must carry the prefix explicitly (join/where/groupBy
        // are wrapped automatically and stay unprefixed here).
        $p = DB::getTablePrefix();
        $report = DeliveryOrder::query()
            ->join('orders', 'orders.id', '=', 'delivery_orders.order_id')
            ->where('delivery_orders.store_id', $rid)
            ->selectRaw("{$p}delivery_orders.provider as provider, COUNT(*) as orders, COALESCE(SUM(".Order::minorMoneySql('channel_fee', "{$p}orders.").'),0) as fees')
            ->groupBy('delivery_orders.provider')
            ->get()
            ->keyBy('provider');

        return view('owner.integrations.aggregators', [
            'rid' => $rid,
            'connections' => $connections,
            'report' => $report,
            'providers' => array_values(array_filter(AggregatorRegistry::keys(), fn ($k) => $k !== 'custom')),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
            'pushable' => ['keeta', 'jahez'],
            // A6: real | unavailable | fake per pushable provider, so the hub can flag a
            // driver=real store whose real client class is missing instead of pretending.
            'driverStatus' => [
                'keeta' => KeetaClientFactory::driverStatus($rid),
                'jahez' => JahezClientFactory::driverStatus($rid),
            ],
            'syncLog' => AggregatorSyncLog::where('store_id', $rid)
                ->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function connect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'external_store_id' => ['required', 'string', 'max:191'],
            'webhook_secret' => ['required', 'string', 'max:191'],
            'client_secret' => ['nullable', 'string', 'max:191'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $rid)],
        ]);

        abort_unless(AggregatorRegistry::get($data['provider']) !== null, 422);

        // P6-59b: the (provider, external_store_id) key below is global — without this an owner could post another
        // store's outlet id and re-home that store's connection (and its incoming orders) onto their own store.
        $clash = AggregatorConnection::where('provider', $data['provider'])
            ->where('external_store_id', $data['external_store_id'])
            ->where('store_id', '!=', $rid)->exists();
        abort_if($clash, 422, 'That outlet id is already connected to another store.');

        AggregatorConnection::updateOrCreate(
            ['provider' => $data['provider'], 'external_store_id' => $data['external_store_id']],
            ['store_id' => $rid, 'branch_id' => $data['branch_id'] ?? null, 'status' => 'active']
        );

        // Store BYOK secrets in the encrypted Credential vault (feature = provider).
        $secrets = ['webhook_secret' => $data['webhook_secret']];
        if (! empty($data['client_secret'])) {
            $secrets['client_secret'] = $data['client_secret'];
        }
        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => $data['provider']],
            ['provider' => $data['provider'], 'secrets' => $secrets, 'active' => true]
        );

        app_alert_success(___('Channel connected.'));

        return back();
    }

    public function pause(Request $request, int $id)
    {
        $conn = $this->ownedConnection($id);
        $conn->status = $conn->isPaused() ? 'active' : 'paused';
        $conn->save();
        app_alert_success($conn->isPaused() ? ___('Channel paused.') : ___('Channel resumed.'));

        return back();
    }

    public function disconnect(Request $request, int $id)
    {
        $conn = $this->ownedConnection($id);
        $conn->delete();
        app_alert_success(___('Channel disconnected.'));

        return back();
    }

    /**
     * Full-replace menu push to a channel that supports it (Keeta, Jahez). Builds the
     * canonical CatalogExport → provider payload and records the result in the sync log.
     */
    public function menuPush(Request $request, int $id)
    {
        $conn = $this->ownedConnection($id);
        $rid = (int) $conn->store_id;

        // A6: refuse the push when the driver is set to real but the real client class
        // is missing — otherwise the push "succeeds" against the Fake and nothing reaches
        // the aggregator. Fake/real both proceed normally.
        $driverStatus = match ($conn->provider) {
            'keeta' => KeetaClientFactory::driverStatus($rid),
            'jahez' => JahezClientFactory::driverStatus($rid),
            default => 'fake',
        };
        if ($driverStatus === 'unavailable') {
            AggregatorSyncLog::record($rid, $conn->provider, 'failed', 'driver not available');
            app_alert_error(___('Driver not available — the live client for this channel is not installed yet.'));

            return back();
        }

        try {
            $result = match ($conn->provider) {
                'keeta' => $this->pushKeeta($conn, $rid),
                'jahez' => $this->pushJahez($conn, $rid),
                default => null,
            };

            if ($result === null) {
                app_alert_error(___('This channel does not support menu push.'));

                return back();
            }

            AggregatorSyncLog::record($rid, $conn->provider, 'ok', $result);
            $conn->last_synced_at = now();
            $conn->save();
            app_alert_success(___('Menu pushed.').' '.$result);
        } catch (\Throwable $e) {
            AggregatorSyncLog::record($rid, $conn->provider, 'failed', $e->getMessage());
            app_alert_error(___('Menu push failed.').' '.$e->getMessage());
        }

        return back();
    }

    private function pushKeeta(AggregatorConnection $conn, int $rid): string
    {
        $payload = new KeetaMenuPusher()
            ->build($rid, $conn->branch_id, $conn->external_store_id);
        KeetaClientFactory::make($rid)->merchantUpdate($payload);
        $unmapped = $payload['unmapped_allergens'] ?? [];
        $msg = count($payload['spus']).' products';
        if ($unmapped) {
            $msg .= ' · '.___('Unmapped allergens').': '.implode(', ', $unmapped);
        }

        return $msg;
    }

    private function pushJahez(AggregatorConnection $conn, int $rid): string
    {
        if (! class_exists(JahezMenuPusher::class)) {
            return '0 products';
        }
        $payload = new JahezMenuPusher()
            ->build($rid, $conn->branch_id, $conn->external_store_id);
        JahezClientFactory::make($rid)->uploadMenu($payload);

        return count($payload['categories'] ?? []).' categories';
    }
}
