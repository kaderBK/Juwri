<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\India;

use App\Services\FeatureAccess;
use Illuminate\Support\Collection;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyMenuPusher;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyProvider;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoMenuPusher;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoProvider;
use Juwri\Modules\Menu\Models\Menu;

/**
 * PARITY-RUN T7 — the outbound half of the Indian aggregator channels: menu push,
 * item-availability (86) sync, outlet open/close and the Test-connection probe,
 * for both Zomato and Swiggy behind one API.
 *
 * Why one service for two providers: the *inbound* halves genuinely differ (field
 * names, signature shape, status vocabulary) and live in their own drivers, but
 * the *outbound* verbs are the same three calls with different method names. One
 * service means one place enforces the two rules that matter here:
 *
 *   1. **Gate by store, never by requester.** These calls run from an owner page,
 *      from the `Order::booted()` choke point and from a Menu save — three
 *      different subjects. `FeatureAccess::storeAllows()` asks the only question
 *      that is always right: did the STORE OWNER buy this channel?
 *   2. **Never let a partner outage break a local write.** Every entry point that
 *      is reached from a model hook is `try/catch`-wrapped by its caller, and the
 *      per-connection loop here isolates one channel's failure from the other's.
 *
 * The desired remote state is also mirrored into `aggregator_connections.settings`
 * so the owner page can render what the platform *should* be showing even when the
 * partner API is unreachable, and so a re-push after an outage is a diff, not a
 * guess.
 */
class IndiaAggregatorSync
{
    /** provider key => feature key. The registry order the UI renders in. */
    public const PROVIDERS = [
        'zomato' => ZomatoProvider::FEATURE_KEY,
        'swiggy' => SwiggyProvider::FEATURE_KEY,
    ];

    public static function isIndiaProvider(?string $provider): bool
    {
        return $provider !== null && array_key_exists($provider, self::PROVIDERS);
    }

    public static function featureKey(string $provider): ?string
    {
        return self::PROVIDERS[$provider] ?? null;
    }

    /**
     * Is this channel sellable AND switched on for this store? The subject is the
     * store owner — see the class docblock.
     */
    public static function enabled(?int $storeId, string $provider): bool
    {
        $key = self::featureKey($provider);

        return $key !== null && FeatureAccess::storeAllows($storeId, $key);
    }

    /** Active, feature-enabled India connections for a store. */
    public static function connectionsFor(int $storeId): Collection
    {
        return AggregatorConnection::where('store_id', $storeId)
            ->whereIn('provider', array_keys(self::PROVIDERS))
            ->where('status', 'active')
            ->get()
            ->filter(fn (AggregatorConnection $c) => self::enabled($storeId, (string) $c->provider))
            ->values();
    }

    /* ------------------------------------------------------------------ menu */

    /** Build the provider payload for a connection (no network). */
    public static function buildMenu(AggregatorConnection $connection): array
    {
        $rid = (int) $connection->store_id;

        return match ($connection->provider) {
            'zomato' => new ZomatoMenuPusher()->build($rid, $connection->branch_id, $connection->external_store_id),
            'swiggy' => new SwiggyMenuPusher()->build($rid, $connection->branch_id, $connection->external_store_id),
            default => [],
        };
    }

    /**
     * Full-replace menu publish. Returns the item count so the caller can put a
     * human sentence in the sync log. Throws on a partner error — the caller logs
     * it as a failed sync row.
     */
    public static function pushMenu(AggregatorConnection $connection): int
    {
        $rid = (int) $connection->store_id;
        $payload = self::buildMenu($connection);

        if ($payload === []) {
            return 0;
        }

        match ($connection->provider) {
            'zomato' => ZomatoClientFactory::make($rid)->publishMenu($payload),
            'swiggy' => SwiggyClientFactory::make($rid)->publishMenu($payload),
            default => null,
        };

        $connection->last_synced_at = now();
        $connection->menu_sync_state = 'synced';
        $connection->sync_error = null;
        $connection->save();

        return count($payload['items'] ?? []);
    }

    /* ---------------------------------------------------------- availability */

    /** The partner-side item code this provider publishes for a local menu id. */
    public static function itemCode(string $provider, int $menuId): string
    {
        return match ($provider) {
            'zomato' => ZomatoProvider::itemCode($menuId),
            'swiggy' => SwiggyProvider::itemCode($menuId),
            default => (string) $menuId,
        };
    }

    /**
     * Push ONE item's stock state to ONE channel, and remember the desired state.
     * Throws on a partner error so an owner-initiated sync can report it.
     */
    public static function pushItemAvailability(AggregatorConnection $connection, int $menuId, bool $available): void
    {
        $rid = (int) $connection->store_id;
        $code = self::itemCode((string) $connection->provider, $menuId);

        match ($connection->provider) {
            'zomato' => ZomatoClientFactory::make($rid)->setItemAvailability($code, $available),
            'swiggy' => SwiggyClientFactory::make($rid)->setItemAvailability($code, $available),
            default => null,
        };

        $settings = (array) ($connection->settings ?? []);
        $unavailable = (array) ($settings['unavailable_items'] ?? []);

        if ($available) {
            unset($unavailable[(string) $menuId]);
        } else {
            $unavailable[(string) $menuId] = true;
        }

        $settings['unavailable_items'] = $unavailable;
        $connection->settings = $settings;
        $connection->save();
    }

    /**
     * Reconcile EVERY item's stock state for one channel (the owner's
     * "Sync availability" button). Returns the number of items pushed.
     */
    public static function syncAvailability(AggregatorConnection $connection): int
    {
        $items = Menu::where('store_id', $connection->store_id)
            ->where('active', '1')
            ->get(['id', 'is_sold_out']);

        foreach ($items as $item) {
            self::pushItemAvailability($connection, (int) $item->id, ! $item->is_sold_out);
        }

        return $items->count();
    }

    /**
     * The 86-toggle fan-out: called from the Menu model when `is_sold_out` flips.
     * Best-effort per channel — a partner outage must never fail a menu save, and
     * one dead channel must not stop the other from being told.
     */
    public static function onItemAvailabilityChanged(int $storeId, int $menuId, bool $available): void
    {
        foreach (self::connectionsFor($storeId) as $connection) {
            try {
                self::pushItemAvailability($connection, $menuId, $available);
            } catch (\Throwable $e) {
                AggregatorSyncLog::record(
                    $storeId,
                    (string) $connection->provider,
                    'failed',
                    'availability #'.$menuId.': '.$e->getMessage()
                );
            }
        }
    }

    /* ----------------------------------------------------------- outlet state */

    public static function isOutletOnline(AggregatorConnection $connection): bool
    {
        $settings = (array) ($connection->settings ?? []);

        // Default ONLINE: a freshly connected outlet is open for business, and an
        // absent key must not read as "closed" and hide a live store.
        return (bool) ($settings['outlet_online'] ?? true);
    }

    /** Open/close the outlet on the platform ("busy mode" / stop-taking-orders). */
    public static function setOutletOnline(AggregatorConnection $connection, bool $online): void
    {
        $rid = (int) $connection->store_id;
        $storeId = (string) $connection->external_store_id;

        match ($connection->provider) {
            'zomato' => ZomatoClientFactory::make($rid)->setOutletOnline($storeId, $online),
            'swiggy' => SwiggyClientFactory::make($rid)->setOutletOnline($storeId, $online),
            default => null,
        };

        $settings = (array) ($connection->settings ?? []);
        $settings['outlet_online'] = $online;
        $connection->settings = $settings;
        $connection->save();
    }

    /* ------------------------------------------------------------------ probe */

    /** Test connection: cheap auth/outlet check against the partner. */
    public static function ping(AggregatorConnection $connection): bool
    {
        $rid = (int) $connection->store_id;
        $storeId = (string) $connection->external_store_id;

        return match ($connection->provider) {
            'zomato' => ZomatoClientFactory::make($rid)->ping($storeId),
            'swiggy' => SwiggyClientFactory::make($rid)->ping($storeId),
            default => false,
        };
    }

    /** Is this tenant talking to the REAL partner API, or still to the mock? */
    public static function isLive(AggregatorConnection $connection): bool
    {
        $rid = (int) $connection->store_id;

        return match ($connection->provider) {
            'zomato' => ZomatoClientFactory::isLive($rid),
            'swiggy' => SwiggyClientFactory::isLive($rid),
            default => false,
        };
    }
}
