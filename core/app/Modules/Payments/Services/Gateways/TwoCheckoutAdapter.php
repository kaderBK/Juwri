<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2Checkout (Verifone) Payment API, token flow
 * (https://www.2checkout.com/documentation/payment-api/ — the legacy
 * `authService` API this gateway has always used).
 *
 * initiate renders our card page: 2co.js turns the card into a one-time token
 * in the browser (seller id + publishable key) and the page posts the token
 * with our `order_id` to `ipn/two_checkout`. The return then authorises the
 * charge server-side with the private key (merchantOrderId = our transaction
 * id, amount and currency from the transaction, never from the browser);
 * APPROVED for our total settles once and keeps 2Checkout's order number as
 * the payment_id. There is no provider notification to verify: the charge is
 * synchronous, so `webhook/two_checkout` only acknowledges.
 *
 * Keys: store orders use the store's seller id / keys (BYOK, options prefixed
 * `restaurant_2checkout_`), everything else the platform's `checkout_*`.
 */
class TwoCheckoutAdapter implements GatewayAdapter
{
    public const API = 'https://www.2checkout.com/checkout/api/1/';

    public function key(): string
    {
        return 'two_checkout';
    }

    public function initiate(Transaction $transaction): Response
    {
        $creds = $this->credentials($transaction);
        $store = Settlement::store($transaction);
        Settlement::useStoreCurrency($transaction);

        return response()->view('owner.billing.gateways.two-checkout', [
            'transaction' => $transaction,
            'checkout_seller_id' => $creds['seller_id'],
            'checkout_public_key' => $creds['public_key'],
            'sandbox' => $creds['sandbox'],
            'color' => (string) ($store->color ?? config('settings.theme_color')),
            'cancel_url' => Settlement::cancelUrl($transaction),
        ]);
    }

    public function handleReturn(Request $request): Response
    {
        $id = (string) $request->input('order_id', '');
        $transaction = ctype_digit($id) ? Settlement::pending(['id' => $id], withoutPaymentId: true) : null;
        if ($transaction === null) {
            return Settlement::invalid();
        }
        $token = (string) $request->input('2checkoutToken', '');
        if ($token === '') {
            return Settlement::failed();
        }

        try {
            $creds = $this->credentials($transaction);
            $currency = Settlement::currency($transaction, 'USD');
            $address = $this->address($transaction);
            $body = [
                'sellerId' => $creds['seller_id'],
                'privateKey' => $creds['private_key'],
                'merchantOrderId' => (string) $transaction->id,
                'token' => $token,
                'currency' => $currency,
                'total' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency),
                'billingAddr' => $address,
                'shippingAddr' => $address,
            ];
            if ($creds['sandbox']) {
                $body['demo'] = true;
            }

            $result = (array) Http::acceptJson()->asJson()->timeout(60)
                ->post(self::API.rawurlencode($creds['seller_id']).'/rs/authService', $body)->json();
            $charge = is_array($result['response'] ?? null) ? $result['response'] : [];

            if (($charge['responseCode'] ?? null) !== 'APPROVED') {
                $reason = (string) ($result['exception']['errorMsg'] ?? $charge['responseMsg'] ?? '');
                Log::info('2Checkout Payment failed: '.$reason);

                return Settlement::failed($reason);
            }
            if (isset($charge['total']) && ! Settlement::amountMatches($transaction, (float) $charge['total'], (string) ($charge['currencyCode'] ?? ''), 'two_checkout', $currency)) {
                return Settlement::failed();
            }

            // J25-7: credit durable via the outbox, same answer to the buyer. P6-100: claimed — only a still-pending
            // row is moved to paid, so a double-submitted card form racing itself settles once.
            return Settlement::settleForBuyer($transaction, 'two_checkout', isset($charge['orderNumber']) ? (string) $charge['orderNumber'] : null, claim: true)
                ?? Settlement::invalid();
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('2checkout', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        // Charges are authorised synchronously on the return; nothing to settle here.
        return new JsonResponse(['message' => 'ignored', 'status' => 200]);
    }

    public function supportsRefund(): bool
    {
        // Refunds need 2Checkout's admin API user, which the settings do not hold.
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return ['ok' => false, 'reference' => null, 'message' => '2Checkout refunds are made from the 2Checkout panel.'];
    }

    /* ------------------------------------------------------------ internals */

    /** @return array<string, string> */
    private function address(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $geo = user_ip_lookup()['location'];
            $known = fn ($value) => ! in_array((string) $value, ['', 'Other', 'Unknown'], true) ? (string) $value : '';
            $store = Store::find($transaction->store_id);

            return [
                'name' => (string) ($transaction->details->customer_name ?? ''),
                'addrLine1' => (string) ($store->address ?? '') ?: $known($geo['city'] ?? ''),
                'city' => $known($geo['city'] ?? ''),
                'zipCode' => $known($geo['postal_code'] ?? ''),
                'country' => $known($geo['country'] ?? ''),
                'email' => (string) ($transaction->details->customer_email ?? $store?->user->email ?? ''),
                'phoneNumber' => (string) ($transaction->details->customer_phone ?? ''),
            ];
        }

        $user = request()->user();
        $options = $user !== null ? user_options($user->id) : null;

        return [
            'name' => (string) ($options->billing_name ?? $user->name ?? ''),
            'addrLine1' => (string) ($options->billing_address ?? ''),
            'city' => (string) ($options->billing_city ?? ''),
            'state' => (string) ($options->billing_state ?? ''),
            'zipCode' => (string) ($options->billing_zipcode ?? ''),
            'country' => (string) ($options->billing_country ?? ''),
            'email' => (string) ($user->email ?? request()->input('email', '')),
            'phoneNumber' => '',
        ];
    }

    /** @return array{seller_id:string,public_key:string,private_key:string,sandbox:bool} */
    private function credentials(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $options = store_options($transaction->store_id);
            [$seller, $public, $private, $mode] = [$options->restaurant_2checkout_account_number ?? '', $options->restaurant_2checkout_public_key ?? '',
                $options->restaurant_2checkout_private_key ?? '', $options->restaurant_2checkout_sandbox_mode ?? ''];
        } else {
            [$seller, $public, $private, $mode] = [config('settings.checkout_account_number'), config('settings.checkout_public_key'),
                config('settings.checkout_private_key'), config('settings.2checkout_sandbox_mode')];
        }

        $sandbox = Settlement::isSandbox($mode);

        return GatewaySandbox::gate('2checkout', $sandbox, [
            'seller_id' => trim((string) $seller),
            'public_key' => trim((string) $public),
            'private_key' => trim((string) $private),
            'sandbox' => $sandbox,
        ]);
    }
}
