<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Services\Agents\AgentToolsService;
use App\Services\Verticals\FulfillmentRegistry;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiFeatures;
use Juwri\Modules\Verticals\Http\Controllers\Api\V1\Concerns\SummarizesVertical;

/**
 * GAP-3: token-authenticated public Storefront API. The restaurant is resolved by
 * StorefrontTokenAuth and bound as `api.storefront_post` — endpoints never accept a
 * restaurant id from the client, so one token can only ever reach its own restaurant.
 */
class StorefrontController extends Controller
{
    // rev/mv-surface-api P1: return the same store_type/vertical block the
    // owner-token StoreController does, so a storefront integrator can tell
    // which vertical (and fulfillment modes) the token's store is.
    use SummarizesVertical;

    /** GET /api/v1/storefront/menu — full menu for the token's restaurant. */
    public function menu()
    {
        $store = app('api.storefront_post');

        // Reuse the enriched public payload (variants/extras/groups/gallery, GAP-1).
        return (new PublicController)->menu($store->id);
    }

    /** GET /api/v1/storefront/restaurant — basic restaurant info for the token's restaurant. */
    public function storeInfo()
    {
        $store = app('api.storefront_post');

        return response()->json(array_merge([
            'id' => $store->id,
            'title' => $store->title,
            'slug' => $store->slug,
            'description' => $store->description,
            'phone' => $store->phone,
            'address' => $store->address,
        ], $this->verticalSummary($store)));
    }

    /**
     * P1-7: POST /api/v1/storefront/orders — a customer app / storefront submits a
     * cart on behalf of a diner. Scope `order:write`; restaurant resolved from the
     * token (never the client). Delegates to the SAME central builder as the agent
     * flow (AgentToolsService::createOrder → OrderApiService::create) with
     * source='storefront_api': server-priced, UNPAID, item caps enforced. No
     * payment fields, no store-credit spend (no verified sender).
     *
     * rev/mv-surface-api P1: `type` is validated against the token store's own
     * vertical (FulfillmentRegistry::modesFor), unioned with the legacy food
     * literals so an existing restaurant storefront integration keeps working.
     * The chosen mode is honoured end-to-end — AgentToolsService::createOrder
     * branches on the store's vertical (isFoodVertical → FulfillmentRegistry)
     * rather than coercing a non-food mode to 'takeaway'.
     */
    public function createOrder(Request $request)
    {
        $store = app('api.storefront_post');

        if (! plan_feature('storefront_api', $store->user)) {
            return StorefrontApiFeatures::refusal(StorefrontApiFeatures::STOREFRONT_API);
        }

        $items = $request->input('items', []);
        if (! is_array($items)) {
            return ApiError::response(422, 'validation_failed', 'items must be an array.', ['items' => ['items must be an array.']]);
        }

        $allowedTypes = array_values(array_unique(array_merge(
            ['on-table', 'takeaway'],
            app(FulfillmentRegistry::class)->modesFor($store->store_type)
        )));
        $requestedType = $request->input('type');
        if ($requestedType !== null && ! in_array($requestedType, $allowedTypes, true)) {
            // P3-06b (API-4): the specific code of the error model; the message names what is allowed.
            return ApiError::response(
                422,
                'invalid_order_type',
                ___('The selected order type is not available for this store.').' '.'Allowed types: '.implode(', ', $allowedTypes).'.'
            );
        }

        $result = AgentToolsService::createOrder($store, $items, [
            'type' => $requestedType,
            'table_number' => $request->input('table_number'),
            'customer_name' => $request->input('customer_name'),
            'phone' => $request->input('phone'),
            'coupon_code' => $request->input('coupon_code'),
            // A2: body client_uuid or the standard Idempotency-Key header.
            // J27-A2: + body client_order_id; must be a UUID (422 otherwise).
            'client_uuid' => ClientOrderId::fromRequest($request),
            'scheduled_at' => $request->input('scheduled_at'), // T1: optional pre-order time
        ], null, 'storefront_api');

        if (empty($result['ok'])) {
            // J27-A2: the key already placed a different cart → 409 `idempotency_conflict` (README §6), no second order.
            return ! empty($result['conflict'])
                ? ApiError::response(409, 'idempotency_conflict', $result['error'] ?? 'This Idempotency-Key was already used with a different request body.')
                : ApiError::status(422, $result['error'] ?? 'Order rejected.');
        }

        return response()->json($result, 201);
    }
}
