<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Juwri\Modules\Payments\Models\Transaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One payment provider behind the platform's checkout (WI-06).
 *
 * The app hands a pending Transaction to `initiate()` (from any checkout:
 * plans, store orders, add-ons, wallet top-ups, deposits, ...). The provider
 * then comes back twice: the buyer's browser on the return URL
 * (`ipn/{gateway}` → handleReturn) and, for providers that push events, the
 * server-to-server notification (`webhook/{gateway}` → handleWebhook).
 * Settlement itself is shared (see Settlement) so every adapter credits a
 * payment the same way and only once.
 */
interface GatewayAdapter
{
    /** The `payment_folder` / route key, e.g. `stripe`. */
    public function key(): string;

    /** Start the payment: redirect the buyer to the provider (or back with an error). */
    public function initiate(Transaction $transaction): Response;

    /** The buyer's browser returning from the provider. */
    public function handleReturn(Request $request): Response;

    /** A provider notification; must verify its signature before any side effect. */
    public function handleWebhook(Request $request): Response;

    public function supportsRefund(): bool;

    /**
     * Refund (part of) a settled payment on the provider. Bug batch 6: $amount is in $currency (null = the
     * transaction's own); every implementation that calls a provider goes through RefundGuard (own currency only,
     * never more than captured minus refunded, exact at the gateway's decimals).
     *
     * @return array{ok:bool, reference:?string, message:?string, refused?:true}
     */
    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array;
}
