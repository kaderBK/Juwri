<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Issuing;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDocument;

/**
 * P4-01: how one country issues the e-invoice of a paid sale and the credit note of a refund, behind
 * {@see \Juwri\Modules\Fiscal\Services\FiscalInvoicing}. Each issuer builds its document with the country's document
 * service and queues it for its FiscalAdapter (SubmitFiscalDocumentJob → FiscalDriverManager), exactly as ZATCA.
 */
interface CountryIssuer
{
    /**
     * The device / credentials this store issues with, or null when the store does not e-invoice in this country
     * (not onboarded / registered / no credentials of its own).
     *
     * @return array<string, mixed>|null
     */
    public function device(Store $store, ?int $branchId): ?array;

    /** @param  array<string, mixed>  $device */
    public function invoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, array $device, ?int $branchId): FiscalDocument;

    /** @param  array<string, mixed>  $device */
    public function creditNote(Store $store, FiscalReceiptContext $refund, string $sourceRef, string $reason, FiscalDocument $original, array $device, ?int $branchId): FiscalDocument;
}
