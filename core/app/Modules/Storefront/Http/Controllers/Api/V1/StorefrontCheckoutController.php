<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Storefront\Http\Middleware\DeprecatedApiAlias;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontCart;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderPlacement;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderView;
use Juwri\Modules\Storefront\Services\Api\StorefrontShippingQuote;

/**
 * P3-06b phase A — the headless storefront's money endpoints (kit/api openapi.json `quoteCart`, `createOrder`,
 * `getOrder`). Every answer is `Cache-Control: no-store` (README §2.10).
 *
 * `POST /orders` serves two bodies on one path (README §5): the new OrderSubmitRequest (`lines`) and, until its
 * sunset, the frozen legacy body (`items` without `lines`), answered by the legacy controller with the
 * Deprecation / Sunset / Link headers.
 */
class StorefrontCheckoutController extends Controller
{
    public function __construct(
        private readonly StorefrontCart $cart,
        private readonly StorefrontOrderPlacement $placement,
        private readonly StorefrontOrderView $view,
        private readonly HostedPayments $hosted,
        private readonly StorefrontShippingQuote $shipping,
    ) {}

    /** POST /api/v1/storefront/quote — QuoteResponse. */
    public function quote(Request $request): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        // P3-06b phase D: a services cart (every line a bookable service) is quoted by the booking engine
        $raw = (array) $request->json()->all();
        $booking = app(StorefrontBookingController::class);
        if ($booking->isBookingCart($ctx, $raw)) {
            return $booking->quote($ctx, $raw);
        }
        $body = StorefrontCart::validate($raw);
        $priced = $this->cart->price($ctx, $body);

        return $this->json($this->cart->response($priced, $body), 200, $ctx);
    }

    /**
     * POST /api/v1/storefront/shipping/quote — ShippingQuoteResponse (P3-08, phase B): the methods for this address and
     * cart (priced as a `ship` order, so the free-shipping threshold reads the quote's subtotal).
     */
    public function shippingQuote(Request $request): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        app()->setLocale($ctx->locale);
        $body = StorefrontShippingQuote::validate((array) $request->json()->all());
        $priced = $this->cart->price($ctx, ['order_type' => 'ship', 'lines' => $body['lines']]);

        return $this->json($this->shipping->answer($ctx->store, (array) $body['address'], $priced->priced->subtotal), 200, $ctx);
    }

    /** POST /api/v1/storefront/orders — OrderCreated (201), or the legacy body's LegacyOrderCreated. */
    public function store(Request $request): mixed
    {
        $body = (array) $request->json()->all();
        if (array_key_exists('items', $body) && ! array_key_exists('lines', $body)) {
            $response = app(StorefrontController::class)->createOrder($request);
            $response->headers->set('Cache-Control', 'no-store');

            return DeprecatedApiAlias::decorate($response, '/api/v1/storefront/orders');
        }

        $ctx = StorefrontApiContext::fromRequest($request);
        [$created, $replayed] = $this->placement->place($ctx, $request, $body);
        $response = $this->json($created, 201, $ctx);
        if ($replayed) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    /** GET /api/v1/storefront/orders/{track_token} — OrderStatusResponse of the token's store's order. */
    public function show(Request $request, string $track_token): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $order = strlen($track_token) <= 64
            ? Order::query()->where('store_id', $ctx->store->id)->where('track_token', $track_token)->first()
            : null;
        if ($order === null) {
            throw StorefrontApiException::notFound();
        }
        // An open window that has ended is closed now, not at the next sweep (README §2.8: expiry is lazy + swept).
        if ($order->payment_mode === 'hosted' && $order->status === 'unpaid') {
            $this->hosted->expire($order);
        }

        return $this->json($this->view->status($ctx, $order), 200, $ctx);
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status, StorefrontApiContext $ctx): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store', 'Content-Language' => $ctx->locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
