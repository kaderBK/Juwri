<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayRounding;

/**
 * BL-038 O3: a promotion on a platform plan bought through a RECURRING gateway subscription.
 *
 * A promotion that ends (one period, or N months) must not be baked into the recurring price, or the gateway renews at
 * the promotional price for ever. The checkout records `details.recurring_promotion` on the transaction (the full-period
 * amount, how many periods are discounted and the months in one period); the gateway subscription is then created at
 * the FULL-period amount and the gateway itself discounts the covered periods:
 *   Stripe — a coupon (amount_off, once | forever), taken off after the last discounted invoice (see stripeCouponParams)
 *   Paddle — a flat discount (recur + maximum_recurring_intervals)
 *   Lemon  — a fixed discount code (once | repeating + duration_in_months)
 *   PayPal — a TRIAL billing cycle at the promotional price, then the REGULAR full-price cycle
 * The discount is the exact difference between the full-period amount and the first charge, in the transaction
 * currency, so percentage and fixed promotions (and taxes) come out at the amount the checkout showed.
 *
 * Renewal webhooks then accept either amount the subscription was sold at — the first charge, or the full period once
 * the promotion is over ({@see expectedAmounts()}) — and the settled transaction records the amount actually paid
 * ({@see settledMetadata()}).
 */
final class RecurringPromotion
{
    /** Metadata key carrying the full-period amount through the gateway (major units, decimal string). */
    public const RECURRING_AMOUNT = 'recurring_amount';

    /** Metadata key: how many periods the promotion discounts (Stripe removes its discount after that many invoices). */
    public const PROMO_PERIODS = 'promo_periods';

    /** Metadata key: the checkout's per-account promotion reservation (`tx:<pending transaction id>`). */
    public const PROMO_REF = 'promo_ref';

    /**
     * The split of a checkout transaction, or null (no promotion that ends, not a recurring plan purchase).
     *
     * @return array{first: Money, full: Money, off_minor: int, periods: int, months_per_period: int, currency: string, coupon_id: int, sig: string}|null
     */
    public static function split(Transaction $transaction): ?array
    {
        $promo = data_get($transaction->details, 'recurring_promotion');
        if ($transaction->transaction_method !== 'membership' || ! in_array($transaction->frequency, ['MONTHLY', 'YEARLY'], true)
            || (! is_object($promo) && ! is_array($promo))) {
            return null;
        }
        $promo = (array) $promo;
        $currency = Settlement::currency($transaction, 'USD');
        $first = $transaction->money('amount');
        $fullValue = $promo['full_amount'] ?? null;
        if ($first === null || ! is_numeric($fullValue)) {
            return null;
        }
        $full = Money::ofMajor((string) $fullValue, $currency);
        $off = $full->minor() - $first->minor();
        $periods = (int) ($promo['periods'] ?? 0);
        $monthsPerPeriod = (int) ($promo['months_per_period'] ?? ($transaction->frequency === 'YEARLY' ? 12 : 1));
        if ($off <= 0 || $first->minor() <= 0 || $periods < 1 || $monthsPerPeriod < 1) {
            return null;
        }

        $split = [
            'first' => $first,
            'full' => $full,
            'off_minor' => $off,
            'periods' => $periods,
            'months_per_period' => $monthsPerPeriod,
            'currency' => strtoupper($currency),
            'coupon_id' => (int) ($promo['coupon_id'] ?? 0),
        ];
        $split['sig'] = substr(hash('sha256', (string) json_encode([$off, $periods, $split['currency'], $split['coupon_id'], $monthsPerPeriod])), 0, 16);

        return $split;
    }

    /**
     * Stripe coupon parameters for a split, `amount_off` in Stripe's own minor units (the difference between the two
     * plan amounts Stripe charges).
     *
     * BL-038 review (High 2): Juwri's Stripe plans bill every 30 or 365 DAYS (the same day counts as the local
     * subscription, see StripeAdapter::startSubscription), while a `repeating` coupon counts calendar MONTHS — 12 x 30
     * days drift from 12 months, so no month window lands exactly on the Nth invoice (a yearly 2-period offer missed its
     * second invoice about 3 years in 4). So one period is `once`; more are `forever`, and StripeAdapter::invoicePaid
     * deletes the subscription's discount once the Nth invoice is paid (counted by Stripe's own paid invoices). Switching
     * to month/year prices instead would move every renewal date away from the local subscription's day count.
     *
     * @param  array{periods: int, months_per_period: int, currency: string, sig: string}  $split
     * @return array<string, int|string>
     */
    public static function stripeCouponParams(array $split, int $amountOffMinor): array
    {
        return [
            // `promof_`: the forever coupon (an earlier `promo_` id was a month window — never reused).
            'id' => ($split['periods'] > 1 ? 'promof_' : 'promo_').$split['sig'],
            'amount_off' => $amountOffMinor,
            'currency' => strtolower($split['currency']),
            'duration' => $split['periods'] > 1 ? 'forever' : 'once',
            'name' => 'Promotion',
        ];
    }

    /**
     * Paddle Billing flat discount body. `maximum_recurring_intervals` counts every discounted billing period, the first
     * included (Paddle: a discount set for 4 billing periods applies to the initial transaction and 3 renewals).
     *
     * TODO(BL-038 review): this reading of `maximum_recurring_intervals` (first payment included) is from Paddle's
     * documentation only — not yet confirmed against a Paddle sandbox (none available). Pinned by
     * RecurringPromotionGatewayTest; check it in a sandbox before relying on multi-period Paddle offers.
     *
     * @param  array{off_minor: int, periods: int, currency: string, coupon_id: int, sig: string}  $split
     * @return array<string, mixed>
     */
    public static function paddleDiscountBody(array $split): array
    {
        return [
            'description' => 'Promotion '.$split['sig'],
            'type' => 'flat',
            'amount' => (string) $split['off_minor'],
            'currency_code' => $split['currency'],
            'enabled_for_checkout' => false,
            'recur' => $split['periods'] > 1,
            'maximum_recurring_intervals' => $split['periods'] > 1 ? $split['periods'] : null,
            'custom_data' => ['coupon_id' => (string) $split['coupon_id']],
        ];
    }

    /**
     * Lemon Squeezy fixed discount-code attributes (Lemon counts months: a yearly plan needs 12 per discounted payment).
     *
     * @param  array{off_minor: int, periods: int, months_per_period: int, sig: string}  $split
     * @return array<string, mixed>
     */
    public static function lemonDiscountAttributes(array $split): array
    {
        $attributes = [
            'name' => 'Promotion '.$split['sig'],
            'code' => self::lemonCode($split),
            'amount' => $split['off_minor'],
            'amount_type' => 'fixed',
            'duration' => $split['periods'] > 1 ? 'repeating' : 'once',
            'is_limited_to_products' => false,
            'is_limited_redemptions' => false,
        ];
        if ($attributes['duration'] === 'repeating') {
            $attributes['duration_in_months'] = $split['periods'] * $split['months_per_period'];
        }

        return $attributes;
    }

    /** @param  array{sig: string}  $split */
    public static function lemonCode(array $split): string
    {
        return 'PROMO'.strtoupper($split['sig']);
    }

    /**
     * PayPal billing cycles: a TRIAL cycle at the promotional price for the discounted periods, then the REGULAR cycle
     * at the full price. Prices are PayPal decimal strings in the subscription currency.
     *
     * @param  array{periods: int, currency: string}  $split
     * @param  array<string, mixed>  $frequency
     * @return list<array<string, mixed>>
     */
    public static function paypalBillingCycles(array $split, array $frequency, string $firstPrice, string $fullPrice): array
    {
        $money = fn (string $value): array => ['fixed_price' => ['value' => $value, 'currency_code' => $split['currency']]];

        return [
            ['frequency' => $frequency, 'tenure_type' => 'TRIAL', 'sequence' => 1, 'total_cycles' => $split['periods'], 'pricing_scheme' => $money($firstPrice)],
            ['frequency' => $frequency, 'tenure_type' => 'REGULAR', 'sequence' => 2, 'total_cycles' => 0, 'pricing_scheme' => $money($fullPrice)],
        ];
    }

    /**
     * The amounts a renewal of this subscription may be charged (Money in the subscription currency): the first charge
     * (`amount`) and, for a promotion that ends, the full period ({@see recurringAmount()}). `$fallbackCurrency` (the
     * charge's own currency) is used when the metadata names none (subscriptions sold before it was carried).
     *
     * @param  object|array<string, mixed>  $metadata  the checkout attributes round-tripped through the gateway
     * @return list<Money>
     */
    public static function expectedAmounts(object|array $metadata, ?string $fallbackCurrency = null): array
    {
        $currency = self::currency($metadata, $fallbackCurrency);
        if ($currency === '') {
            return [];
        }
        $out = [];
        foreach ([((array) $metadata)['amount'] ?? null, self::recurringAmount($metadata)] as $value) {
            if (is_numeric($value)) {
                $out[] = Money::ofMajor((string) $value, $currency);
            }
        }

        return $out;
    }

    /**
     * The full-period amount a subscription renews at once its promotion is over: the metadata's `recurring_amount`, or
     * (rows sold by the old platform) the one inside its JSON `details`. Null when there is none.
     *
     * @param  object|array<string, mixed>  $metadata
     */
    public static function recurringAmount(object|array $metadata): ?string
    {
        $metadata = (array) $metadata;
        $value = $metadata[self::RECURRING_AMOUNT] ?? null;
        if ($value === null && isset($metadata['details'])) {
            $details = is_string($metadata['details']) ? json_decode($metadata['details'], true) : (array) $metadata['details'];
            $value = is_array($details) ? ($details[self::RECURRING_AMOUNT] ?? data_get($details, 'recurring_promotion.full_amount')) : null;
        }

        return is_scalar($value) && is_numeric((string) $value) ? (string) $value : null;
    }

    /** @param  object|array<string, mixed>  $metadata */
    private static function currency(object|array $metadata, ?string $fallbackCurrency): string
    {
        $currency = strtoupper(trim((string) (((array) $metadata)['currency_code'] ?? '')));

        return $currency !== '' ? $currency : strtoupper(trim((string) $fallbackCurrency));
    }

    /**
     * The metadata to settle a renewal with: when the gateway charged the full-period amount (the promotion is over),
     * the settled transaction records that amount, not the first charge.
     *
     * @param  object  $metadata  the checkout attributes round-tripped through the gateway
     */
    public static function settledMetadata(object $metadata, int $paidMinor, string $gateway, ?string $fallbackCurrency = null): object
    {
        $recurring = self::recurringAmount($metadata);
        $currency = self::currency($metadata, $fallbackCurrency);
        if ($recurring === null || $currency === '') {
            return $metadata;
        }
        $full = Money::ofMajor((string) $recurring, $currency);
        $expected = GatewayRounding::chargeMinor($gateway, $full, $currency) ?? $full->minor();
        if ($paidMinor !== $expected && $paidMinor !== $full->minor()) {
            return $metadata;
        }
        $settled = clone $metadata;
        $settled->amount = $full->major();

        return $settled;
    }
}
