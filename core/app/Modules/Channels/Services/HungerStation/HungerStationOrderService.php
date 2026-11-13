<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\HungerStation;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\WebhookService;
use App\Support\OrderCreatedWebhookPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Channels\Models\HsEventLog;
use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P2 — ingest a HungerStation order webhook into a local Order.
 *
 * Idempotent: keyed on delivery_orders(provider='hungerstation', external_id=order_id).
 * A retried webhook (HS retries 5×) returns the existing order, never a duplicate.
 * Resolves the branch by hs_vendor_id → its restaurant (post). Maps each item by
 * hs_sku → Menu; an unmapped sku is LOGGED + flagged on the order, never silently
 * dropped. Fires the same order.created signal as other order sources so KDS +
 * notifications light up.
 */
class HungerStationOrderService
{
    public const PROVIDER = 'hungerstation';

    /**
     * @return array{order: ?Order, duplicate: bool, error: ?string}
     */
    public function ingest(array $payload): array
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $vendorId = (string) ($payload['vendor_id'] ?? '');
        if ($orderId === '' || $vendorId === '') {
            return ['order' => null, 'duplicate' => false, 'error' => 'missing order_id or vendor_id'];
        }

        // Idempotency fast-path — a completed same-external-id ingest returns it.
        $existing = DeliveryOrder::where('provider', self::PROVIDER)->where('external_id', $orderId)->first();
        if ($existing && $existing->order_id) {
            return ['order' => Order::find($existing->order_id), 'duplicate' => true, 'error' => null];
        }

        $branch = Branch::where('hs_vendor_id', $vendorId)->first();
        if (! $branch) {
            HsEventLog::record('in', 'order.unknown_vendor', [
                'hs_order_id' => $orderId,
                'request' => ['vendor_id' => $vendorId],
                'ok' => false,
            ]);

            return ['order' => null, 'duplicate' => false, 'error' => 'unknown vendor'];
        }

        $store = Store::find($branch->store_id);
        if (! $store) {
            return ['order' => null, 'duplicate' => false, 'error' => 'unknown restaurant'];
        }

        // Atomic claim before creating the local Order — HS retries up to 5x across
        // queue workers, so two concurrent deliveries of one hs_order_id must not
        // both create an Order. The unique (provider, external_id) index makes the
        // loser throw here. order_id is backfilled once the Order exists.
        // P6-64: claim + Order + items + backfill commit together, so a job retry after a
        // mid-ingest failure re-runs cleanly (no orphan claim with order_id null that
        // would make the retry report 'duplicate' and drop the order).
        try {
            [$order, $unmapped] = DB::transaction(function () use ($payload, $store, $branch, $orderId) {
                $claim = DeliveryOrder::create([
                    'provider' => self::PROVIDER,
                    'external_id' => $orderId,
                    'order_id' => null,
                    'store_id' => $store->id,
                ]);

                // Coupons & Wallets P7-3c [decision C]: HungerStation orders are settled by
                // the aggregator at the aggregator's price; owner coupons are NOT applied
                // here (doing so would desync the local Order total from what HS actually
                // charged), and store credit never applies on this unauthenticated path
                // (account-bound, D3). Our discounts live on first-party surfaces only.
                $unmapped = [];
                $order = Order::create([
                    'store_id' => $store->id,
                    'branch_id' => $branch->id,
                    'type' => 'delivery',
                    'status' => 'new',
                    'source' => self::PROVIDER,
                    'customer_name' => $payload['customer']['name'] ?? 'HungerStation',
                    'phone_number' => $payload['customer']['phone_masked'] ?? null,
                    'seen' => false,
                    'hs_order_id' => $orderId,
                    'hs_order_code' => $payload['order_code'] ?? null,
                    'hs_transport_type' => $payload['transport_type'] ?? null,
                    'hs_picking_mode' => $payload['picking_mode'] ?? 'partner',
                    'track_token' => Str::lower(Str::random(32)),
                    'created_at' => now(),
                ]);

                foreach ($payload['items'] ?? [] as $line) {
                    $sku = (string) ($line['sku'] ?? '');
                    $menu = $sku !== '' ? Menu::where('store_id', $store->id)->where('hs_sku', $sku)->first() : null;
                    if (! $menu) {
                        $unmapped[] = $sku.' ('.($line['name'] ?? '?').')';

                        continue;
                    }
                    OrderItem::create([
                        'order_id' => $order->id,
                        'item_id' => $menu->id,
                        'quantity' => (int) ($line['quantity'] ?? 1),
                    ] + app(ItemPricing::class)->snapshot($menu, null, 'delivery', (int) $branch->id)); // BL-049: freeze the line price
                }

                $order->load('items');
                $order->snapshotTotals(); // BL-049: freeze subtotal/tax/grand like every other order path

                if ($unmapped) {
                    // Never silently drop — flag on the order + log for the owner.
                    $order->message = trim((string) $order->message.' [HS unmapped: '.implode(', ', $unmapped).']');
                    $order->save();
                }

                $claim->update(['order_id' => $order->id]);

                return [$order, $unmapped];
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = DeliveryOrder::where('provider', self::PROVIDER)->where('external_id', $orderId)->first();

            return ['order' => $existing && $existing->order_id ? Order::find($existing->order_id) : null, 'duplicate' => true, 'error' => null];
        }

        HsEventLog::record('in', 'order.received', [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'hs_order_id' => $orderId,
            'request' => ['order_code' => $payload['order_code'] ?? null, 'items' => count($payload['items'] ?? []), 'unmapped' => count($unmapped)],
            'ok' => true,
        ]);

        // Same signal other sources fire → KDS + owner notifications.
        try {
            // F0-10: canonical order.created shape (adds total/track_token the HS path
            // used to omit); keeps hs_order_id + the delivery type as HS extras.
            WebhookService::dispatch((int) $store->user_id, 'order.created', OrderCreatedWebhookPayload::build($order, self::PROVIDER, [
                'type' => 'delivery',
                'hs_order_id' => $orderId,
            ]));
        } catch (\Throwable $e) {
        }

        return ['order' => $order, 'duplicate' => false, 'error' => null];
    }

    /** P3: push a local order status change to HungerStation (mapped per picking mode). */
    public function pushStatus(Order $order): ?string
    {
        if ($order->source !== self::PROVIDER || ! $order->hs_order_id) {
            return null;
        }
        $hs = HungerStationStatusMapper::toHs((string) $order->status, (string) ($order->hs_picking_mode ?: 'partner'));
        if (! $hs) {
            return null;
        }
        $branch = Branch::find($order->branch_id);
        $chainId = $branch->hs_chain_id ?? '';
        $client = HungerStationClientFactory::make((int) $order->store_id);
        try {
            $client->updateOrderStatus($chainId, $order->hs_order_id, $hs);
        } catch (\Throwable $e) {
            report($e);
        }
        HsEventLog::record('out', 'order.status', [
            'store_id' => $order->store_id, 'branch_id' => $order->branch_id,
            'hs_order_id' => $order->hs_order_id, 'response' => ['status' => $hs], 'ok' => true,
        ]);

        return $hs;
    }
}
