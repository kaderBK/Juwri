<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Payouts;

/**
 * P9-5b: the seam between the payout service and the Stripe API. The live
 * implementation calls the Stripe SDK; tests bind a fake so the rail's routing
 * and settlement recording are verifiable without hitting Stripe.
 */
interface StripeConnectGateway
{
    /**
     * Transfer funds from the platform balance to a seller's connected account.
     *
     * @return string the Stripe transfer id (recorded as the payout reference)
     */
    public function createTransfer(int $amountCents, string $currency, string $destinationAccount, string $idempotencyKey): string;

    /**
     * Create a Stripe Express account for a seller.
     *
     * @return string the connected account id (acct_...)
     */
    public function createExpressAccount(string $email): string;

    /**
     * Create a hosted onboarding link for a connected account.
     *
     * @return string the URL the seller is redirected to
     */
    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string;

    /** Whether the connected account has completed onboarding. */
    public function accountDetailsSubmitted(string $accountId): bool;
}
