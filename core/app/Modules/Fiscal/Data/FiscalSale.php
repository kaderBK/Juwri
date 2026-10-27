<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Data;

use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;

/**
 * J25-8: the sale numbers the selling module hands to {@see FiscalReceipts}
 * (already computed — Fiscal never recomputes them). `orderId` null = an unsaved sale (no document lookup).
 */
final class FiscalSale
{
    /**
     * @param  list<FiscalLine>  $lines  P6-99: see {@see FiscalReceiptContext::$lines}
     */
    public function __construct(
        public readonly float $total,
        public readonly float $taxAmount,
        public readonly \DateTimeInterface $issuedAt,
        public readonly ?int $orderId = null,
        public readonly array $lines = [],
        public readonly float $discount = 0.0,
        public readonly bool $discountIncludesTax = false,
    ) {}
}
