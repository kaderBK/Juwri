<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Data\FiscalSale;

/**
 * J25-8: builds the {@see FiscalReceiptContext} of a sale — the store's fiscal identity (legal name, VAT/CR
 * numbers, profile currency) plus the authority QR bits of an already issued document for that order. Bound by
 * the Fiscal provider; called by the module that owns the sale (Ordering), so Fiscal never reads an order.
 */
interface FiscalReceipts
{
    /** @param  array<string, mixed>  $extra  adapter-supplied QR/signature bits (skips the document lookup) */
    public function context(Store $store, FiscalSale $sale, array $extra = []): FiscalReceiptContext;

    /**
     * P6-172: what a printable receipt of this context must show under the store's active fiscal profile (none when
     * fiscalization or the country's profile is off): the authority QR payload, the document title, and whether the
     * VAT and CR numbers are printed.
     *
     * @return array{qr: ?string, title: ?string, showVat: bool, showCr: bool}
     */
    public function receiptHeader(Store $store, FiscalReceiptContext $context): array;
}
