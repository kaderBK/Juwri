<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting\Gl;

use App\Services\FeatureRegistry;
use Juwri\Modules\Accounting\Models\GlJournal;

/**
 * SYSTEMS-RUN SUP-P2: post inventory movements into the GL. Receipt → DR
 * inventory (1300) / CR accounts payable (2100). No-op unless accounting_gl on;
 * idempotent per (PurchaseOrder, rule_key + received value). Reporting only.
 */
class CogsGlPoster
{
    public static function postReceipt(int $storeId, int $poId, float $value): void
    {
        if (! FeatureRegistry::isEnabled('accounting_gl') || $value < 0.001) {
            return;
        }
        // Rule key includes a monotonically increasing suffix so successive
        // partial receipts each post their own delta.
        $ruleKey = 'po_receipt:'.GlJournal::where('store_id', $storeId)
            ->where('source_type', 'PurchaseOrder')->where('source_id', $poId)->count();

        JournalEngine::post($storeId, [
            ['account' => JournalEngine::account($storeId, '1300'), 'debit' => $value, 'credit' => 0, 'memo' => 'PO #'.$poId.' received'],
            ['account' => JournalEngine::account($storeId, '2100'), 'debit' => 0, 'credit' => $value, 'memo' => 'Supplier payable'],
        ], 'PurchaseOrder', $poId, $ruleKey, 'Inventory receipt', now());
    }
}
