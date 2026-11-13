<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;

/**
 * Which currencies a payment gateway can charge — the platform/store-checkout counterpart of
 * ResellerCurrency::canCharge() (reseller billing, the Reseller module).
 *
 * A payment is always charged in the transaction's own stamped currency ({@see Settlement::currency()}), never
 * converted and never re-read in another currency. A gateway that cannot charge that currency refuses the payment
 * before any call to the provider, with a payer-facing message and a warning log:
 *
 *  - FIXED: gateways that only ever charge one currency (the request has no currency field or a hard-coded one), or a
 *    documented closed list (Fatora QAR/USD, Tamara's five Gulf currencies).
 *    Any other currency is refused — those payments used to be sent as the same NUMBER in the fixed currency.
 *    (Fawry/EGP and T-Lync/LYD have their own checks in their controllers, WI-06d/WI-06e.)
 *  - LISTED: gateways with a documented, short currency list. Enforced only when the transaction's currency is
 *    not the store's/platform's current currency (it moved after the checkout was created, or a store-context
 *    payment runs on the platform account), so a same-currency payment behaves exactly as before; there an
 *    unsupported currency is still refused by the provider itself.
 *  - every other gateway is multi-currency: the provider validates the currency against the merchant account and
 *    its refusal reaches the payer through the adapter's normal error path.
 *
 * Precision: a gateway that takes a currency with fewer decimals than ISO ({@see GatewayMinorUnits::GATEWAY_ZERO_DECIMAL}
 * — PayPal HUF/TWD, Midtrans IDR) refuses an amount with a fraction it cannot carry (HUF 1500.50) before any provider
 * call, and so does one that charges only coarser steps ({@see GatewayMinorUnits::GATEWAY_STEP_DECIMALS} — Stripe/Razorpay
 * KWD 12.345, Mollie HUF 1500.50). The customer's amount is never rounded: refusing is the only exact option.
 */
final class GatewayCurrencies
{
    /** @var array<string, list<string>> */
    public const FIXED = [
        'paytm' => ['INR'],
        'midtrans' => ['IDR'],
        // Fatora: "if it is different from QAR or USD, Fatora will convert the amount to USD"
        // (fatora.io/api/currencySupport.php) — the payer would be charged in USD. before: a KWD/SAR payment was sent
        // and charged as converted USD.
        'fatora' => ['QAR', 'USD'],
        // Tamara: `currency` enum SAR AED BHD KWD OMR (docs.tamara.co/reference/createcheckoutsession) — a closed list,
        // not one currency. before: any currency was sent and refused by Tamara.
        'tamara' => ['SAR', 'AED', 'BHD', 'KWD', 'OMR'],
        // GoCardless Direct Debit schemes: Bacs GBP, SEPA EUR, ACH USD, PAD CAD, BECS AUD, BECS NZ NZD, Autogiro SEK,
        // Betalingsservice DKK (gocardless.com/guides — supported schemes). before: any currency was sent and refused by
        // GoCardless after the billing request.
        // Moyasar: SAR — the currency its docs document for every account (amounts in halalas). Phase 4 item 18.
        'moyasar' => ['SAR'],
        'gocardless' => ['GBP', 'EUR', 'USD', 'CAD', 'AUD', 'NZD', 'SEK', 'DKK'],
    ];

    /**
     * Gateways that cannot charge currencies with more minor-unit decimals than N at all. Paymob takes an integer
     * `amount_cents` (×100) and the account region host (P6-108b) does not change it: whether a 3-decimal currency (OMR on Paymob
     * Oman) would be read as cents or as fils is not documented here, so — safest — those currencies are refused
     * rather than risk a 10× mischarge (before: OMR 12.345 sent as 1235 "cents").
     *
     * @var array<string, int>
     */
    public const MAX_CURRENCY_DECIMALS = [
        'paymob' => 2,
        // Lemon Squeezy's 131 selling currencies include none of BHD/IQD/JOD/KWD/LYD/OMR/TND
        // (docs.lemonsqueezy.com/help/payments/currencies). before: an amount on the 10-fils step was sent (KWD 12.340).
        'lemon_squeezy' => 2,
    ];

    /** @var array<string, list<string>> */
    public const LISTED = [
        // PayPal REST currency codes (same list as reseller billing, ResellerCurrency::SUPPORTED['paypal']).
        'paypal' => [
            'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR',
            'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
        ],
        // Paystack supported currencies.
        'paystack' => ['NGN', 'GHS', 'ZAR', 'KES', 'USD', 'XOF', 'EGP', 'RWF'],
        // iyzico checkout form currencies (prices are sent with 2 decimals).
        'iyzico' => ['TRY', 'EUR', 'USD', 'GBP', 'IRR', 'NOK', 'RUB', 'CHF'],
    ];

    /** Whether $gateway can charge $currency for this transaction (see the class rules). */
    public static function canCharge(string $gateway, Transaction $transaction): bool
    {
        $currency = Settlement::currency($transaction);
        if (GatewayMinorUnits::gatewayUnsupported($gateway, $currency)) {
            return false; // Stripe UGX: its docs give two contradicting amount units
        }
        if (isset(self::MAX_CURRENCY_DECIMALS[$gateway]) && GatewayMinorUnits::decimals($currency) > self::MAX_CURRENCY_DECIMALS[$gateway]) {
            return false;
        }
        if (isset(self::FIXED[$gateway])) {
            return in_array($currency, self::FIXED[$gateway], true);
        }
        if (isset(self::LISTED[$gateway]) && $currency !== Settlement::accountCurrency($transaction, $currency)) {
            return in_array($currency, self::LISTED[$gateway], true);
        }

        return true;
    }

    /**
     * Null when the gateway can charge the transaction's currency; otherwise logs the refusal and returns the
     * message for the payer.
     */
    public static function refusal(string $gateway, Transaction $transaction): ?string
    {
        $currency = Settlement::currency($transaction);
        if (self::canCharge($gateway, $transaction)) {
            return self::precisionRefusal($gateway, $transaction, $currency);
        }
        Log::warning("[{$gateway}] transaction {$transaction->id} is in {$currency}; this gateway cannot charge it — payment refused");

        return (string) ___('This payment method cannot charge in :currency. Please choose another payment method.', ['currency' => $currency]);
    }

    /**
     * Null when $gateway can carry the transaction's amount exactly in $currency; otherwise logs and returns the
     * message for the payer. before: the amount was sent as is (PayPal HUF "1500.50" → DECIMAL_PRECISION refusal).
     */
    private static function precisionRefusal(string $gateway, Transaction $transaction, string $currency): ?string
    {
        // Owner decision: Stripe/Razorpay 3-decimal amounts between steps are charged rounded DOWN, not refused
        // (GatewayRounding) — before (earlier today): refused.
        if (GatewayRounding::quote($gateway, $transaction->money('amount'), $currency) !== null
            || GatewayMinorUnits::gatewayMajorString($gateway, $transaction->money('amount'), $currency) !== null) {
            return null;
        }

        return self::precisionMessage($gateway, $transaction, $currency);
    }

    /**
     * For the controller-based gateways (Noon, Geidea, Paylink, Fatora, Paymob, Fawry): the amount to send in
     * $currency at the gateway's own decimals, or the payer-facing refusal — currency first ({@see refusal()}), then
     * precision. Never rounded.
     *
     * @return array{0: ?string, 1: ?string} [amount, null] or [null, refusal]
     */
    public static function amountOrRefusal(string $gateway, Transaction $transaction, string $currency): array
    {
        $refusal = self::refusal($gateway, $transaction);
        if ($refusal !== null) {
            return [null, $refusal];
        }
        $amount = GatewayMinorUnits::gatewayMajorString($gateway, $transaction->money('amount'), $currency);

        return $amount === null ? [null, self::precisionMessage($gateway, $transaction, $currency)] : [$amount, null];
    }

    private static function precisionMessage(string $gateway, Transaction $transaction, string $currency): string
    {
        $money = $transaction->money('amount');
        $amount = GatewayMinorUnits::majorStringFromMoney($money, $currency);
        // The finest amount it can charge (Stripe KWD: 2 — whole tens of fils); before: gatewayDecimals() (3 for KWD).
        $decimals = GatewayMinorUnits::gatewayPrecision($gateway, $currency);
        Log::warning("[{$gateway}] transaction {$transaction->id} is {$amount} {$currency}; this gateway carries {$decimals} decimals in {$currency} — payment refused, not rounded");

        if ($decimals === 0) {
            return (string) ___('This payment method only charges whole amounts in :currency, and this payment is :amount :currency. Please choose another payment method.', [
                'currency' => $currency, 'amount' => $amount,
            ]);
        }

        return (string) ___('This payment method cannot charge :amount :currency exactly: it takes at most :decimals decimals in :currency. Please choose another payment method.', [
            'amount' => $amount, 'currency' => $currency, 'decimals' => $decimals,
        ]);
    }
}
