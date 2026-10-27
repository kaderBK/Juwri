<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Edition;

use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use App\Models\Order;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Modules\Accounting\Services\Accounting\Gl\CogsGlPoster;

/** P1-13: the Accounting module's {@see GeneralLedger} — the same GL posters Community modules used to call directly. */
final class AccountingLedger implements GeneralLedger
{
    public function postInventoryReceipt(int $storeId, int $purchaseOrderId, float $value): void
    {
        CogsGlPoster::postReceipt($storeId, $purchaseOrderId, $value);
    }

    public function postOrderPaid(Model $order): void
    {
        if ($order instanceof Order) {
            OrderJournalPoster::postOrderPaid($order);
        }
    }

    public function postRefund(Model $order, float $amount, ?string $ref = null): void
    {
        if ($order instanceof Order) {
            OrderJournalPoster::postRefund($order, $amount, $ref);
        }
    }

    public function postJournal(int $storeId, array $lines, string $sourceType, int $sourceId, string $ruleKey, string $memo, DateTimeInterface $date): void
    {
        $resolved = [];
        foreach ($lines as $line) {
            $code = $line['code'];
            unset($line['code']);
            $resolved[] = ['account' => JournalEngine::account($storeId, $code)] + $line;
        }
        JournalEngine::post($storeId, $resolved, $sourceType, $sourceId, $ruleKey, $memo, $date);
    }

    public function exportOrderInvoice(int $orderId): void
    {
        SyncInvoiceJob::dispatch($orderId);
    }
}
