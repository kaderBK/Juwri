<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Accounting;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * P1-13 extension point: postings into the general ledger (Accounting module). Lower-layer modules (Ordering, Hr,
 * Inventory) and App code report business events here; the Accounting module binds it (Community, P1-13 13c-R).
 */
interface GeneralLedger
{
    /** Inventory receipt: DR inventory / CR accounts payable for a purchase order's received value. */
    public function postInventoryReceipt(int $storeId, int $purchaseOrderId, float $value): void;

    /** A settled sale (idempotent per order). */
    public function postOrderPaid(Model $order): void;

    /** A refund of an order to store credit (idempotent per $ref). */
    public function postRefund(Model $order, float $amount, ?string $ref = null): void;

    /**
     * A balanced journal of chart-of-accounts codes (e.g. payroll), idempotent per (store, source, rule key).
     *
     * @param  list<array{code: string, debit?: float, credit?: float, memo?: string}>  $lines
     */
    public function postJournal(int $storeId, array $lines, string $sourceType, int $sourceId, string $ruleKey, string $memo, DateTimeInterface $date): void;

    /** Export a paid order to the store's connected accounting provider (QuickBooks/Xero), queued. */
    public function exportOrderInvoice(int $orderId): void;
}
