<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Services\LedgerService;

/**
 * P1-08 L1 public surface: the double-entry money ledger for modules above Payments (Billing, Customers, Ordering).
 * Same semantics as {@see LedgerService} (bound by PaymentsServiceProvider).
 */
interface Ledger
{
    public function defaultCurrency(): string;

    public function accountFor(string $ownerType, int $ownerId, string $kind, ?string $currency = null): LedgerAccount;

    /** The account's balance (LedgerService::balanceFor). */
    public function accountBalance(LedgerAccount $account): float;

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return string the posting's transaction id
     */
    public function post(array $legs, ?string $transactionId = null, string $reason = ''): string;
}
