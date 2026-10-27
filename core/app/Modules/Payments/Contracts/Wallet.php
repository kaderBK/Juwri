<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;

/**
 * P1-08 L1 public surface: the owner's prepaid platform wallet, for modules above Payments (Billing). Same
 * semantics as {@see WalletService} (the implementation, bound by PaymentsServiceProvider).
 */
interface Wallet
{
    /** The platform wallet currency. */
    public function currency(): string;

    public function balanceFor(User $user, ?string $currency = null): float;

    /** @return mixed the user's latest wallet entries (newest first) */
    public function ledgerFor(User $user, int $limit = 20): mixed;

    public function debit(User $user, float $amount, string $reason = '', string $ref = '', ?string $currency = null): float;

    public function credit(User $user, float $amount, string $reason = '', string $ref = '', ?int $grantedBy = null, ?string $currency = null): float;

    public function creditBack(User $user, float $amount, string $currency, string $reason = '', string $ref = ''): float;
}
