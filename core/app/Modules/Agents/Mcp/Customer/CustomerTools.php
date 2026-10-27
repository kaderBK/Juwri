<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp\Customer;

use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Services\Agents\AgentToolsService;
use App\Services\GoogleWallet\GoogleWalletService;
use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Http\Controllers\McpError;
use Juwri\Modules\Agents\Mcp\DefinedTool;
use Juwri\Modules\Agents\Mcp\McpToolSet;
use Juwri\Modules\Delivery\Models\Shipment;

/**
 * MCP-expansion P3 / P1-16: the tools of the Customer Account MCP ({@see CustomerMcpServer}, POST /mcp/customer) —
 * our mirror of Shopify's Customer Account MCP, moved unchanged from the former hand-rolled CustomerMcpController.
 * A diner's AI assistant reads THEIR orders, tracks them, and reorders.
 *
 * The customer is the one the EXISTING customer token resolved (CustomerTokenAuth, issued by the customer OTP/login
 * flow) — `phone`/`customer_id` are NEVER accepted as tool args, so one customer can never read another's data
 * (isolation is the #1 review axis, P3-5). No plan gate (it's the customer's own data, D6). Every tool reads the
 * diner's own data (or builds a cart draft) — M3-2 annotations: readOnlyHint.
 */
final class CustomerTools implements McpToolSet
{
    use FormatsOrders;

    /** null only for the caller-less definition list (landing page, annotation tests); run() needs the customer. */
    public function __construct(private readonly ?Customer $customer = null) {}

    /** Read/action tools (P3-2/P3-3). */
    public function definitions(): array
    {
        return [
            DefinedTool::define(
                'my_orders',
                'Your recent orders (most recent first) at the restaurant you are signed in to. '
                .'Returns a compact row per order (id, status, type, total, created_at). limit caps '
                .'results (default 10, max 50). Call order_detail for full line items, track_order for '
                .'live status. Only YOUR orders are ever returned.',
                ['limit' => 'integer']
            ),
            DefinedTool::define(
                'order_detail',
                'Full detail for ONE of your orders by id: status, totals, line items (name, qty, '
                .'unit price, variant, extras, note), delivery info, and — for a physical-goods order — '
                .'a shipment block (carrier, tracking_number, shipped_at, delivered_at; null when this '
                .'order was never shipped, e.g. every dine-in/takeaway order). Errors -32004 if the '
                .'order id is not one of yours (no existence leak).',
                ['order_id' => 'integer'],
                ['order_id']
            ),
            DefinedTool::define(
                'track_order',
                'Live status of one of your orders, by order_id OR its track_token. Returns id, status, '
                .'delivery_status, timestamps, and — for a physical-goods (ship/pickup) order — a '
                .'shipment block {status, carrier, tracking_number, shipped_at, delivered_at} (null when '
                .'no shipment exists for this order). Errors -32004 if it is not one of your orders.',
                ['order_id' => 'integer', 'track_token' => 'string']
            ),
            DefinedTool::define(
                'reorder',
                'Rebuild one of your past orders as a cart draft ("order it again"). Returns '
                .'{from_order, items:[{item_id, quantity}]}, skipping any item that is now removed or '
                .'sold out. Feed the returned items to the storefront order flow to place a fresh order '
                .'(this tool does NOT place an order). Errors -32004 if the order id is not yours.',
                ['order_id' => 'integer'],
                ['order_id']
            ),
            DefinedTool::define(
                'my_profile',
                'Your profile on file at this restaurant: name, phone, and order count. Read-only. '
                .'No other customer\'s data is ever accessible.',
                []
            ),
            // HPMS P2: the diner's own hotel room bookings at this restaurant (when
            // the venue runs the hotel module). Scoped to YOUR customer id only.
            // rev/multi-vertical MCP-expansion fix 3: also surfaces service
            // bookings (laundry drop-off, appointments) for a services-vertical
            // venue — see the 'kind' discriminator below.
            DefinedTool::define(
                'my_bookings',
                'Your bookings at this venue, most recent first: hotel room bookings (when this venue is a '
                .'hotel/guesthouse) AND service bookings — laundry drop-off, appointments, etc. — (when this '
                .'venue runs a services vertical). Each row carries kind ("hotel" or "service") plus: '
                .'reference code, status, and for a hotel row room type/check-in/check-out/nights/guests, or '
                .'for a service row the booked service name(s) and scheduled_at; every row also carries '
                .'quoted_total and currency. Returns an empty list when the venue has neither. limit caps '
                .'results (default 10, max 50). Only YOUR bookings are ever returned; the guest manage-link '
                .'token is never exposed.',
                ['limit' => 'integer']
            ),
            // google-suite Lane C follow-up: the diner's own purchased gift cards +
            // an optional Google Wallet save-link. Scoped to YOUR customer id only.
            DefinedTool::define(
                'my_gift_cards',
                'Gift cards YOU purchased at this restaurant, most recent first. Each row is compact: '
                .'code, balance (number + formatted), status (active, redeemed, void), whether it is '
                .'expired/redeemable, and expires_at. When the store has Google Wallet enabled and the '
                .'card is still redeemable, wallet_save_url is a pay.google.com "Add to Google Wallet" '
                .'link for that card (else null). Returns an empty list when you purchased none. Only '
                .'YOUR cards are ever returned; gift cards sent to you by others are not listed here.',
                []
            ),
        ];
    }

    public function run(string $name, array $args): array
    {
        $customer = $this->customer ?? throw new \LogicException('CustomerTools::run needs the token\'s customer');

        return match ($name) {
            'my_orders' => $this->myOrders($customer, $args),
            'order_detail' => $this->orderDetailTool($customer, $args),
            'track_order' => $this->trackOrder($customer, $args),
            'reorder' => $this->reorder($customer, $args),
            'my_profile' => $this->myProfile($customer),
            'my_bookings' => $this->myBookings($customer, $args),
            'my_gift_cards' => $this->myGiftCards($customer),
            default => throw new McpError(-32602, "Unknown tool: {$name}"),
        };
    }

    // --- Read tools (P3-2) -------------------------------------------------

    /**
     * Resolve an order that MUST belong to the token's customer. Not-found and
     * not-yours are the SAME response (-32004, no existence leak) — P3-5.
     */
    private function ownOrder(Customer $customer, int $id): Order
    {
        $order = Order::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->find($id);
        if (! $order) {
            throw new McpError(-32004, 'Order not found.');
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function myOrders(Customer $customer, array $args): array
    {
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));

        return Order::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($o) => $this->orderRow($o))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function orderDetailTool(Customer $customer, array $args): array
    {
        $order = $this->ownOrder($customer, (int) ($args['order_id'] ?? 0));
        $detail = $this->orderDetail($order);
        $detail['shipment'] = $this->shipmentSummary($order->id);

        return $detail;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function trackOrder(Customer $customer, array $args): array
    {
        $id = (int) ($args['order_id'] ?? 0);
        $token = trim((string) ($args['track_token'] ?? ''));

        if ($id <= 0 && $token === '') {
            throw new McpError(-32602, 'Provide order_id or track_token.');
        }

        $order = Order::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->when($id > 0, fn ($q) => $q->where('id', $id))
            ->when($id <= 0, fn ($q) => $q->where('track_token', $token)) // a token is guaranteed above
            ->first();
        if (! $order) {
            throw new McpError(-32004, 'Order not found.');
        }

        return [
            'id' => $order->id,
            'status' => $order->status,
            'delivery_status' => $order->delivery_status,
            'type' => $order->type,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'out_for_delivery_at' => optional($order->out_for_delivery_at)->toIso8601String(),
            'delivered_at' => optional($order->delivered_at)->toIso8601String(),
            // rev/multi-vertical MCP-expansion fix 3: shipment status for a
            // physical-goods (ship/pickup) order; null for every food order —
            // additive, backward-compatible.
            'shipment' => $this->shipmentSummary($order->id),
        ];
    }

    /**
     * rev/multi-vertical MCP-expansion fix 3 — the Shipment row for one order,
     * in the same compact shape ShipmentController's public track endpoint
     * uses. Null when the order was never shipped (every legacy food order).
     */
    /** @return array<string, mixed>|null */
    private function shipmentSummary(int $orderId): ?array
    {
        if (! class_exists(Shipment::class) || ! Schema::hasTable('shipments')) {
            return null;
        }

        $shipment = Shipment::where('order_id', $orderId)->first();
        if (! $shipment) {
            return null;
        }

        return [
            'status' => $shipment->status,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'shipped_at' => optional($shipment->shipped_at)->toIso8601String(),
            'delivered_at' => optional($shipment->delivered_at)->toIso8601String(),
        ];
    }

    // --- Action tools (P3-3) ----------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function reorder(Customer $customer, array $args): array
    {
        $order = $this->ownOrder($customer, (int) ($args['order_id'] ?? 0));
        $order->loadMissing('items');

        // Reuse the shared draft-cart rebuild (skips removed/sold-out items).
        return [
            'from_order' => $order->id,
            'items' => AgentToolsService::draftCartFromOrder($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function myProfile(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'orders_count' => (int) $customer->orders_count,
        ];
    }

    /**
     * HPMS P2: the diner's own hotel room bookings at this restaurant. Scoped by
     * store_id + customer_id (same isolation rule as {@see ownOrder}); the
     * bearer manage-link token is never serialised (hidden on the model). Degrades
     * to an empty list when the hotel booking engine has not shipped in this deploy.
     *
     * @param  array<string, mixed>  $args
     * @return array<int, array<string, mixed>>
     */
    private function myBookings(Customer $customer, array $args): array
    {
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));
        $rows = [];

        // P1-13: hotel bookings come from the Hotel module (none in Community).
        $rows = array_merge($rows, app(RoomCharge::class)->customerBookings((int) $customer->store_id, (int) $customer->id, $limit));

        /* rev/multi-vertical MCP-expansion fix 3: a laundry/appointment "booking"
           is a plain Order on a slot-based fulfillment type — there is no
           separate booking table (see FulfillmentRegistry). Only surfaced when
           this store's vertical actually declares the 'service' capability, so
           a restaurant/retail customer's my_bookings answer is byte-identical
           to before (this block never runs for them). */
        $store = Store::find($customer->store_id);
        $registry = app(VerticalRegistry::class);
        if ($store && $registry->has($store->store_type, 'service')) {
            $fulfillment = app(FulfillmentRegistry::class);
            $slotModes = array_values(array_filter(
                $registry->fulfillmentModes($store->store_type),
                fn ($mode) => $fulfillment->needsSlot($mode)
            ));

            if ($slotModes !== []) {
                $rows = array_merge($rows, Order::where('store_id', $customer->store_id)
                    ->where('customer_id', $customer->id)
                    ->whereIn('type', $slotModes)
                    ->with('items.menu')
                    ->orderByDesc('id')->limit($limit)->get()
                    ->map(fn ($o) => [
                        'kind' => 'service',
                        'code' => $o->track_token ?: (string) $o->id,
                        'status' => $o->status,
                        'service' => $o->items->map(fn ($it) => optional($it->menu)->getRawOriginal('name'))
                            ->filter()->values()->all(),
                        'scheduled_at' => optional($o->scheduled_at)->toIso8601String(),
                        'quoted_total' => (float) $o->total,
                        'currency' => $o->currencyCode(),
                    ])->values()->all());
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * google-suite Lane C follow-up: the diner's own PURCHASED gift cards at this
     * restaurant, scoped by store_id + purchaser_customer_id (same isolation
     * rule as {@see ownOrder}). wallet_save_url is minted only when the store has
     * Google Wallet enabled AND the card is still redeemable — otherwise null, so
     * the tool degrades to a plain card list when Wallet is dark.
     *
     * @return array<int, array<string, mixed>>
     */
    private function myGiftCards(Customer $customer): array
    {
        $store = Store::findOrFail($customer->store_id);
        $walletEnabled = GoogleWalletService::enabledForStore($store);
        $decimals = currency_decimals(store_options($customer->store_id, 'currency_code') ?: null);

        return GiftCard::where('store_id', $customer->store_id)
            ->where('purchaser_customer_id', $customer->id)
            ->orderByDesc('id')->get()
            ->map(function ($card) use ($store, $walletEnabled, $decimals) {
                $bal = (float) $card->balance();
                $saveUrl = ($walletEnabled && $card->isRedeemable())
                    ? GoogleWalletService::giftCardSaveUrl($store, $card)
                    : null;

                return [
                    'code' => $card->code,
                    'balance' => round($bal, $decimals),
                    'formatted' => store_money_plain($bal, $store->id),
                    'status' => $card->status,
                    'expired' => $card->isExpired(),
                    'redeemable' => $card->isRedeemable(),
                    'expires_at' => optional($card->expires_at)->toDateString(),
                    'wallet_save_url' => $saveUrl,
                ];
            })->values()->all();
    }
}
