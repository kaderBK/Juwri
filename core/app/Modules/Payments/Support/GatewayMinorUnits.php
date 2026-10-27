<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Support;

use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Juwri\Core\Money\Money;

/**
 * Converts a major-unit amount to the integer minor-unit amount payment
 * gateways expect (Stripe Checkout, Lemon Squeezy custom_price, wallet
 * PaymentIntents).
 *
 * MC-01 live-mischarge fix: the old inline logic multiplied every currency that
 * was not zero-decimal by 100, charging three-decimal currencies
 * (OMR/KWD/BHD/JOD/TND/LYD/IQD) at one tenth of their value — an OMR 1.234
 * order billed as 0.123 OMR. This class is the single source of truth for
 * gateway minor units; every card-charge path must route through it.
 *
 * P1-05 slice 9c: the *FromMoney() twins take the stored amount as {@see Money} (exact integer minor units in the
 * storage exponent) instead of the major float. They send EXACTLY what toMinor()/toMajorString()/toMajorFloat() send
 * for the same transaction (pinned by GatewayMinorUnitsMoneyTest + GatewayAmountCharacterisationTest):
 *   - same currency and storage exponent ≤ gateway decimals (every ISO currency except MGA/CLF/UYW): exact decimal
 *     arithmetic — the float path gives the same integer/string/float there;
 *   - otherwise (the gateway currency is not the Money's — the store/platform currency moved since the transaction
 *     was stamped — or a storage exponent above the gateway's): the legacy float rule on the same major number, so
 *     the amount sent does not change. The "currency moved" case re-reads the number in the gateway currency (a
 *     USD 12.50 checkout sent as KWD 12.500) — today's behaviour, kept, flagged for P1-08b.
 */
class GatewayMinorUnits
{
    /** Currencies with no minor unit — the amount is passed as-is (×1). */
    public const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Currencies whose minor unit is 1/1000 of the major unit (×1000). */
    public const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * Per-gateway conventions: currencies a gateway takes with FEWER decimals than {@see decimals()} gives (its own
     * rule, not ISO 4217). Read through gatewayDecimals() / gatewayMajorString(); an amount with a fraction the
     * gateway cannot carry is never rounded — gatewayMajorString() returns null and the payment is refused.
     *  - PayPal REST: HUF, TWD and JPY "do not support decimals" (developer.paypal.com/api/rest/reference/currency-codes;
     *    a decimal value is rejected with DECIMAL_PRECISION). JPY is already 0 in ISO; HUF and TWD are 2 there.
     *  - Midtrans: `gross_amount` / item `price` are integers — IDR is charged in whole rupiah (ISO exponent 2).
     *  - Mollie: `amount.value` must carry exactly the currency's decimals, and ISK has 0 there
     *    (docs.mollie.com/docs/multicurrency — "Icelandic króna ISK 0"); before: ISK 500 sent as "500.00".
     */
    public const GATEWAY_ZERO_DECIMAL = [
        'paypal' => ['HUF', 'JPY', 'TWD'],
        'midtrans' => ['IDR'],
        'mollie' => ['ISK'],
    ];

    /**
     * Gateways whose amount field carries at most N decimals whatever the currency (provider docs checked 2026-10-01;
     * quotes below):
     *  - geidea: "the format is a double - a number with 2 digits after the decimal point" (docs.geidea.net/reference/
     *    create-session-v2-1); the request/callback HMAC is over that 2-decimal amount;
     *  - fawry: EGP only; "amount (in two decimal format 10.00)" in every signature (developer.fawrystaging.com);
     *  - paymob: `amount` / `amount_cents` "expressed in cents" (developers.paymob.com …/create-intention) — nothing
     *    documents fils/baisa, so 3-decimal currencies are refused outright ({@see GatewayCurrencies::MAX_CURRENCY_DECIMALS});
     *  - paylink: `amount` is a "Float", decimals not documented (developer.paylink.sa/docs/add-invoice) — the 2 decimals
     *    the integration always sent are kept (unverified).
     * A 3-decimal currency amount reaches them only when its third decimal is 0 (BHD 12.500 → 12.50); anything finer
     * is refused, never truncated.
     * before: noon and fatora were listed here too (2, an assumption from the old round(…, 2)). Noon documents "maximum
     * post-decimal digits as 3 (depending on the currency)" (docs.noonpayments.com/payment-method/benefit/hosted), so
     * KWD/BHD/OMR go at their 3 decimals; Fatora charges only QAR/USD ({@see GatewayCurrencies::FIXED}) and documents a
     * `decimal` amount (example 123.100), so its cap was moot.
     */
    public const GATEWAY_MAX_DECIMALS = [
        'geidea' => 2, 'paylink' => 2, 'fawry' => 2, 'paymob' => 2,
    ];

    /**
     * Gateways that SEND a currency at its usual decimals but only CHARGE multiples of a coarser step — the amount must
     * be exact at N decimals ({@see gatewayPrecision()}); the wire format is unchanged. Anything finer is refused
     * (gatewayMajorString() null), never rounded:
     *  - stripe: 3-decimal currencies "require the least-significant (last) digit to be 0. Your integration must round
     *    amounts to the nearest ten. For example, 5.124 KWD must be rounded to 5120 or 5130" (docs.stripe.com/currencies,
     *    "Three-decimal currencies": BHD JOD KWD OMR TND; archived 2024-01-03 at web.archive.org/web/20240103112923/
     *    https://stripe.com/docs/currencies — the live page no longer shows the section). ISK "is represented as a
     *    two-decimal value, where the decimal amount is always 00 … You can't charge fractions of ISK" (Special cases).
     *    before: fromMoney() rounded KWD 12.345 to 12350 (the customer charged 5 fils more).
     *  - razorpay: "for three decimal currencies, such as KWD, BHD and OMR … pass the last decimal number as 0 … to
     *    accept a payment of 295.991, pass the value as 295990" (razorpay.com/docs/payments/server-integration/…/
     *    integration-steps). before: KWD 12.345 sent as 12350.
     *    Owner decision 2026-10-01: for Stripe and Razorpay, BHD/JOD/KWD/OMR/TND between steps are NOT refused but
     *    charged rounded DOWN, the difference booked ({@see GatewayRounding}); gatewayMinor()
     *    still returns null for them — callers that charge go through GatewayRounding::chargeMinor().
     *  - lemon_squeezy: integer `custom_price` built by fromMoney()'s ×10 rule (it settles on Stripe); an amount that rule
     *    would round is refused. before: KWD 12.345 sent as 12350. Since 2026-10-02 every 3-decimal currency is refused
     *    for it ({@see GatewayCurrencies::MAX_CURRENCY_DECIMALS}: none is in its selling list), so this entry only
     *    backs that refusal.
     *  - mollie: HUF and TWD carry 2 decimals but "for PayPal payments made in Hungarian forint or New Taiwan dollar, we
     *    round the amount up to zero decimals" (docs.mollie.com/docs/multicurrency) — the hosted page offers PayPal, so a
     *    fractional forint/dollar amount is refused. before: HUF 1500.50 sent (PayPal payers charged 1501).
     *
     * @var array<string, array<string, int>>
     */
    public const GATEWAY_STEP_DECIMALS = [
        'stripe' => ['BHD' => 2, 'JOD' => 2, 'KWD' => 2, 'OMR' => 2, 'TND' => 2, 'ISK' => 0],
        'razorpay' => ['BHD' => 2, 'IQD' => 2, 'JOD' => 2, 'KWD' => 2, 'LYD' => 2, 'OMR' => 2, 'TND' => 2],
        'lemon_squeezy' => ['BHD' => 2, 'IQD' => 2, 'JOD' => 2, 'KWD' => 2, 'LYD' => 2, 'OMR' => 2, 'TND' => 2],
        'mollie' => ['HUF' => 0, 'TWD' => 0],
    ];

    /**
     * Currencies a gateway's own docs leave without one safe amount unit — refused outright, never sent
     * ({@see GatewayCurrencies::canCharge()}; gatewayMajorString()/gatewayMinor() give null, paidMatches() false):
     *  - stripe UGX: docs.stripe.com/currencies lists UGX among the zero-decimal currencies ("to charge 500 JPY,
     *    provide 500") AND, under Special cases, says "UGX transitioned to a zero-decimal currency, but backwards
     *    compatibility requires you to represent it as a two-decimal value … to charge 5 UGX, provide an amount value of
     *    500". Read one way the payer is charged 100× too much, the other 1/100 — refusing is the only exact option
     *    (checked 2026-10-02). before: sent ×1 (5000 UGX → 5000).
     *
     * @var array<string, list<string>>
     */
    public const GATEWAY_UNSUPPORTED = [
        'stripe' => ['UGX'],
    ];

    /** Whether $gateway refuses $currency outright ({@see GATEWAY_UNSUPPORTED}). */
    public static function gatewayUnsupported(string $gateway, ?string $currency): bool
    {
        return in_array(strtoupper(trim((string) $currency)), self::GATEWAY_UNSUPPORTED[strtolower(trim($gateway))] ?? [], true);
    }

    /** Decimals $gateway's amount field carries for $currency: its own convention, else {@see decimals()}. */
    public static function gatewayDecimals(string $gateway, ?string $currency): int
    {
        $code = strtoupper(trim((string) $currency));
        $key = strtolower(trim($gateway));
        if (in_array($code, self::GATEWAY_ZERO_DECIMAL[$key] ?? [], true)) {
            return 0;
        }

        return min(self::decimals($code), self::GATEWAY_MAX_DECIMALS[$key] ?? 3);
    }

    /**
     * The finest amount $gateway can CHARGE in $currency, in decimals: gatewayDecimals() narrowed by
     * {@see GATEWAY_STEP_DECIMALS} (Stripe KWD 2: whole multiples of 10 fils; Stripe ISK 0). An amount finer than this
     * is refused, never rounded.
     */
    public static function gatewayPrecision(string $gateway, ?string $currency): int
    {
        $code = strtoupper(trim((string) $currency));
        $decimals = self::gatewayDecimals($gateway, $code);

        return min($decimals, self::GATEWAY_STEP_DECIMALS[strtolower(trim($gateway))][$code] ?? $decimals);
    }

    /**
     * fromMoney() for $gateway — null when the amount is finer than the gateway can charge ({@see gatewayPrecision()}),
     * so the ×10 rule never rounds a customer's amount (Stripe/Razorpay KWD 12.345 → null; KWD 12.340 → 12340).
     */
    public static function gatewayMinor(string $gateway, ?Money $money, ?string $currency = null): ?int
    {
        return self::gatewayMajorString($gateway, $money, $currency) === null ? null : self::fromMoney($money, $currency);
    }

    /**
     * A provider-reported amount string in plain decimal notation. Exponent notation ("1e999999999999" is numeric to PHP)
     * is refused: brick/math tries to allocate the whole number and the process dies with a memory fatal that no catch
     * can stop (found by the P1-18 mutation run). Providers send plain decimals.
     */
    private static function isPlainDecimal(string $value): bool
    {
        return preg_match('/^[+-]?(?:\d{1,30}(?:\.\d{0,30})?|\.\d{1,30})$/', $value) === 1;
    }

    /**
     * The one settle-amount check: whether the amount a provider reports as paid ($paid, major units as it sent it)
     * is EXACTLY the transaction's stored amount as $gateway carries it ({@see gatewayMajorString()}). No tolerance —
     * a payment short (or over) by any minor unit never settles; "12.5", "12.50" and 12.5 are the same amount.
     * False for a missing/non-numeric value or an amount the gateway cannot carry.
     *
     * @param  mixed  $paid  int|float|numeric-string from the provider
     */
    public static function paidMatches(string $gateway, mixed $paid, ?Money $expected, ?string $currency = null): bool
    {
        if ($expected === null || ! (is_int($paid) || (is_float($paid) && is_finite($paid)) || (is_string($paid) && is_numeric(trim($paid)) && self::isPlainDecimal(trim($paid))))) {
            return false;
        }
        $want = self::gatewayMajorString($gateway, $expected, $currency);
        if ($want === null) {
            return false;
        }

        try {
            return Money::decimal(is_string($paid) ? trim($paid) : $paid)->isEqualTo(BigDecimal::of($want));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * majorStringFromMoney() at $gateway's own decimals ({@see GATEWAY_ZERO_DECIMAL}) — null when the amount has a
     * fraction the gateway cannot carry (HUF 1500.50 on PayPal): never rounded, the caller refuses the payment.
     * Identical to majorStringFromMoney() for every currency the gateway takes at the ISO decimals.
     */
    public static function gatewayMajorString(string $gateway, ?Money $money, ?string $currency = null): ?string
    {
        $string = self::majorStringFromMoney($money, $currency);
        $code = self::gatewayCurrency($money, $currency);
        if (self::gatewayUnsupported($gateway, $code)) {
            return null;
        }
        $decimals = self::gatewayDecimals($gateway, $code);

        try {
            // A coarser charging step (GATEWAY_STEP_DECIMALS) only checks the amount; the wire format stays at $decimals.
            BigDecimal::of($string)->toScale(self::gatewayPrecision($gateway, $code));

            return $decimals === self::decimals($code) ? $string : (string) BigDecimal::of($string)->toScale($decimals);
        } catch (RoundingNecessaryException) {
            return null;
        }
    }

    /** Minor-unit exponent for a currency code: 0, 2 (default) or 3. */
    public static function decimals(?string $currency): int
    {
        $code = strtoupper(trim((string) $currency));

        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Integer minor-unit amount for the gateway.
     *
     * Three-decimal currencies are charged in multiples of 10 — Stripe (and
     * Lemon Squeezy, which settles on Stripe) require the trailing minor digit
     * to be 0, so OMR 1.234 → 1234 → 1230.
     *
     * @param  int|float|string  $amount  major-unit amount
     */
    public static function toMinor($amount, ?string $currency): int
    {
        $decimals = self::decimals($currency);
        $minor = (int) round(((float) $amount) * (10 ** $decimals));

        if ($decimals === 3) {
            $minor = (int) (round($minor / 10) * 10);
        }

        return $minor;
    }

    /**
     * Major-unit amount rounded to the currency's real precision, as a float —
     * for gateways whose JSON amount field is a NUMBER (Tap, Tamara, …).
     *
     * The bug this fixes: drivers hardcoded round($amount, 2), which truncated
     * the third minor digit of the fils currencies (OMR/KWD/BHD/JOD/TND/LYD/IQD)
     * — an OMR 1.234 order charged as 1.23, silently under-billing every order.
     *
     * @param  int|float|string  $amount  major-unit amount
     */
    public static function toMajorFloat($amount, ?string $currency): float
    {
        return round((float) $amount, self::decimals($currency));
    }

    /**
     * Same precision as toMajorFloat(), formatted as a fixed-decimal STRING —
     * for gateways whose JSON amount field is a decimal string (Paytabs, Telr,
     * Tabby, …). Zero-decimal currencies render with no decimal point.
     *
     * @param  int|float|string  $amount  major-unit amount
     */
    public static function toMajorString($amount, ?string $currency): string
    {
        return number_format((float) $amount, self::decimals($currency), '.', '');
    }

    /**
     * toMinor() for a stored amount: integer minor units for the gateway in $currency (default: the Money's).
     * A null amount gives 0, like toMinor(null).
     */
    public static function fromMoney(?Money $money, ?string $currency = null): int
    {
        $code = self::gatewayCurrency($money, $currency);
        $exact = self::exact($money, $code);
        if ($exact === null) {
            return self::toMinor($money?->toFloat(), $code);
        }

        $minor = $exact->getUnscaledValue();
        if ($exact->getScale() === 3) {
            // Same ×10 rule as toMinor(): half away from zero to a multiple of 10.
            $minor = $minor->toBigDecimal()->dividedBy(10, 0, RoundingMode::HalfUp)->multipliedBy(10)->toBigInteger();
        }

        return $minor->toInt();
    }

    /** toMajorString() for a stored amount (decimal string at the gateway's decimals). */
    public static function majorStringFromMoney(?Money $money, ?string $currency = null): string
    {
        $code = self::gatewayCurrency($money, $currency);
        $exact = self::exact($money, $code);

        return $exact === null ? self::toMajorString($money?->toFloat(), $code) : (string) $exact;
    }

    /** toMajorFloat() for a stored amount (a number at the gateway's decimals). */
    public static function majorFloatFromMoney(?Money $money, ?string $currency = null): float
    {
        $code = self::gatewayCurrency($money, $currency);
        $exact = self::exact($money, $code);

        return $exact === null ? self::toMajorFloat($money?->toFloat(), $code) : $exact->toFloat();
    }

    private static function gatewayCurrency(?Money $money, ?string $currency): string
    {
        return $currency !== null ? strtoupper(trim($currency)) : (string) $money?->currency();
    }

    /**
     * The amount at the gateway's decimals, exactly — or null when only the legacy float rule gives the amount the
     * gateway was always sent (no amount, another currency, or a storage exponent above the gateway's decimals).
     */
    private static function exact(?Money $money, string $currency): ?BigDecimal
    {
        if ($money === null || $money->currency() !== $currency) {
            return null;
        }
        $decimals = self::decimals($currency);
        if ($money->exponent() > $decimals) {
            return null;
        }

        return BigDecimal::ofUnscaledValue($money->minor(), $money->exponent())->toScale($decimals);
    }
}
