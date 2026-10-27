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
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;

/**
 * PARITY-RUN T7 — owner connection page for Zomato and Swiggy (Setup →
 * Integrations → Zomato / Swiggy).
 *
 * One controller, two providers: the provider key is a ROUTE DEFAULT, not user
 * input, so a request can never address a channel its route did not gate. Each
 * route group carries its own `feature:aggregator_zomato` / `feature:aggregator_
 * swiggy` middleware — legal here because the caller is the authenticated
 * merchant, which is exactly the subject that middleware resolves. (The webhook
 * half is the opposite case and is gated in-controller; see
 * IndiaAggregatorWebhookController.)
 *
 * ── SECRET HANDLING ──
 * Credentials live in the encrypted `Credential` vault and are NEVER read back
 * into the page. The view receives booleans ("is a webhook secret set?") plus a
 * masked hint derived from length only, and an empty submitted field means "keep
 * what is stored" rather than "erase it". Nothing here logs a secret.
 */
class IndiaAggregatorController extends Controller
{
    /** Provider key from the route default — never from the request body. */
    private function provider(Request $request): string
    {
        $provider = (string) $request->route('provider');
        abort_unless(IndiaAggregatorSync::isIndiaProvider($provider), 404);

        return $provider;
    }

    /** Resolve an owned restaurant, mirroring the other owner screens. */
    private function resolveStore(Request $request, $storeId): Store
    {
        $store = $storeId instanceof Store ? $storeId : Store::findOrFail($storeId);

        abort_unless($store->user_id == $request->user()->id, 404);

        return $store;
    }

    private function connection(Store $store, string $provider): ?AggregatorConnection
    {
        return AggregatorConnection::where('store_id', $store->id)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * A length-only hint. Never the value, never a prefix of it — a masked
     * placeholder exists to tell the owner "something is stored", nothing more.
     */
    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', min(12, max(6, mb_strlen($value))));
    }

    public function index(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);
        $connection = $this->connection($store, $provider);

        $secrets = Credential::for($provider, (int) $store->id) ?? [];

        return view('owner.integrations.aggregators-india', [
            'store' => $store,
            'provider' => $provider,
            'connection' => $connection,
            'webhookUrl' => route('webhook.'.$provider.'.inbound'),
            'masked' => [
                'webhook_secret' => $this->mask($secrets['webhook_secret'] ?? null),
                'api_key' => $this->mask($secrets['api_key'] ?? ($secrets['client_id'] ?? null)),
                'api_secret' => $this->mask($secrets['api_secret'] ?? ($secrets['client_secret'] ?? null)),
            ],
            'outletOnline' => $connection ? IndiaAggregatorSync::isOutletOnline($connection) : true,
            'isLive' => $connection ? IndiaAggregatorSync::isLive($connection) : false,
            'stats' => $this->stats($store, $provider),
            'syncLog' => AggregatorSyncLog::where('store_id', $store->id)
                ->where('provider', $provider)
                ->orderByDesc('id')
                ->limit(15)
                ->get(),
        ]);
    }

    /**
     * Order count + commission for this channel.
     *
     * Deliberately two plain aggregates rather than one `selectRaw` join: raw
     * column expressions are NOT run through Laravel's table-prefix wrapper, and
     * a hardcoded table name in raw SQL is the exact class of bug that 500s on a
     * prefixed production database while passing on sqlite in CI.
     */
    private function stats(Store $store, string $provider): array
    {
        $orderIds = DeliveryOrder::where('store_id', $store->id)
            ->where('provider', $provider)
            ->pluck('order_id')
            ->filter()
            ->all();

        return [
            'orders' => count($orderIds),
            'fees' => $orderIds
                ? (float) Order::whereIn('id', $orderIds)->sum(DB::raw(Order::minorMoneySql('channel_fee')))
                : 0.0,
        ];
    }

    /** Create or update the connection + its BYOK credentials. */
    public function connect(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);

        $data = $request->validate([
            'external_store_id' => ['required', 'string', 'max:191'],
            'webhook_secret' => ['nullable', 'string', 'max:191'],
            'api_key' => ['nullable', 'string', 'max:191'],
            'api_secret' => ['nullable', 'string', 'max:191'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $store->id)], // P6-59b
            'prep_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            'auto_accept' => ['nullable'],
        ]);

        // The outlet id is unique per provider across the platform — refuse to
        // hijack another tenant's mapping rather than silently re-pointing it.
        $clash = AggregatorConnection::where('provider', $provider)
            ->where('external_store_id', $data['external_store_id'])
            ->where('store_id', '!=', $store->id)
            ->exists();

        if ($clash) {
            app_alert_error(___('That outlet id is already connected to another store.'));

            return back();
        }

        $connection = $this->connection($store, $provider) ?: new AggregatorConnection([
            'provider' => $provider,
            'store_id' => $store->id,
        ]);

        $settings = (array) ($connection->settings ?? []);
        $settings['prep_minutes'] = (int) ($data['prep_minutes'] ?? 20);

        $connection->provider = $provider;
        $connection->store_id = $store->id;
        $connection->external_store_id = $data['external_store_id'];
        $connection->branch_id = $data['branch_id'] ?? null;
        $connection->status = $connection->status ?: 'active';
        $connection->auto_accept = $request->boolean('auto_accept');
        $connection->settings = $settings;
        $connection->save();

        // Merge into the vault: an EMPTY field means "keep the stored secret",
        // because the form can never show the owner what is already there.
        $secrets = Credential::for($provider, (int) $store->id) ?? [];
        foreach (['webhook_secret', 'api_key', 'api_secret'] as $field) {
            if (! empty($data[$field])) {
                $secrets[$field] = $data[$field];
            }
        }

        if ($secrets !== []) {
            Credential::updateOrCreate(
                ['store_id' => $store->id, 'feature' => $provider],
                ['provider' => $provider, 'secrets' => $secrets, 'active' => true]
            );
        }

        app_alert_success(___('Channel connected.'));

        return back();
    }

    /** Cheap auth/outlet probe against the partner (or the mock). */
    public function test(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);
        $connection = $this->connection($store, $provider);

        if (! $connection) {
            app_alert_error(___('Connect the channel first.'));

            return back();
        }

        try {
            $ok = IndiaAggregatorSync::ping($connection);
        } catch (\Throwable $e) {
            $ok = false;
        }

        AggregatorSyncLog::record((int) $store->id, $provider, $ok ? 'ok' : 'failed', 'test connection');

        $ok
            ? app_alert_success(___('Connection test passed.'))
            : app_alert_error(___('Connection test failed.'));

        return back();
    }

    /** Full-replace menu publish. */
    public function menuPush(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);
        $connection = $this->connection($store, $provider);

        if (! $connection) {
            app_alert_error(___('Connect the channel first.'));

            return back();
        }

        try {
            $count = IndiaAggregatorSync::pushMenu($connection);
            AggregatorSyncLog::record((int) $store->id, $provider, 'ok', $count.' '.___('items'));
            app_alert_success(___('Menu pushed.').' '.$count);
        } catch (\Throwable $e) {
            $connection->menu_sync_state = 'failed';
            $connection->sync_error = mb_substr($e->getMessage(), 0, 500);
            $connection->save();
            AggregatorSyncLog::record((int) $store->id, $provider, 'failed', $e->getMessage());
            app_alert_error(___('Menu push failed.').' '.$e->getMessage());
        }

        return back();
    }

    /** Reconcile every item's in-stock state with the platform. */
    public function syncAvailability(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);
        $connection = $this->connection($store, $provider);

        if (! $connection) {
            app_alert_error(___('Connect the channel first.'));

            return back();
        }

        try {
            $count = IndiaAggregatorSync::syncAvailability($connection);
            AggregatorSyncLog::record((int) $store->id, $provider, 'ok', ___('Item availability synced').': '.$count);
            app_alert_success(___('Item availability synced').': '.$count);
        } catch (\Throwable $e) {
            AggregatorSyncLog::record((int) $store->id, $provider, 'failed', $e->getMessage());
            app_alert_error(___('Sync failed.').' '.$e->getMessage());
        }

        return back();
    }

    /** Open / close the outlet on the marketplace. */
    public function toggleOutlet(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);
        $connection = $this->connection($store, $provider);

        if (! $connection) {
            app_alert_error(___('Connect the channel first.'));

            return back();
        }

        $online = ! IndiaAggregatorSync::isOutletOnline($connection);

        try {
            IndiaAggregatorSync::setOutletOnline($connection, $online);
            AggregatorSyncLog::record(
                (int) $store->id,
                $provider,
                'ok',
                $online ? 'outlet online' : 'outlet offline'
            );
            $online
                ? app_alert_success(___('Outlet is now accepting orders.'))
                : app_alert_success(___('Outlet is now closed on this channel.'));
        } catch (\Throwable $e) {
            AggregatorSyncLog::record((int) $store->id, $provider, 'failed', $e->getMessage());
            app_alert_error(___('Sync failed.').' '.$e->getMessage());
        }

        return back();
    }

    /** Pause / resume ingestion without dropping the stored mapping. */
    public function pause(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);
        $connection = $this->connection($store, $provider);

        if (! $connection) {
            return back();
        }

        $connection->status = $connection->isPaused() ? 'active' : 'paused';
        $connection->save();

        app_alert_success($connection->isPaused() ? ___('Channel paused.') : ___('Channel resumed.'));

        return back();
    }

    /** Remove the mapping AND the stored secrets. */
    public function disconnect(Request $request, $store)
    {
        $provider = $this->provider($request);
        $store = $this->resolveStore($request, $store);

        $this->connection($store, $provider)?->delete();
        Credential::where('store_id', $store->id)->where('feature', $provider)->delete();

        app_alert_success(___('Channel disconnected.'));

        return back();
    }
}
