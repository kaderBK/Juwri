<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontSlotView;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-09 (basket §9) — the delivery / collection time of the storefront's OWN browser pages (the kit theme `basket`): the
 * same three calls the headless API makes (kit/api `getDeliverySlots`, `createHold`, `releaseHold`) on the same services,
 * so the slot a shopper sees, the hold that keeps it for 10 minutes and the order that takes it are exactly the API's.
 *
 *  - `GET    {slug}/grocery/slots?order_type&from&days`  the store's hourly slots per day with live capacity, the express
 *                                                         option and the View Model SlotPicker
 *  - `POST   {slug}/grocery/hold`                         a 10-minute hold of one slot; the token comes back once and rides
 *                                                         on the order as `X-Hold-Token`
 *  - `DELETE {slug}/grocery/hold/{hold}`                  release it (`X-Hold-Token`), 204
 *
 * The store is the route's slug (never a body field); a hold is looked up inside that store and needs its token to be
 * released or used, so another shopper's hold id is useless without it. Everything is `Cache-Control: no-store`.
 */
class StorefrontGrocerySlotsController extends Controller
{
    public function __construct(
        private readonly DeliverySlotBook $slots,
        private readonly StorefrontOrdering $ordering,
        private readonly StorefrontSlotView $view,
    ) {}

    public function index(Request $request, string $slug): JsonResponse
    {
        $store = $this->store($slug);
        $ctx = StorefrontApiContext::forWeb($store, $request, $this->customer());
        $validator = Validator::make($request->query(), [
            'order_type' => ['required', 'string', 'max:32'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
        ]);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }
        $core = OrderTypeKeys::toCoreFor((string) $request->query('order_type'), $this->ordering->orderTypes($store));
        if ($core === null || $ctx->isKiosk()) {
            throw new StorefrontApiException(422, 'invalid_order_type', ___('The selected order type is not available for this store.'));
        }
        $from = $request->query('from');
        $days = $this->slots->days($store, $ctx->branch, $core, is_string($from) ? $from : null, (int) $request->query('days', 3));

        return $this->json($this->view->build($store, $ctx->branch, $core, $days, $this->slots->express($store, $ctx->branch, $core)));
    }

    public function hold(Request $request, string $slug): JsonResponse
    {
        $store = $this->store($slug);
        $ctx = StorefrontApiContext::forWeb($store, $request, $this->customer());
        $validator = Validator::make((array) $request->json()->all(), ['slot_id' => ['required', 'string', 'max:64']]);
        if ($validator->fails() || $ctx->isKiosk()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors ?: ['slot_id' => [___('This time is not available. Pick another time.')]]);
        }
        // one browser session = one holder, so a shopper moving to another time replaces their own earlier hold
        $holder = hash('sha256', 'web:'.$request->session()->getId().'|'.(string) $request->ip());
        try {
            $made = $this->slots->hold($store, $ctx->branch, (string) $request->json('slot_id'), $holder, null);
        } catch (SlotException $e) {
            throw $this->view->refusal($ctx, $e);
        }

        return $this->json($this->view->hold($ctx, $made['hold'], $made['token']), 201);
    }

    public function release(Request $request, string $slug, string $hold): Response
    {
        $store = $this->store($slug);
        $ctx = StorefrontApiContext::forWeb($store, $request, $this->customer());
        if (strlen($hold) > 64) {
            throw StorefrontApiException::notFound();
        }
        try {
            $this->slots->release($store, $hold, (string) $request->header('X-Hold-Token', ''));
        } catch (SlotException $e) {
            throw $this->view->refusal($ctx, $e);
        }

        return response('', 204, ['Cache-Control' => 'no-store']);
    }

    private function store(string $slug): Store
    {
        $store = Store::query()->idOrSlug($slug)->first();
        if ($store === null || ! StorefrontAvailability::servable($store)) {
            throw StorefrontApiException::notFound();
        }

        return $store;
    }

    private function customer(): ?Customer
    {
        $customer = auth('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store, private'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
