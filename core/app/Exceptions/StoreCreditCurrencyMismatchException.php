<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by CustomerWalletService::refundToCredit() when the refund's currency
 * (the order's own currency) differs from the customer's store-credit account
 * currency. Store credit is never issued 1:1 across currencies (no FX), the same
 * rule applyToOrder() enforces when credit is spent.
 */
class StoreCreditCurrencyMismatchException extends RuntimeException {}
