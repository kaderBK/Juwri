<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use App\Models\Order;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\CardHolds;
use Juwri\Modules\Payments\Services\Gateways\GatewaySandbox;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Srmklive\PayPal\Services\PayPal;

/**
 * P1-08 L1 public surface: Payments' implementation of its public contracts ({@see Wallet}, {@see Ledger},
 * {@see Gateways}). Pure delegation to the existing services — no behaviour of its own.
 */
final class PublicApi implements Gateways, Ledger, Wallet
{
    // Wallet
    public function currency(): string
    {
        return WalletService::currency();
    }

    public function balanceFor(User $user, ?string $currency = null): float
    {
        return WalletService::balanceFor($user, $currency);
    }

    public function ledgerFor(User $user, int $limit = 20): mixed
    {
        return WalletService::ledgerFor($user, $limit);
    }

    public function debit(User $user, float $amount, string $reason = '', string $ref = '', ?string $currency = null): float
    {
        return WalletService::debit($user, $amount, $reason, $ref, $currency);
    }

    public function credit(User $user, float $amount, string $reason = '', string $ref = '', ?int $grantedBy = null, ?string $currency = null): float
    {
        return WalletService::credit($user, $amount, $reason, $ref, $grantedBy, $currency);
    }

    public function creditBack(User $user, float $amount, string $currency, string $reason = '', string $ref = ''): float
    {
        return WalletService::creditBack($user, $amount, $currency, $reason, $ref);
    }

    // Ledger
    public function defaultCurrency(): string
    {
        return LedgerService::defaultCurrency();
    }

    public function accountFor(string $ownerType, int $ownerId, string $kind, ?string $currency = null): LedgerAccount
    {
        return LedgerService::accountFor($ownerType, $ownerId, $kind, $currency);
    }

    public function accountBalance(LedgerAccount $account): float
    {
        return LedgerService::balanceFor($account);
    }

    public function post(array $legs, ?string $transactionId = null, string $reason = ''): string
    {
        return LedgerService::post($legs, $transactionId, $reason);
    }

    // Gateways
    public function controllerFor(string $folder): string
    {
        return PaymentController::controllerFor($folder);
    }

    public function decimals(?string $currency): int
    {
        return GatewayMinorUnits::decimals($currency);
    }

    public function refusal(string $gateway, Transaction $transaction): ?string
    {
        return GatewayCurrencies::refusal($gateway, $transaction);
    }

    public function chargeMinor(string $gateway, ?Money $money, ?string $currency = null): ?int
    {
        return GatewayRounding::chargeMinor($gateway, $money, $currency);
    }

    public function exactChargeMinor(string $gateway, Money $money): ?int
    {
        $code = $money->currency();
        if (GatewayMinorUnits::gatewayUnsupported($gateway, $code)) {
            return null;
        }

        return GatewayMinorUnits::gatewayMinor($gateway, $money, $code);
    }

    public function sandboxKey(string $gateway, string $key): string
    {
        return GatewaySandbox::key($gateway, $key);
    }

    public function allowsSandboxEvent(string $gateway, bool $live): bool
    {
        return GatewaySandbox::allowsEvent($gateway, $live);
    }

    public function recordRounding(Transaction $transaction, string $gateway, ?string $currency = null): ?array
    {
        return GatewayRounding::record($transaction, $gateway, $currency);
    }

    public function receiptLines(?Transaction $transaction): array
    {
        return GatewayRounding::receiptLines($transaction);
    }

    public function transactionCurrency(Transaction $transaction, string $fallback = 'USD'): string
    {
        return Settlement::currency($transaction, $fallback);
    }

    public function accountCurrency(Transaction $transaction, string $fallback = 'USD'): string
    {
        return Settlement::accountCurrency($transaction, $fallback);
    }

    public function bnplGateways(): array
    {
        return BnplEligibility::GATEWAYS;
    }

    public function bnplAvailableForOrder(mixed $store, float $total, string $gateway): bool
    {
        return BnplEligibility::availableForOrder($store, $total, $gateway);
    }

    public function refundOrder(Order $order, float $amount): array
    {
        return GatewayRefundService::refund($order, $amount);
    }

    public function refundStorePayment(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return GatewayRefundService::refundStorePayment($transaction, $amount, $currency);
    }

    public function authorises(string $folder): bool
    {
        return app(CardHolds::class)->authorises($folder);
    }

    public function captureHold(Transaction $transaction, int $minor): array
    {
        return app(CardHolds::class)->capture($transaction, $minor);
    }

    public function resolveCapture(Transaction $transaction, bool $captured, ?string $reference, array $audit = []): array
    {
        return app(CardHolds::class)->resolveCapture($transaction, $captured, $reference, $audit);
    }

    public function voidHold(Transaction $transaction): array
    {
        return app(CardHolds::class)->void($transaction);
    }

    public function paypalProvider(): PayPal
    {
        return PaypalController::getPaypalProvider();
    }
}
