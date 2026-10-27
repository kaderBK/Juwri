<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDocument;

/**
 * P4-01: the e-invoices of a sale, issued by the selling module at its money choke points (Ordering: an order becoming
 * paid, a refund recorded). Fiscal decides whether the store e-invoices (its country, the fiscal switches, an onboarded
 * device) and builds the document only from the {@see FiscalReceiptContext} it is given. Each call is idempotent on
 * $sourceRef (one document per store and reference). Null = the store does not e-invoice (nothing issued).
 */
interface FiscalInvoices
{
    /** Whether the store e-invoices at all (cheap: its fiscal country has an issuing service) — skip the rest when not. */
    public function issuesFor(int $storeId): bool;

    /** The e-invoice of a paid sale (ZATCA: simplified tax invoice, reported). */
    public function invoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, ?int $branchId = null): ?FiscalDocument;

    /** Whether the sale already has its e-invoice (a credit note can reference it). */
    public function hasInvoice(Store $store, int $orderId): bool;

    /**
     * The credit note of a refund of that sale ($refund: the refunded amount and its tax), referencing the sale's
     * e-invoice. Null when the sale has no e-invoice to credit (the caller issues the sale's invoice first when it is
     * still owed — Ordering does, so a refund made while the invoice is still in the outbox still gets its note).
     */
    public function creditNote(Store $store, FiscalReceiptContext $refund, string $sourceRef, string $reason, ?int $branchId = null): ?FiscalDocument;

    /**
     * P6-176: the standard (B2B) tax invoice of a paid sale for a business buyer (KSA / ZATCA only). $buyer: name, vat,
     * street, building_number, additional_number, district, city, postal_code, country. When the sale already has its
     * simplified invoice, that one is cancelled by a full credit note first. Idempotent per sale.
     * Null = the store does not e-invoice.
     *
     * @param  array<string,mixed>  $buyer
     *
     * @throws \DomainException with a message for the merchant when the invoice cannot be issued (missing or invalid
     *                           seller / buyer details, another country, the sale already credited)
     */
    public function standardInvoice(Store $store, FiscalReceiptContext $sale, array $buyer, ?int $branchId = null): ?FiscalDocument;
}
