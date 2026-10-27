<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Support\KioskContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\CheckoutPhoneGate;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontCart;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderPlacement;
use Juwri\Modules\Storefront\Services\Api\StorefrontShippingQuote;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;

/**
 * P3-08 (aisle, REVIEW Opus: money + payments + idempotency + tenancy) — the goods checkout of the storefront's OWN
 * browser pages (the kit theme `aisle`): the same three calls the headless API makes, on the same services, so a quote
 * the shopper saw, the order it becomes and the hosted payment are exactly the API's:
 *
 *  - `POST {slug}/retail/quote`          the server quote of the cart (items, shipping method of the address, coupon):
 *                                        `{quote, quote_hash, payment_modes, requires, warnings}` — what the cart JS shows
 *  - `POST {slug}/retail/shipping-quote` the shipping methods for the cart and an address (fee, dates, cut-off, carrier)
 *  - `POST {slug}/retail/order`          the order: `Idempotency-Key` (the cart's client UUIDv7), the quote hash the shopper
 *                                        saw, shipping method + address, `payment_mode` pay later or hosted (the pay page of
 *                                        the store's own gateway); a hosted order answers `payment_url`.
 *
 * The store is the route's slug (never a body field); lines name items, variants and options by public id and resolve
 * strictly inside that store; the signed-in customer is the store's own customer. A browser session + CSRF replace the
 * API token ({@see StorefrontApiContext::forWeb()}).
 */
class StorefrontRetailCheckoutController extends Controller
{
    public function __construct(
        private readonly StorefrontCart $cart,
        private readonly StorefrontOrderPlacement $placement,
        private readonly StorefrontShippingQuote $shipping,
        private readonly HostedPayments $hosted,
        private readonly CheckoutPhoneGate $phoneGate,
    ) {}

    public function quote(Request $request, string $slug): JsonResponse
    {
        $store = $this->store($slug);
        $ctx = StorefrontApiContext::forWeb($store, $request, $this->customer());
        $body = StorefrontCart::validate($this->cartBody($request));
        $priced = $this->cart->price($ctx, $body);
        $answer = $this->cart->response($priced, $body);
        $money = new MoneyFormat($store);
        $answer['quote']['cta_label'] = ___('Go to checkout · :amount', ['amount' => $money->format($priced->priced->total)]);
        $free = $this->freeShipping($store, $money, $priced->priced->subtotal);
        if ($free !== null && $priced->orderType === 'ship') {
            $answer['quote']['free_delivery'] = $free;
        }

        return $this->json($answer);
    }

    public function shippingQuote(Request $request, string $slug): JsonResponse
    {
        $store = $this->store($slug);
        $ctx = StorefrontApiContext::forWeb($store, $request, $this->customer());
        $body = $this->cartBody($request);
        $checked = StorefrontShippingQuote::validate(['lines' => $body['lines'], 'address' => $body['address'] ?? null]);
        $priced = $this->cart->price($ctx, ['order_type' => 'ship', 'lines' => $checked['lines']]);

        return $this->json($this->shipping->answer($store, (array) $checked['address'], $priced->priced->subtotal, isset($body['shipping_method_id']) ? (string) $body['shipping_method_id'] : null));
    }

    public function place(Request $request, string $slug): JsonResponse
    {
        $store = $this->store($slug);
        $customer = $this->customer();
        $ctx = StorefrontApiContext::forWeb($store, $request, $customer);
        $body = $this->cartBody($request);

        // a kiosk session (the server-side marker, never a field) orders without a name or phone and pays at the counter
        $kiosk = KioskContext::on((int) $store->id);
        $mode = ! $kiosk && in_array((string) $request->input('pay_via', $request->input('payment_mode')), ['online', 'pay_online', 'card', 'hosted'], true) ? 'hosted' : 'pay_later';
        $body['payment'] = ['mode' => $mode];
        if ($mode === 'hosted') {
            $body['payment']['method_id'] = 'card';
            // the pay page hands the shopper back to the order's tracking page when no return address is kept (see below)
            $body['payment']['return_url'] = rtrim($request->getSchemeAndHttpHost(), '/').route('publicView', ['slug' => $store->slug], false);
        }
        // the store's phone-verification gate (CheckoutOtpController), exactly as the food checkout applies it
        if (! $kiosk && $this->phoneGate->required((int) $store->id)) {
            $phone = $this->phoneGate->normalize((string) $request->input('phone', $request->input('phone-number')));
            if (! $this->phoneGate->verified($phone, (int) $store->id)) {
                return $this->json(['success' => false, 'otp_required' => true, 'phone' => $phone, 'message' => ___('Please verify your phone number to place this order.')], 422);
            }
        }
        $body['customer'] = array_filter([
            'name' => $this->text($request->input('name')) ?? ($kiosk ? ___('Guest') : null),
            'phone' => $kiosk ? null : $this->text($request->input('phone', $request->input('phone-number'))),
            'email' => $kiosk ? null : $this->text($request->input('email')),
        ], fn ($v) => $v !== null);
        $hash = $request->input('quote_hash');
        if (! is_string($hash) || $hash === '') {
            // a client that sent no hash is placing at the price the server computes now (the cart JS always sends it)
            $hash = $this->cart->price($ctx, StorefrontCart::validate($this->cartBody($request)))->hash;
        }
        $body['quote_hash'] = $hash;

        [$created, $replayed] = $this->placement->place($ctx, $request, $body);

        $order = Order::query()->where('store_id', $store->id)->where('track_token', (string) $created['order']['track_token'])->first();
        $out = [
            'success' => true,
            'duplicate' => $replayed,
            'order' => ['number' => $created['order']['number'], 'status' => $created['order']['status']],
            'track_url' => route('store.orderTrack', ['token' => $created['order']['track_token']], false),
        ];
        $next = $created['payment']['next_action'] ?? null;
        if ($mode === 'hosted' && is_array($next) && ! empty($next['url'])) {
            $out['payment_url'] = (string) $next['url'];
            $out['payment_expires_at'] = $next['expires_at'] ?? null;
            if ($order !== null) {
                $this->handBackToTracking($order);
            }
        }

        return $this->json($out, $replayed ? 200 : 201);
    }

    /**
     * aisle §8: the "free shipping" row — how far the items subtotal is from the lowest threshold of the store's standard
     * shipping (a method whose fee is 0 from `free_over_minor`). Null when no method has a threshold.
     *
     * @return array{remaining: array<string, mixed>, progress: float, label: string}|null
     */
    private function freeShipping(Store $store, MoneyFormat $money, int $subtotal): ?array
    {
        $threshold = ShippingMethod::query()->where('store_id', $store->id)->where('active', true)->where('kind', 'standard')
            ->where('currency_code', strtoupper(store_currency((int) $store->id)))->whereNotNull('free_over_minor')->min('free_over_minor');
        if ($threshold === null || (int) $threshold <= 0) {
            return null;
        }
        $remaining = max(0, (int) $threshold - $subtotal);

        return [
            'remaining' => $money->money($remaining),
            'progress' => round(min(1, $subtotal / (int) $threshold), 4),
            'label' => $remaining === 0 ? ___('Free shipping unlocked') : ___('Add :amount more for free shipping', ['amount' => $money->format($remaining)]),
        ];
    }

    /**
     * The API keeps the client's `return_url` on the payment; the browser flow has no address of its own to come back
     * to before the order exists, so the payment is opened without one and the pay page sends the shopper to the order's
     * tracking page (PaymentController::handOffScript).
     */
    private function handBackToTracking(Order $order): void
    {
        $transaction = $this->hosted->transactionFor($order);
        if ($transaction === null) {
            return;
        }
        $details = (array) $transaction->details;
        if (array_key_exists('return_url', $details)) {
            unset($details['return_url']);
            $transaction->details = (object) $details;
            $transaction->save();
        }
    }

    /**
     * The cart-JS body (`lines[]` {item, qty, variant?, options?, note?, substitution?}, kit order-type key, coupon, shipping
     * method, address, slot / hold / express, substitution preference) as the headless cart request.
     *
     * @return array<string, mixed>
     */
    private function cartBody(Request $request): array
    {
        $lines = [];
        foreach ((array) $request->input('lines', []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $row = ['item_id' => (string) ($line['item'] ?? ''), 'qty' => (int) ($line['qty'] ?? 1)];
            if (! empty($line['variant'])) {
                $row['variant_id'] = (string) $line['variant'];
            }
            if (! empty($line['options']) && is_array($line['options'])) {
                $row['option_ids'] = array_values(array_map('strval', $line['options']));
            }
            if (isset($line['note']) && trim((string) $line['note']) !== '') {
                $row['note'] = (string) $line['note'];
            }
            if (! empty($line['substitution']) && is_string($line['substitution'])) {
                $row['substitution'] = $line['substitution']; // basket: this line's out-of-stock choice (a store option's public id)
            }
            $lines[] = $row;
        }
        $body = ['order_type' => (string) ($request->input('order_type') ?: 'ship'), 'lines' => $lines];

        $coupon = $request->input('coupon', $request->input('coupon_code'));
        if (is_string($coupon) && trim($coupon) !== '') {
            $body['coupon'] = trim($coupon);
        }
        $method = $request->input('shipping_method', $request->input('shipping_method_id'));
        if (is_string($method) && $method !== '') {
            $body['shipping_method_id'] = $method;
        }
        $address = $this->address($request);
        if ($address !== null) {
            $body['address'] = $address;
        }
        // basket (P3-09): the delivery / collection time (a slot of this store, the hold that keeps it, or express), and
        // the order's out-of-stock choice; the server validates every one of them against the store
        foreach (['slot_id', 'hold_id', 'substitution_preference'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && $value !== '') {
                $body[$key] = $value;
            }
        }
        if ($request->boolean('express')) {
            $body['express'] = true;
        }

        return $body;
    }

    /** @return array<string, string>|null the address of the quote: an `address` object, or the checkout's flat fields */
    private function address(Request $request): ?array
    {
        $source = is_array($request->input('address')) ? (array) $request->input('address') : $request->all();
        $out = [];
        foreach (['country', 'line1', 'line2', 'city', 'region', 'postal_code', 'notes'] as $field) {
            $value = $this->text($source[$field] ?? null);
            if ($value !== null) {
                $out[$field] = $field === 'country' ? strtoupper($value) : $value;
            }
        }

        return isset($out['country']) ? $out : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
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
