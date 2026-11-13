<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use App\Models\Order;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Menu\Models\Menu;

/**
 * T3-P2 Deliverect channel integration (middleware → many aggregators). Deliverect
 * pushes a standardized order to our webhook keyed by `channelLinkId`; we resolve the
 * connection by that id, map items by SKU (Menu.external_sku, falling back to id), and
 * surface any unmapped SKUs on the order note (never silently dropped). Outbound status
 * goes back through the Deliverect client. Live creds require partner signup (deferred).
 */
class DeliverectProvider extends AbstractAggregatorProvider implements PushesItemAvailability
{
    /**
     * A2 (integrations pack): the REAL Deliverect inbound-webhook signature header
     * (HMAC-SHA256 over the raw body). The previous `X-Deliverect-Signature` was a
     * guess — real Deliverect signs with this header, so live webhooks would have
     * been rejected. Shared by the generic order webhook (via $signatureHeader) and
     * DeliverectPosController's register/sync/cancel verification.
     */
    public const HMAC_HEADER = 'x-server-authorization-hmac-sha256';

    protected string $signatureHeader = self::HMAC_HEADER;

    /**
     * D7/D8 (integrations pack): the agreed source-key → human channel-name map. An
     * order's `source` records the provider that ingested it (Deliverect, Uber Eats,
     * the marketplace drivers, …); the staff order card and the customer track page
     * both resolve a display name through this map (via {@see Order::channelLabel()}).
     * A native source (pos / online / customer_app / api) is not in the map and yields
     * null — no "Ordered via" line for a direct order.
     */
    public const CHANNEL_NAMES = [
        'deliverect' => 'Deliverect',
        'uber_eats' => 'Uber Eats',
        'talabat' => 'Talabat',
        'jahez' => 'Jahez',
        'hungerstation' => 'HungerStation',
        'careem' => 'Careem',
        'keeta' => 'Keeta',
        'zomato' => 'Zomato',
        'swiggy' => 'Swiggy',
    ];

    public function key(): string
    {
        return 'deliverect';
    }

    public function externalStoreId(array $payload): ?string
    {
        $id = $payload['channelLinkId'] ?? $payload['channel_link_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function externalOrderId(array $payload): ?string
    {
        $id = $payload['orderId'] ?? $payload['id'] ?? $payload['_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $items = [];
        $unmapped = [];
        foreach ((array) ($payload['items'] ?? []) as $line) {
            $sku = (string) ($line['plu'] ?? $line['sku'] ?? '');
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $menu = $this->resolveMenu($connection->store_id, $sku);
            if ($menu) {
                $items[] = ['item_id' => $menu->id, 'quantity' => $qty];
            } else {
                $unmapped[] = $qty.'× '.($line['name'] ?? $sku);
            }
        }

        $note = 'Via Deliverect';
        if ($unmapped) {
            $note .= ' · '.__('Unmapped items (review)').': '.implode(', ', $unmapped);
        }

        $customer = $payload['customer'] ?? [];

        return [
            'type' => 'delivery',
            'customer_name' => $customer['name'] ?? null,
            'phone' => (string) ($customer['phoneNumber'] ?? $customer['phone'] ?? ''),
            'address' => $payload['deliveryAddress']['line1'] ?? ($payload['address'] ?? null),
            'note' => $note,
            'channel_fee' => isset($payload['channelFee']) ? (float) $payload['channelFee'] / 100 : 0.0,
            'items' => $items,
        ];
    }

    /** SKU → local Menu: prefer external_sku, fall back to a numeric menu id. */
    private function resolveMenu(int $storeId, string $sku): ?Menu
    {
        if ($sku === '') {
            return null;
        }
        $q = Menu::where('store_id', $storeId);
        /* WV.V — the table is `menu`, SINGULAR. `hasColumn('menus', …)` asked about
           a table that does not exist, so it returned false forever and external-SKU
           mapping silently never ran: every Deliverect item resolved by numeric id
           or not at all. Ask the model for its table name so a rename cannot
           reintroduce it. (Same typo in UberEatsProvider; IndiaItemResolver already
           did it correctly.) */
        if (Schema::hasColumn(new Menu()->getTable(), 'external_sku')) {
            $byExt = (clone $q)->where('external_sku', $sku)->first();
            if ($byExt) {
                return $byExt;
            }
        }

        return ctype_digit($sku) ? (clone $q)->find((int) $sku) : null;
    }

    /**
     * A1 (integrations pack) — corrected status matrix mapping our order lifecycle
     * → Deliverect's REAL POS status ladder. The previous matrix was wrong on two
     * counts: it used codes that are not in the ladder at all (30, 40) and it mapped
     * `cancelled → 90`, which is FINALIZED — so a cancelled order reported as
     * completed to every channel.
     *
     * Deliverect POS ladder (the only valid outbound codes):
     *   10 New · 20 Accepted · 40 Printed · 50 Preparing · 60 Prepared ·
     *   70 Pickup Ready · 90 Finalized · 95 Auto_Finalized · 110 Canceled · 120 Failed.
     *
     * (Inbound-only codes — 100 channel-cancel, 126 POS-reject, 95 accept-only mode —
     * are handled in the order/cancel webhooks, not here; see A7/A8.)
     */
    public const STATUS_MATRIX = [
        'new' => 20,  // Accepted — the order is in our POS
        'accepted' => 20,  // Accepted
        'preparing' => 50,  // Preparing
        'ready' => 70,  // Pickup Ready
        'out_for_delivery' => 90,  // Finalized — handed to the channel courier
        'completed' => 90,  // Finalized
        'delivered' => 90,  // Finalized
        'cancelled' => 110, // Canceled
    ];

    public function statusCodeFor(Order $order): ?int
    {
        return self::STATUS_MATRIX[(string) ($order->status ?? '')] ?? null;
    }

    public function pushStatus(Order $order, AggregatorConnection $connection): void
    {
        // Map to Deliverect's status code; outbound POST goes through the client wired
        // with live creds (deferred). No-op-safe seam — the mapping is unit-tested.
        $code = $this->statusCodeFor($order);
        if ($code === null) {
            return;
        }
        // DeliverectClientFactory::make($connection->store_id)->updateStatus(...)
        // is invoked here once staging creds land (BD-ACTIONS #8).
    }

    /**
     * PushesItemAvailability: the 86-toggle fan-out ({@see AggregatorAvailabilityPusher})
     * calls this when a menu item's is_sold_out flips. Availability is the inverse of
     * snooze — an unavailable (86'd) item is snoozed on Deliverect.
     */
    public function pushItemAvailability(AggregatorConnection $connection, int $menuId, bool $available): void
    {
        $this->snoozeProduct($connection, $menuId, ! $available);
    }

    /**
     * N6 — busy-mode / product-snooze seam. Called when an owner toggles busy or when an
     * item is 86'd (is_sold_out) so we can snooze it on Deliverect by PLU (= our menu id).
     * Outbound Store-API call is wired with live creds (deferred); safe no-op otherwise.
     */
    public function snoozeProduct(AggregatorConnection $connection, int $menuId, bool $snoozed): void
    {
        $settings = $connection->settings ?? [];
        $snoozeList = $settings['snoozed_plus'] ?? [];
        if ($snoozed) {
            $snoozeList[(string) $menuId] = true;
        } else {
            unset($snoozeList[(string) $menuId]);
        }
        $settings['snoozed_plus'] = $snoozeList;
        $connection->settings = $settings;
        $connection->save();
        // DeliverectClientFactory::make(...)->snooze($menuId, $snoozed) once creds land.
    }

    public function setBusy(AggregatorConnection $connection, bool $busy): void
    {
        $settings = $connection->settings ?? [];
        $settings['busy'] = $busy;
        $connection->settings = $settings;
        $connection->save();
    }
}
