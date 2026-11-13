<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Core\Models\Store;

/**
 * P5-21: card payments on the cashier's phone (SoftPOS) through the store's OWN acquirer account. Every write is
 * idempotent on the till's client UUID per store: the same UUID with the same content answers the first result (one
 * provider call); the same UUID with different content is refused (TerminalRejected 'key_reused'). Amounts are minor units.
 * A provider that is switched off (softpos.<provider>.enabled) or a store without an enabled account → 'not_enabled'.
 *
 * Every payment answer: {client_uuid, kind, provider, reference, state, amount_minor, currency, refunded_minor,
 * masked_pan?, scheme?, auth_code?}.
 */
interface TerminalPayments
{
    /** Is any SoftPOS provider switched on? */
    public function anyEnabled(): bool;

    /** @return list<string> provider keys switched on (geidea_softpos, edfapay_softpos) */
    public function enabledProviders(): array;

    /**
     * The store's terminal accounts, credentials masked.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(Store $store): array;

    /**
     * Set the store's own account for a provider. Credentials are stored encrypted; an empty credential value keeps the
     * stored one.
     *
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    public function saveAccount(Store $store, string $provider, string $merchantId, array $credentials, bool $enabled): array;

    /**
     * Charge a card; with an order, the captured payment marks that order paid once (TerminalPaymentCaptured).
     *
     * @return array<string, mixed>
     */
    public function charge(Store $store, string $clientUuid, int $amountMinor, string $currency, ?int $orderId = null, ?int $tipMinor = null): array;

    /**
     * The one-time claim of a captured charge's settlement (true once; false if already claimed, not captured or not
     * this store's). Call it inside the transaction that marks the order paid, so a rollback releases it.
     */
    public function claimSettlement(Store $store, int $paymentId): bool;

    /**
     * Refund part or all of a captured charge; the refunds of a charge never total more than it.
     *
     * @return array<string, mixed>
     */
    public function refund(Store $store, string $clientUuid, string $chargeClientUuid, int $amountMinor): array;

    /** @return array<string, mixed> */
    public function void(Store $store, string $clientUuid, string $chargeClientUuid): array;

    /**
     * The payment as recorded (no provider call); null when the store has no such payment.
     *
     * @return array<string, mixed>|null
     */
    public function payment(Store $store, string $clientUuid): ?array;

    /**
     * The charge reconciled with the provider (authoritative).
     *
     * @return array<string, mixed>
     */
    public function status(Store $store, string $chargeClientUuid): array;
}
