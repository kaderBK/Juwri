<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Payouts;

use Juwri\Modules\Payments\Services\Gateways\GatewaySandbox;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;
use Stripe\Transfer;

/**
 * P9-5b: the real Stripe Connect gateway. Uses the platform Stripe secret key
 * (same source as the rest of the app) to create a Transfer to the seller's
 * connected account. Only instantiated when the SDK + key are present
 * (StripeConnectService::isAvailable), so no hard dependency at boot.
 */
class LiveStripeConnectGateway implements StripeConnectGateway
{
    public function createTransfer(int $amountCents, string $currency, string $destinationAccount, string $idempotencyKey): string
    {
        Stripe::setApiKey(self::secret());

        $transfer = Transfer::create([
            'amount' => $amountCents,
            'currency' => $currency,
            'destination' => $destinationAccount,
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);

        return (string) $transfer->id;
    }

    public function createExpressAccount(string $email): string
    {
        Stripe::setApiKey(self::secret());

        $account = Account::create([
            'type' => 'express',
            'email' => $email,
        ]);

        return (string) $account->id;
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        Stripe::setApiKey(self::secret());

        $link = AccountLink::create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return (string) $link->url;
    }

    public function accountDetailsSubmitted(string $accountId): bool
    {
        Stripe::setApiKey(self::secret());

        return (bool) Account::retrieve($accountId)->details_submitted;
    }

    /** Lane D: a Stripe test key (sk_test_…) is refused while payment sandboxes are off — no test transfer marks a payout paid. */
    private static function secret(): string
    {
        $key = GatewaySandbox::key('stripe', trim((string) config('settings.stripe_secret_key')));
        if ($key === '') {
            throw new \RuntimeException('Stripe is not configured.');
        }

        return $key;
    }
}
