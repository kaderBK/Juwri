<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Support\Facades\Log;

/**
 * Phase 4 item 18: provider sandboxes behind one flag (config `payments.sandbox.enabled`, env PAYMENTS_SANDBOX_ENABLED,
 * default OFF).
 *
 * Stripe, Tap and Moyasar tell a test account by its key (`sk_test_…`, `pk_test_…`, Stripe `rk_test_…`); PayPal by
 * the store's / platform's sandbox switch. With the flag off a test key or the sandbox switch counts as "not
 * configured" (no payment is started on it) and a provider notification that says it comes from a test account
 * (Stripe `livemode: false`, Tap `live_mode: false`, Moyasar `live: false`) is refused. With the flag on (staging, demo,
 * the test suite) both work as before. Live keys are never affected.
 */
final class GatewaySandbox
{
    /** Key prefixes of a provider's test account. */
    private const TEST_PREFIXES = [
        'stripe' => ['sk_test_', 'pk_test_', 'rk_test_'],
        'tap' => ['sk_test_', 'pk_test_'],
        'moyasar' => ['sk_test_', 'pk_test_'],
        'tabby' => ['sk_test_', 'pk_test_'],
        'paystack' => ['sk_test_', 'pk_test_'],
        'flutterwave' => ['FLWSECK_TEST', 'FLWPUBK_TEST'],
        'razorpay' => ['rzp_test_'],
        'mollie' => ['test_'],
    ];

    public static function enabled(): bool
    {
        return (bool) config('payments.sandbox.enabled', false);
    }

    public static function isTestKey(string $gateway, string $key): bool
    {
        $key = trim($key);
        foreach (self::TEST_PREFIXES[$gateway] ?? [] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** May a payment be made with this key? A live key always; a test key only while sandboxes are allowed. */
    public static function allowsKey(string $gateway, string $key): bool
    {
        if (! self::isTestKey($gateway, $key)) {
            return true;
        }

        return self::allows($gateway, 'test key');
    }

    /** May the provider's sandbox be used (PayPal's sandbox switch)? */
    public static function allowsMode(string $gateway, bool $sandbox): bool
    {
        return ! $sandbox || self::allows($gateway, 'sandbox mode');
    }

    /**
     * Lane D: every adapter whose sandbox is picked by its own setting puts its credential set through here. A
     * sandbox-mode set while sandboxes are off counts as "not configured": every string in it is blanked, so no payment
     * starts, no status/refund call is made and a webhook (verified with the same credentials) is refused. A blank
     * sandbox setting reads as sandbox (gateway_is_sandbox default), so in production a blank mode is refused too.
     *
     * @template T of array<string, mixed>
     *
     * @param  T  $creds
     * @return T
     */
    public static function gate(string $gateway, bool $sandbox, array $creds): array
    {
        if (self::allowsMode($gateway, $sandbox)) {
            return $creds;
        }

        /** @var T */
        return array_map(static fn (mixed $v): mixed => is_string($v) ? '' : $v, $creds);
    }

    /** A test key (per TEST_PREFIXES) as "" while sandboxes are off; any other key unchanged. */
    public static function key(string $gateway, string $key): string
    {
        return self::allowsKey($gateway, $key) ? $key : '';
    }

    /** May a notification that says it is live ($live) / from a test account be processed? */
    public static function allowsEvent(string $gateway, bool $live): bool
    {
        return $live || self::allows($gateway, 'test-mode notification');
    }

    private static function allows(string $gateway, string $what): bool
    {
        if (self::enabled()) {
            return true;
        }
        Log::warning("[{$gateway}] {$what} refused: payment sandboxes are switched off (PAYMENTS_SANDBOX_ENABLED)");

        return false;
    }
}
