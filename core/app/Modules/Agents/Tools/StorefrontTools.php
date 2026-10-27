<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Tools;

use App\Services\Agents\AgentToolsService;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Support\Carbon;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Services\Telephony\VoicePayLink;

/**
 * P1-16: the storefront's customer-facing agent tools — ONE definition for the web chat widget, WhatsApp, SMS, voice
 * and the storefront MCP. Schemas moved from AgentToolsService::toolSchemas(), dispatch from
 * AgentChatService::dispatchTool(), hotel_availability from StorefrontMcpController (MCP only, as before). The store is
 * the channel's (token / phone line / widget), never the model's; customer-history tools key off the TRUSTED sender
 * phone (AO3 PII boundary), never a model-supplied phone.
 */
final class StorefrontTools
{
    /** Every storefront tool is a read except create_order: a non-destructive, non-idempotent write (an unpaid order). */
    private const WRITE_ANNOTATIONS = [
        'create_order' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
    ];

    /**
     * P6-09 V-07: the voice manifest. A call cannot prove who holds the wallet and must stay out of PCI scope, so the
     * voice channel drops wallet spend/balance tools (and there is no payment tool on any storefront channel: payment is
     * the hosted pay link sent by SMS). New wallet/payment tools must be added here, or a guard test fails.
     */
    public const VOICE_EXCLUDED = ['store_credit_balance'];

    public static function define(AgentToolRegistry $registry): void
    {
        $handlers = self::handlers();
        foreach (self::specs() as $spec) {
            $name = $spec['name'];
            $registry->register(new AgentTool(
                name: $name,
                description: $spec['description'],
                inputSchema: $spec['parameters'],
                audience: AgentTool::STOREFRONT,
                channels: in_array($name, self::VOICE_EXCLUDED, true)
                    ? [AgentTool::CHANNEL_CHAT, AgentTool::CHANNEL_MCP]
                    : [AgentTool::CHANNEL_CHAT, AgentTool::CHANNEL_MCP, AgentTool::CHANNEL_VOICE],
                handler: $handlers[$name],
                annotations: self::WRITE_ANNOTATIONS[$name] ?? ['readOnlyHint' => true],
                schemaForStore: $name === 'create_order' ? self::createOrderSchema(...) : null,
            ));
        }

        // HPMS P2: additive read-only room-booking availability for hotel venues, offered on MCP only (the chat
        // schemas are untouched). Always listed; returns {enabled:false} when this store is not a hotel or has online
        // booking turned off.
        $registry->register(new AgentTool(
            name: 'hotel_availability',
            description: 'Room-type availability and nightly price for a date range, when this venue is a '
                .'hotel/guesthouse with online booking enabled. Args: check_in, check_out ("YYYY-MM-DD", '
                .'default tonight → tomorrow). Returns {enabled, online_booking:"off|request|instant", '
                .'check_in, check_out, nights, currency, room_types:[{id,name,base_rate,available,'
                .'nightly_total}]}. enabled=false means this store is not a bookable hotel — do not offer '
                .'rooms. Read-only: it never holds or books a room (booking happens on the venue\'s site).',
            inputSchema: ['type' => 'object', 'properties' => (object) [
                'check_in' => ['type' => 'string'],
                'check_out' => ['type' => 'string'],
            ], 'required' => []],
            audience: AgentTool::STOREFRONT,
            channels: [AgentTool::CHANNEL_MCP],
            handler: fn (ToolContext $c, array $args) => self::hotelAvailability(self::store($c), $args),
        ));
    }

    /**
     * rev/mv-mcp-parity: create_order's shared schema advertises the food `type` enum (takeaway/on-table); its handler
     * is vertical-aware (ship/pickup_slot/appointment for a non-food store), so a store's own fulfillment modes are
     * advertised instead, letting the agent pick a valid one.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function createOrderSchema(array $schema, Store $store): array
    {
        $modes = app(FulfillmentRegistry::class)->modesFor($store->store_type ?? null);
        if ($modes !== []) {
            $schema['properties']['type']['enum'] = $modes;
        }

        return $schema;
    }

    private static function store(ToolContext $context): Store
    {
        if ($context->store === null) {
            throw new ToolError(ToolError::NOT_FOUND, 'Store not found.');
        }

        return $context->store;
    }

    /** @return array<string, \Closure(ToolContext, array<string, mixed>): mixed> */
    private static function handlers(): array
    {
        $s = self::store(...);

        return [
            'search_menu' => fn (ToolContext $c, array $args) => AgentToolsService::searchMenu($s($c), (string) ($args['query'] ?? ''), (array) ($args['filters'] ?? [])),
            'get_item' => fn (ToolContext $c, array $args) => AgentToolsService::getItem($s($c), (int) ($args['item_id'] ?? 0)),
            'validate_cart' => fn (ToolContext $c, array $args) => AgentToolsService::validateCart($s($c), (array) ($args['items'] ?? []), $args['coupon_code'] ?? null),
            // The sender phone is the TRUSTED identity — required for agent store-credit spend (decision B), never a
            // model-supplied phone.
            // P6-09 V-15: a phone order is source `phone` and, when pay links are required, waits unpaid (out of the
            // kitchen) until the caller pays the texted link. Decided here from the channel, never from tool args.
            'create_order' => fn (ToolContext $c, array $args) => $c->channel === AgentTool::CHANNEL_VOICE
                ? AgentToolsService::createOrder($s($c), (array) ($args['items'] ?? []), $args, $c->sessionPhone, 'phone', VoicePayLink::windowMinutes())
                : AgentToolsService::createOrder($s($c), (array) ($args['items'] ?? []), $args, $c->sessionPhone),
            'order_status' => fn (ToolContext $c, array $args) => AgentToolsService::orderStatus($s($c), (string) ($args['track_token'] ?? '')),
            'restaurant_info' => fn (ToolContext $c, array $args) => AgentToolsService::restaurantInfo($s($c)),
            // Only the verified sender's own history — a model-supplied phone is ignored.
            'order_history' => fn (ToolContext $c, array $args) => $c->sessionPhone ? AgentToolsService::orderHistory($s($c), $c->sessionPhone, (int) ($args['limit'] ?? 5)) : [],
            // P7-2c: store-credit balance is PII — keyed off the trusted sender identity (same boundary as
            // order_history). Null identity (web/MCP, unverified) -> not found.
            'store_credit_balance' => fn (ToolContext $c, array $args) => $c->sessionPhone ? AgentToolsService::storeCreditBalance($s($c), $c->sessionPhone) : ['found' => false, 'balance' => 0.0],
            'reorder_last' => fn (ToolContext $c, array $args) => $c->sessionPhone ? AgentToolsService::reorderLast($s($c), $c->sessionPhone) : ['found' => false, 'items' => []],
            'order_eta' => fn (ToolContext $c, array $args) => AgentToolsService::orderEta($s($c)),
            // cart_item_ids are non-PII menu ids, store-scoped inside the service — safe to accept from the model.
            'suggest_upsell' => fn (ToolContext $c, array $args) => AgentToolsService::suggestUpsell($s($c), array_map('intval', (array) ($args['cart_item_ids'] ?? []))),
            'dietary_answer' => fn (ToolContext $c, array $args) => AgentToolsService::dietaryAnswer($s($c), (int) ($args['item_id'] ?? 0), (string) ($args['tag'] ?? '')),
        ];
    }

    /**
     * The chat-facing schemas, in the order the chat prompt lists them.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    private static function specs(): array
    {
        $item = [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'quantity' => ['type' => 'integer', 'minimum' => 1],
                'variation' => ['type' => 'integer'],
                'extras' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['item_id', 'quantity'],
        ];

        $specs = [
            ['name' => 'search_menu', 'description' => 'Search the menu by name/description with optional vegan/halal/max_price filters.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'filters' => ['type' => 'object']], 'required' => ['query']]],
            ['name' => 'get_item', 'description' => 'Full details for one menu item (variants, extras).',
                'parameters' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer']], 'required' => ['item_id']]],
            ['name' => 'validate_cart', 'description' => 'Server-side price + availability check for a cart. Pass an optional coupon_code to preview the server-priced discount (returns discount, coupon_applied, coupon_message, and the discounted total). Store credit is NOT previewed here — it is applied at order time for an identified customer.',
                'parameters' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => $item], 'coupon_code' => ['type' => 'string']], 'required' => ['items']]],
            ['name' => 'create_order', 'description' => 'Place an UNPAID order using one of THIS store\'s fulfillment modes — the `type` enum lists what this store offers (e.g. takeaway/on-table for a restaurant, ship/pickup_slot/in_store for a shop, pickup_slot/appointment for a service). Omit `type` to use the store\'s default. Customer pays on arrival or on delivery. Pass an optional coupon_code to apply an owner discount (server-priced; returns discount + coupon_applied). Store credit: only spent when the merchant has enabled agent credit-spend AND the diner is identified by their verified sender phone; the amount used is returned as wallet_applied (server-decided — you cannot force it). Optional scheduled_at ("YYYY-MM-DD HH:MM", store local time) requests a pre-order for later; it is validated against the store\'s scheduling rules and rejected if invalid.',
                'parameters' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => $item], 'type' => ['type' => 'string', 'enum' => ['takeaway', 'on-table']], 'customer_name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'address' => ['type' => 'string', 'description' => 'Shipping/delivery address, required for ship/delivery modes'], 'coupon_code' => ['type' => 'string'], 'scheduled_at' => ['type' => 'string', 'description' => 'Optional pre-order time, YYYY-MM-DD HH:MM local']], 'required' => ['items']]],
            ['name' => 'order_status', 'description' => 'Status of an order by its track token.',
                'parameters' => ['type' => 'object', 'properties' => ['track_token' => ['type' => 'string']], 'required' => ['track_token']]],
            ['name' => 'restaurant_info', 'description' => 'Restaurant name, order types, and payment info.',
                'parameters' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'order_history', 'description' => "A returning customer's recent orders at this restaurant, matched by their phone number.",
                'parameters' => ['type' => 'object', 'properties' => ['phone' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]], 'required' => ['phone']]],
            ['name' => 'store_credit_balance', 'description' => "A returning customer's store-credit (wallet) balance at this restaurant, matched by phone. Account-bound — safe to read out to a diner who gave their phone. Returns found + balance + currency. Read only: you cannot SPEND credit here — a diner pays with credit on the storefront or customer app.",
                'parameters' => ['type' => 'object', 'properties' => ['phone' => ['type' => 'string']], 'required' => ['phone']]],
            ['name' => 'reorder_last', 'description' => "Rebuild the customer's most recent order as a cart draft (\"the usual\"); skips sold-out/removed items. Feed the returned items to validate_cart.",
                'parameters' => ['type' => 'object', 'properties' => ['phone' => ['type' => 'string']], 'required' => ['phone']]],
            ['name' => 'order_eta', 'description' => 'Current estimated prep wait (minutes) based on live kitchen load — use it to set expectations when placing an order.',
                'parameters' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'suggest_upsell', 'description' => 'Recommend up to 3 add-on items for a cart in progress. Returns [{item_id, name, price}] — active, in-stock items from THIS restaurant, cheapest-first, excluding anything already in the cart. Prices are server-sourced (never invent them). Use this AFTER the customer has chosen items, to offer complementary add-ons before checkout; use search_menu instead when the customer is looking for something specific by keyword. Pass the current cart item_ids so they are not re-suggested. Returns an empty array when nothing suitable is available.',
                'parameters' => ['type' => 'object', 'properties' => ['cart_item_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'item_ids already in the cart, so they are excluded from suggestions']]]],
            ['name' => 'dietary_answer', 'description' => "Answer whether ONE menu item satisfies a dietary tag (e.g. vegan, halal, vegetarian, gluten_free), using ONLY the item's structured dietary data. Returns the string \"yes\", \"no\", or \"unknown\". Returns \"unknown\" when the item has no data for that tag OR the item_id is not found — in that case tell the customer you're not sure and to check with staff; never guess from the name or description. Use this instead of inferring dietary suitability yourself.",
                'parameters' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer'], 'tag' => ['type' => 'string', 'description' => 'dietary tag key, e.g. vegan / halal / vegetarian / gluten_free']], 'required' => ['item_id', 'tag']]],
        ];

        return $specs;
    }

    /**
     * HPMS P2: room-type availability + nightly quote for a date range. Read-only,
     * gated on the hotel module being enabled AND the owner's online-booking mode
     * (off|request|instant). Reuses the Hotel module's BookingService/RateService exactly
     * as the public booking page does; degrades to an empty room list (never a 500)
     * when the booking engine has not shipped in this deploy.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function hotelAvailability(Store $store, array $args): array
    {
        if (! app(RoomCharge::class)->enabled($store->id)) {
            return ['enabled' => false, 'reason' => 'hotel_module_off'];
        }

        $mode = strtolower(trim((string) store_options($store->id, 'hotel_online_booking')));
        $mode = in_array($mode, ['request', 'instant'], true) ? $mode : 'off';
        if ($mode === 'off') {
            return ['enabled' => false, 'online_booking' => 'off'];
        }

        $today = Carbon::today();
        $checkIn = self::parseDate((string) ($args['check_in'] ?? ''), $today, $today);
        $out = self::parseDate((string) ($args['check_out'] ?? ''), $checkIn->copy()->addDay(), $checkIn->copy()->addDay());
        if ($out->lte($checkIn)) {
            $out = $checkIn->copy()->addDay();
        }

        $currency = store_options($store->id, 'currency_code') ?: config('settings.currency_code', 'USD');

        return [
            'enabled' => true,
            'online_booking' => $mode,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $out->toDateString(),
            'nights' => $checkIn->diffInDays($out),
            'currency' => $currency,
            'room_types' => app(RoomCharge::class)->availableRoomTypes((int) $store->id, $checkIn->toDateString(), $out->toDateString()),
        ];
    }

    /** Parse a Y-m-d arg, clamped to a floor; falls back when missing/invalid. */
    private static function parseDate(string $raw, Carbon $fallback, Carbon $floor): Carbon
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback->copy();
        }
        try {
            $d = Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable $e) {
            return $fallback->copy();
        }

        return $d->lt($floor) ? $floor->copy() : $d;
    }
}
