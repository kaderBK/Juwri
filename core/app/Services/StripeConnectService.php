<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\SellerWithdrawal;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\AppStore\Models\SellerStripeAccount;
use Juwri\Modules\Payments\Services\Gateways\GatewaySandbox;
use Juwri\Modules\Payments\Services\Payouts\LiveStripeConnectGateway;
use Juwri\Modules\Payments\Services\Payouts\StripeConnectGateway;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Stripe\Transfer;

/**
 * P9-5b: the Stripe Connect Express cash-out rail. Selected via
 * platform.marketplace.payout_rail. Money still leaves the wallet at request
 * time (P9-5a); this rail settles a pending withdrawal by transferring to the
 * seller's connected account and recording the Stripe transfer id. The live
 * Stripe onboarding of a seller's account is an owner/seller flow; here we
 * consume a stored account id and move the money behind a mockable gateway.
 */
class StripeConnectService
{
    /** The active rail from config, normalised. */
    public static function activeRail(): string
    {
        $rail = (string) config('platform.marketplace.payout_rail', 'manual');

        return in_array($rail, ['manual', 'stripe_connect'], true) ? $rail : 'manual';
    }

    /** True when the stripe_connect rail is active AND usable (key + SDK present). */
    public static function isAvailable(): bool
    {
        return static::activeRail() === 'stripe_connect'
            // Lane D: a Stripe test key (sk_test_…) counts as not configured while payment sandboxes are off.
            && GatewaySandbox::key('stripe', trim((string) config('settings.stripe_secret_key'))) !== ''
            && class_exists(Transfer::class);
    }

    /** Resolve the gateway (test-swappable via the container binding). */
    private static function gateway(): StripeConnectGateway
    {
        return app()->bound(StripeConnectGateway::class)
            ? app(StripeConnectGateway::class)
            : new LiveStripeConnectGateway;
    }

    /**
     * Transfer a pending withdrawal to the seller's connected account.
     *
     * @return string the Stripe transfer id (payout reference)
     *
     * @throws \RuntimeException if the seller has no connected account
     */
    public static function payoutForWithdrawal(SellerWithdrawal $withdrawal): string
    {
        $accountId = SellerStripeAccount::where('user_id', $withdrawal->user_id)->value('stripe_account_id');
        if (! $accountId) {
            throw new \RuntimeException('Seller has no connected Stripe account.');
        }

        // P6-232: transfer in the currency the withdrawal was drawn from (a SAR wallet pays out SAR, never the same
        // number of USD); a currency Stripe cannot carry is refused below.
        $code = strtoupper($withdrawal->minorMoneyCurrency());
        $currency = strtolower($code);
        // Lane D: Stripe's minor unit for THIS currency (JPY 500 → 500, KWD 1.230 → 1230), not ×100; an amount Stripe
        // cannot carry exactly is refused, never rounded.
        $cents = GatewayMinorUnits::gatewayUnsupported('stripe', $code)
            ? null
            : GatewayMinorUnits::gatewayMinor('stripe', Money::ofMajor((string) $withdrawal->amount, $code), $code);
        if ($cents === null || $cents <= 0) {
            throw new \RuntimeException("Withdrawal amount {$withdrawal->amount} {$code} cannot be paid out by Stripe.");
        }

        return static::gateway()->createTransfer($cents, $currency, $accountId, 'withdraw-payout:'.$withdrawal->id);
    }

    // --- Onboarding (seller connects their Stripe Express account) -------------

    /**
     * P5-8 (feature-platform): KYC gate for PAID listings. Only enforced when
     * the platform actually runs Stripe Connect — otherwise paid listings
     * proceed and payouts stay on the manual admin rail.
     */
    public static function kycComplete(User $seller): bool
    {
        if (! static::isAvailable()) {
            return true;
        }

        return (bool) (static::accountFor($seller)?->details_submitted);
    }

    /** The seller's connected-account row, if any. */
    public static function accountFor(User $seller): ?SellerStripeAccount
    {
        return SellerStripeAccount::where('user_id', $seller->id)->first();
    }

    /**
     * Start (or resume) Stripe Express onboarding: create the connected account
     * on first call, then return the hosted onboarding link to redirect to.
     */
    public static function beginOnboarding(User $seller, string $refreshUrl, string $returnUrl): string
    {
        $account = static::accountFor($seller);

        if (! $account) {
            $accountId = static::gateway()->createExpressAccount((string) $seller->email);
            $account = SellerStripeAccount::create([
                'user_id' => $seller->id,
                'stripe_account_id' => $accountId,
                'details_submitted' => false,
            ]);
        }

        return static::gateway()->createOnboardingLink($account->stripe_account_id, $refreshUrl, $returnUrl);
    }

    /**
     * Sync onboarding completion from Stripe (called on return from the hosted
     * flow). Returns the current details_submitted state.
     */
    public static function refreshStatus(User $seller): bool
    {
        $account = static::accountFor($seller);
        if (! $account) {
            return false;
        }

        $submitted = static::gateway()->accountDetailsSubmitted($account->stripe_account_id);
        $account->update(['details_submitted' => $submitted]);

        return $submitted;
    }
}
