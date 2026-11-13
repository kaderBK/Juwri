<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use App\Models\Order;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Srmklive\PayPal\Services\PayPal;

/**
 * P1-08 L1 public surface: what modules above Payments (Billing, Ordering) need from the gateway layer — gateway
 * currency/decimal rules, cash rounding, BNPL eligibility, order refunds, the gateway controller of a payment folder.
 * Same semantics as the Payments services each method names (bound to GatewaysApi by PaymentsServiceProvider).
 */
interface Gateways
{
    /** The gateway controller class of a payment_folder (PaymentController::controllerFor). */
    public function controllerFor(string $folder): string;

    /** Decimals a gateway charge carries in this currency (GatewayMinorUnits::decimals). */
    public function decimals(?string $currency): int;

    /** Why this gateway cannot take this transaction's currency/amount, or null (GatewayCurrencies::refusal). */
    public function refusal(string $gateway, Transaction $transaction): ?string;

    /** The charge in gateway minor units (GatewayRounding::chargeMinor). */
    public function chargeMinor(string $gateway, ?Money $money, ?string $currency = null): ?int;

    /**
     * The charge in gateway minor units only when the gateway can take it exactly: null when the gateway is refused for
     * the currency or the amount is finer than its unit (refused, never rounded; GatewayMinorUnits::gatewayMinor).
     */
    public function exactChargeMinor(string $gateway, Money $money): ?int;

    /** The key when it may be used now: a test key only while payment sandboxes are allowed, else '' (GatewaySandbox::key). */
    public function sandboxKey(string $gateway, string $key): string;

    /** Whether a provider notification may be processed: live always, test-mode only while sandboxes are allowed. */
    public function allowsSandboxEvent(string $gateway, bool $live): bool;

    /**
     * Record the cash rounding of a gateway charge (GatewayRounding::record).
     *
     * @return array<string, mixed>|null
     */
    public function recordRounding(Transaction $transaction, string $gateway, ?string $currency = null): ?array;

    /**
     * Receipt lines for a rounded charge (GatewayRounding::receiptLines).
     *
     * @return array<string, mixed>
     */
    public function receiptLines(?Transaction $transaction): array;

    /** The transaction's charge currency (Settlement::currency). */
    public function transactionCurrency(Transaction $transaction, string $fallback = 'USD'): string;

    /** The currency of the account the transaction settles into (Settlement::accountCurrency). */
    public function accountCurrency(Transaction $transaction, string $fallback = 'USD'): string;

    /** @return list<string> the BNPL gateways (BnplEligibility::GATEWAYS) */
    public function bnplGateways(): array;

    /** Whether a BNPL gateway may be offered for this store and total (BnplEligibility::availableForOrder). */
    public function bnplAvailableForOrder(mixed $store, float $total, string $gateway): bool;

    /**
     * Refund an order's gateway payment (GatewayRefundService::refund).
     *
     * @return array<string, mixed>
     */
    public function refundOrder(Order $order, float $amount): array;

    /**
     * P3-14: refund a store payment that is not an order (a booking deposit) to its gateway, capped by what was paid
     * (GatewayRefundService::refundStorePayment). `automatic` = the provider took it; otherwise a tracked manual refund
     * — unless `refused` (refused before any provider call: record nothing).
     *
     * @return array<string, mixed>
     */
    public function refundStorePayment(Transaction $transaction, float $amount, ?string $currency = null): array;

    /** P3-09: can the gateway of this payment folder put a card hold and capture later (CardHolds::authorises)? */
    public function authorises(string $folder): bool;

    /**
     * P3-09: capture `$minor` (transaction currency, ≤ the authorised amount) of an authorised card hold
     * (CardHolds::capture). On success the transaction carries the captured amount and is pending again for settlement.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function captureHold(Transaction $transaction, int $minor): array;

    /**
     * P3-09: release an authorised card hold (CardHolds::void).
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function voidHold(Transaction $transaction): array;

    /** The configured PayPal client (PaypalController::getPaypalProvider). */
    public function paypalProvider(): PayPal;
}
