<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Bookings\Contracts\BookingApi;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderPlacement;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderView;
use Juwri\Modules\Storefront\Services\Api\StorefrontSlotView;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;

/**
 * P3-09 / P3-06b phase C — the headless delivery / collection slots and their holds (kit/api `getDeliverySlots`,
 * `createHold`, `releaseHold`; API-3, API-11, API-24a). Slots, holds and releases are `Cache-Control: no-store`.
 *
 *  - `GET /delivery/slots?order_type&from&days`: the store's hourly slots per day with live capacity, the express
 *    option and the View Model SlotPicker (`menu:read`).
 *  - `POST /holds` (`order:write`, `Idempotency-Key`): a 10-minute hold of a `delivery_slot`; the token comes back
 *    once (and again on an idempotent replay of the same key). Booking holds (`booking_slot`, phase D) go to the
 *    booking engine through StorefrontBookingController.
 *  - `DELETE /holds/{hold_id}` with `X-Hold-Token`: 204, or 200 `HoldReleased` when it cancelled a pending hosted
 *    order (store setting "Release holds during payment" on), or 409 `hold_in_use` (off).
 */
class StorefrontSlotsController extends Controller
{
    public function __construct(
        private readonly DeliverySlotBook $slots,
        private readonly StorefrontOrdering $ordering,
        private readonly StorefrontSlotView $view,
        private readonly StorefrontOrderView $orders,
    ) {}

    /** GET /api/v1/storefront/delivery/slots — DeliverySlotsResponse. */
    public function index(Request $request): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        app()->setLocale($ctx->locale);
        $query = $request->query();
        $validator = Validator::make($query, [
            'order_type' => ['required', 'string', 'max:32'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'after_slot' => ['sometimes', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }
        if (isset($query['after_slot'])) {
            // Laundry's delivery-back slot (API-23) arrives with the laundry variant (P3-11).
            throw StorefrontApiException::validation(['after_slot' => [___('This store does not offer a return slot.')]]);
        }
        $core = OrderTypeKeys::toCoreFor((string) $query['order_type'], $this->ordering->orderTypes($ctx->store));
        if ($core === null) {
            throw new StorefrontApiException(422, 'invalid_order_type', ___('The selected order type is not available for this store.'));
        }

        $days = $this->slots->days($ctx->store, $ctx->branch, $core, isset($query['from']) ? (string) $query['from'] : null, (int) ($query['days'] ?? 3));
        $express = $this->slots->express($ctx->store, $ctx->branch, $core);

        return $this->json($this->view->response($ctx, $core, $days, $express), 200, $ctx);
    }

    /** POST /api/v1/storefront/holds — Hold (201). */
    public function store(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        app()->setLocale($ctx->locale);
        $key = StorefrontOrderPlacement::idempotencyKey($request);
        $body = (array) $request->json()->all();
        $validator = Validator::make($body, [
            'kind' => ['required', 'in:booking_slot,delivery_slot'],
            'slot_id' => ['required', 'string', 'max:64'],
            'return_slot_id' => ['sometimes', 'string', 'max:64'],
            'service_ids' => ['sometimes', 'array', 'max:4'],
            'option_ids' => ['sometimes', 'array', 'max:40'],
        ]);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }
        if ($body['kind'] === 'booking_slot') {
            // P3-06b phase D: a booking slot is held by the booking engine (API-11; `X-Manage-Token` for a reschedule)
            return app(StorefrontBookingController::class)->hold($request, $ctx, $body, $key);
        }
        if (isset($body['return_slot_id'])) {
            throw StorefrontApiException::validation(['return_slot_id' => [___('This store does not offer a return slot.')]]);
        }

        $holder = hash('sha256', 'sft:'.$ctx->token->getKey().'|'.(string) $request->ip());
        try {
            $made = $this->slots->hold($ctx->store, $ctx->branch, (string) $body['slot_id'], $holder, $key);
        } catch (SlotException $e) {
            throw $this->view->refusal($ctx, $e);
        }
        $response = $this->json($this->view->hold($ctx, $made['hold'], $made['token']), 201, $ctx);
        if ($made['replayed']) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    /** DELETE /api/v1/storefront/holds/{hold_id} — 204, or HoldReleased (200). */
    public function destroy(Request $request, string $hold_id): \Symfony\Component\HttpFoundation\Response
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        app()->setLocale($ctx->locale);
        if (strlen($hold_id) > 64) {
            throw StorefrontApiException::notFound();
        }
        // P3-06b phase D: a booking hold (API-24a, API-26: a hold bound to a pending deposit)
        $bookingHold = app(BookingApi::class)->findHold($ctx->store, $hold_id);
        if ($bookingHold !== null) {
            return app(StorefrontBookingController::class)->release($request, $ctx, $bookingHold);
        }
        try {
            $cancelled = $this->slots->release($ctx->store, $hold_id, (string) $request->header('X-Hold-Token', ''));
        } catch (SlotException $e) {
            throw $this->view->refusal($ctx, $e);
        }
        if ($cancelled === null) {
            return response('', 204, ['Cache-Control' => 'no-store']);
        }
        $status = $this->orders->status($ctx, $cancelled);

        return $this->json([
            'hold_id' => $hold_id,
            'released' => true,
            'cancelled' => [
                'kind' => 'order',
                'public_id' => (string) $cancelled->public_id,
                'number' => (string) $status['order']['number'],
                'status' => 'cancelled',
                'track_token' => (string) $cancelled->track_token,
            ],
            'payment' => $status['payment'],
        ], 200, $ctx);
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status, StorefrontApiContext $ctx): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store', 'Content-Language' => $ctx->locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
