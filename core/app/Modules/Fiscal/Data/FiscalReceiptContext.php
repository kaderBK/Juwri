<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Data;

use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;

/**
 * J25-8: the ONLY input a fiscal document or receipt block is built from. An immutable snapshot of the
 * seller identity and the already computed totals of one sale. Fiscal never reads an order: the module that owns
 * the sale (Ordering: its OrderFiscalContext service) fills it, through
 * {@see FiscalReceipts} for the store identity.
 *
 * All monetary values are already computed upstream — this object never recomputes tax, it only carries the
 * numbers. `orderId` is a plain reference id stored on the fiscal document (`fiscal_documents.order_id`).
 *
 * (Was TAX-RUN F0's Services\FiscalReceiptContext; that name stays as a deprecated subclass.)
 */
class FiscalReceiptContext
{
    /**
     * @param  array<string, mixed>  $extra  optional device/document extras filled by live adapters (tlv, istd_qr, lookup_url, hash, signature, uuid)
     * @param  list<FiscalLine>  $lines  P6-99: the sale's lines as charged (items, fees, tips); empty = one aggregate line from the totals
     * @param  float  $discount  P6-99: order-level discount (major units) — a document-level allowance
     * @param  bool  $discountIncludesTax  P6-99: true = the discount was taken off the tax-inclusive amount
     * @param  array<string,string>  $sellerAddress  P6-176: the seller's national address (KSA: ZatcaParties::ADDRESS keys)
     */
    public function __construct(
        public readonly string $sellerName,
        public readonly ?string $vatNumber,
        public readonly ?string $crNumber,
        public readonly float $total,
        public readonly float $taxAmount,
        public readonly \DateTimeInterface $issuedAt,
        public readonly string $currency = 'USD',
        public readonly array $extra = [],
        public readonly ?int $orderId = null,
        public readonly array $lines = [],
        public readonly float $discount = 0.0,
        public readonly bool $discountIncludesTax = false,
        public readonly array $sellerAddress = [],
    ) {}

    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }
}
