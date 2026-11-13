<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Contracts\Gateways;

/**
 * M2: cancel merchant-of-record (Paddle Billing, Lemon Squeezy) subscriptions at
 * the gateway.
 *
 * Before this, cancelling a `paddle###…` / `lemon_squeezy###…` plan or add-on
 * only nulled the local `unique_id` — the MoR gateway kept charging the merchant
 * and each renewal webhook re-activated the entitlement.
 *
 * MoR "cancel at period end" semantics: the merchant keeps the period already
 * paid, so callers must NOT expire the local entitlement early. Best-effort — a
 * gateway error is logged and swallowed, never rolled back onto the local
 * cancel (mirrors the existing stripe/paypal behaviour).
 */
class GatewaySubscriptionCanceller
{
    /** Gateways this dispatcher knows how to cancel at source. */
    public const HANDLED = ['paddle', 'lemon_squeezy'];

    /**
     * PayPal subscriptions (not MoR): cancelled through the PayPal provider, errors propagate as before.
     * P1-08b: moved here from User::cancelRecurringSubscription() — Core may not import the Payments module.
     */
    public static function cancelPaypal(string $subscriptionId): void
    {
        app(Gateways::class)->paypalProvider()->cancelSubscription($subscriptionId, ___('Cancelled'));
    }

    public static function handles(string $gateway): bool
    {
        return in_array(strtolower($gateway), self::HANDLED, true);
    }

    public static function cancel(string $gateway, string $subscriptionId): void
    {
        $gateway = strtolower($gateway);

        try {
            if ($gateway === 'paddle') {
                $base = gateway_is_sandbox(config('settings.paddle_sandbox_mode'), false)
                    ? 'https://sandbox-api.paddle.com'
                    : 'https://api.paddle.com';

                Http::withToken(config('settings.paddle_api_key'))
                    ->acceptJson()
                    ->post($base.'/subscriptions/'.$subscriptionId.'/cancel', [
                        'effective_from' => 'next_billing_period',
                    ])
                    ->throw();
            } elseif ($gateway === 'lemon_squeezy') {
                Http::withToken(config('settings.lemon_squeezy_api_key'))
                    ->withHeaders([
                        'Accept' => 'application/vnd.api+json',
                        'Content-Type' => 'application/vnd.api+json',
                    ])
                    ->delete('https://api.lemonsqueezy.com/v1/subscriptions/'.$subscriptionId)
                    ->throw();
            }
        } catch (\Throwable $e) {
            Log::warning('subscription.gateway_cancel_failed', [
                'gateway' => $gateway,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            // P6-244: rethrown like Stripe / PayPal — a swallowed failure cleared the gateway id while Paddle /
            // Lemon Squeezy kept billing (account deletion, plan cancel). Callers decide; none treat it as success.
            throw $e;
        }
    }
}
