<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Exceptions;

use RuntimeException;

/**
 * MONETIZATION B10: thrown by CustomerWalletService::credit() when a store is
 * not entitled to ISSUE customer store credit (the `customer_wallet` gate).
 *
 * A distinct type so callers can catch exactly this — "the store cannot create
 * new credit" — and fall back gracefully (e.g. a gateway refund instead of
 * refund-to-credit), without swallowing unrelated RuntimeExceptions. Redemption
 * of existing balances never throws it.
 */
class StoreCreditDisabledException extends RuntimeException
{
}
