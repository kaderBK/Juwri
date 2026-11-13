<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\WalletPurchaseReceipt;

/**
 * P6-252: durable receipt + receipt e-mail for a purchase paid from the platform wallet (public surface for modules
 * above Payments, e.g. AppStore). Implemented by {@see WalletPurchaseReceipt}. Idempotent per transaction.
 */
interface PurchaseReceipts
{
    public function issueFor(Transaction $transaction): BillingReceipt;
}
