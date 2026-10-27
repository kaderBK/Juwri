<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal\SoftPos;

use Illuminate\Http\Request;
use Juwri\Modules\Payments\Models\TerminalAccount;

/**
 * P5-21: one SoftPOS provider's server API, with the store's own account. Amounts are minor units of the currency.
 * Every answer is normalised to {reference, state, masked_pan?, scheme?, auth_code?} with state one of the
 * PaymentTerminal states (authorised / captured / declined / pending) or voided / refunded.
 * A transport problem throws \RuntimeException (the caller retries with the same client UUID: the provider gets the same
 * merchant reference and does not create a second payment).
 */
interface SoftPosDriver
{
    public function key(): string;

    /** @return array<string, mixed> */
    public function create(TerminalAccount $account, string $clientUuid, int $amountMinor, string $currency): array;

    /** @return array<string, mixed> */
    public function fetch(TerminalAccount $account, string $reference): array;

    /** @return array<string, mixed> */
    public function refund(TerminalAccount $account, string $reference, string $clientUuid, int $amountMinor, string $currency): array;

    /** @return array<string, mixed> */
    public function void(TerminalAccount $account, string $reference, string $clientUuid): array;

    /** Is this callback really from the provider, for THIS store's account (its own secret)? */
    public function verifyCallback(TerminalAccount $account, Request $request): bool;

    /** The provider reference a verified callback is about. */
    public function callbackReference(Request $request): ?string;
}
