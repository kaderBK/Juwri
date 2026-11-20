<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Models\Transaction;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * #23: Apple Pay / Google Pay via Stripe's Payment Request Button. The button
 * confirms a PaymentIntent created here — the amount is ALWAYS computed
 * server-side from the order, never trusted from the client. Wallet support
 * requires the restaurant's Stripe keys plus Stripe-dashboard wallet enablement
 * and Apple Pay domain verification (owner/infra steps).
 */
class WalletPaymentController extends Controller
{
    private function resolveStore($store): Store
    {
        return Store::idOrSlug($store)->firstOrFail();
    }

    /**
     * Whether the wallet button can be shown (publishable key present). Bug batch 3 · F: with ?order_id (this
     * store's order) the currency is the one the ORDER was placed in — what intent() charges — not the store's
     * current currency; without it, the store's.
     */
    public function config(Request $request, $store)
    {
        $store = $this->resolveStore($store);
        $opts = store_options($store->id);
        $publishable = $opts->restaurant_stripe_publishable_key ?? null;
        $order = $request->filled('order_id') ? Order::where('store_id', $store->id)->find((int) $request->input('order_id')) : null;

        return response()->json([
            'enabled' => ! empty($publishable),
            'publishable_key' => $publishable ?: null,
            'currency' => strtolower((string) ($order?->currency_code ?: ($opts->currency_code ?? 'usd'))),
            'country' => strtoupper((string) ($opts->country_code ?? 'US')),
        ]);
    }

    /**
     * Create a PaymentIntent for an order. Amount is derived from the order
     * total server-side. Returns the client_secret for the Payment Request Button.
     */
    public function intent(Request $request, $store)
    {
        $store = $this->resolveStore($store);

        $orderId = (int) $request->input('order_id');
        $order = Order::where('store_id', $store->id)->find($orderId);
        // AUDIT-authz #3: the order id is sequential, so it is not proof the caller is
        // this order's diner. The intent is bound to the order's track token (the same
        // bearer credential as the track page); a wrong/missing token looks like no order.
        $token = (string) $request->input('track_token', '');
        if (! $order || $token === '' || ! is_string($order->track_token) || $order->track_token === ''
            || ! hash_equals($order->track_token, $token)) {
            return response()->json(['error' => 'Order not found.'], 404);
        }
        if ((int) ($order->is_paid ?? 0) === 1) {
            return response()->json(['error' => 'Order already paid.'], 409);
        }
        // Same rule as PaymentController::payableStore(): a cancelled order is never charged.
        if (in_array((string) $order->status, PaymentController::CLOSED_ORDER_STATUSES, true)) {
            return response()->json(['error' => 'This order was cancelled and can no longer be paid.'], 410);
        }

        $opts = store_options($store->id);
        $secret = $opts->restaurant_stripe_secret_key ?? null;
        // Phase 4 item 18: a Stripe test key only while payment sandboxes are allowed (config payments.sandbox.enabled),
        // as at the Stripe checkout (Payments' GatewaySandbox; read here through the config, not the class).
        $testKey = is_string($secret) && preg_match('/^(sk|rk)_test_/', trim($secret)) === 1;
        if (empty($secret) || ($testKey && ! config('payments.sandbox.enabled', false))) {
            return response()->json(['error' => 'Wallet payments are not configured for this restaurant.'], 503);
        }

        // Gateway-currency bug fix: charge in the currency the order was placed in (stamped on the order), never
        // the store's current currency — a USD order must not be charged as the same number of KWD.
        $currency = strtoupper(trim((string) ($order->currency_code ?: ($opts->currency_code ?? 'USD'))));
        // MC-01: round display to the currency's own decimals and derive minor
        // units from the raw total via the single gateway contract so
        // three-decimal fils (OMR/KWD/…) survive instead of being ×100'd.
        // Bug batch 3 · F: charge what is DUE (total less store credit / gift card / Fusion wallet), not the total.
        $amount = round((float) $order->amount_due, app(Gateways::class)->decimals($currency));
        if ($amount <= 0) {
            return response()->json(['error' => 'Invalid order amount.'], 422);
        }
        // Settlement fix (Lane C): every intent is backed by a pending order transaction, settled by the signed
        // `payment_intent.succeeded` webhook (StripeAdapter) through the same exact-amount path as Stripe Checkout —
        // before: nothing ever marked a wallet-paid order as paid. The transaction carries the amount due in the order's
        // currency; the charge is GatewayRounding::chargeMinor() of it: exact, or for a 3-decimal amount between Stripe's
        // 10-fils steps ROUNDED DOWN with the difference recorded and booked at settlement (owner decision) — before:
        // toMinor() rounded half-up (KWD 3.375 → 3380).
        $label = sprintf('%s (#%s)', $store->title, $order->getKey());
        $transaction = Transaction::create([
            'currency_code' => $currency, // first: the money columns are read in this currency
            'product_name' => $label,
            'transaction_description' => $label,
            'product_id' => $order->getKey(),
            'store_id' => $store->getKey(),
            'amount' => (string) Money::ofMajor((string) $order->amount_due, $currency)->major(),
            'base_amount' => (string) Money::ofMajor((string) $order->amount_due, $currency)->major(),
            'transaction_method' => 'order',
            'transaction_ip' => $request->ip(),
            'details' => ['wallet_intent' => true],
        ]);
        $minor = app(Gateways::class)->chargeMinor('stripe', $transaction->money('amount'), $currency);
        if ($minor === null) {
            $transaction->delete();

            return response()->json(['error' => (string) app(Gateways::class)->refusal('stripe', $transaction)], 422);
        }
        $rounding = app(Gateways::class)->recordRounding($transaction, 'stripe', $currency);
        $amount = (float) Money::ofMinor($minor, $currency)->major();
        if ($rounding !== null) {
            $amount = (float) Money::ofMinor((int) $rounding['charged_minor'], $currency)->major();
        }
        $metadata = ['order_id' => $order->id, 'store_id' => $store->id, 'transaction_id' => (string) $transaction->id];
        if ($rounding !== null) {
            $metadata['rounding_adjustment_minor'] = (string) $rounding['adjustment_minor'];
        }

        try {
            Stripe::setApiKey($secret);
            $intent = PaymentIntent::create([
                'amount' => $minor,
                'currency' => strtolower($currency),
                'automatic_payment_methods' => ['enabled' => true], // enables Apple/Google Pay
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::error('[wallet] intent create failed: '.$e->getMessage());
            $transaction->delete();

            return response()->json(['error' => 'Could not start the payment.'], 502);
        }
        $transaction->update(['payment_id' => (string) $intent->id]);

        return response()->json([
            'client_secret' => $intent->client_secret,
            'publishable_key' => $opts->restaurant_stripe_publishable_key ?? null,
            'amount' => $amount,
            'amount_minor' => $minor, // the sheet shows exactly what the PaymentIntent charges (no client-side ×100)
            'currency' => strtolower($currency),
        ]);
    }

    /**
     * Serve the Apple Pay domain-association file (downloaded from Stripe and
     * placed by the owner). Required for Apple Pay domain verification.
     */
    public function applePayDomainAssociation()
    {
        foreach ([
            public_path('.well-known/apple-developer-merchantid-domain-association'),
            storage_path('app/apple-developer-merchantid-domain-association'),
        ] as $path) {
            if (is_file($path)) {
                return response()->file($path, ['Content-Type' => 'text/plain']);
            }
        }
        abort(404);
    }
}
