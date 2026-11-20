<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Exceptions;

use RuntimeException;

/**
 * SEC-1120 delta NIT 3: thrown by SellerPayoutService::requestWithdrawal() when the amount is more than the seller can
 * withdraw now (only marketplace earnings past the hold). A distinct type so the wallet page shows "more than you can
 * withdraw" for exactly this, and never for an unrelated failure (a database or lock error).
 */
class WithdrawalRefusedException extends RuntimeException {}
