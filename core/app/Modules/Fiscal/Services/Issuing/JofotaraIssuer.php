<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Issuing;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraDocumentService;

/**
 * P4-01: Jordan JoFotara — the store's OWN JoFotara credentials (Fiscalization console; a platform default credential
 * is never used to issue in a store's name). One chain per store ('default'); a credit note (381) references the
 * invoice number it credits.
 */
final class JofotaraIssuer implements CountryIssuer
{
    public function __construct(private JofotaraDocumentService $jofotara) {}

    public function device(Store $store, ?int $branchId): ?array
    {
        $secrets = Credential::where('store_id', $store->id)->where('feature', 'jofotara')->where('active', true)->first()?->secrets;

        return ! empty($secrets['client_id']) && ! empty($secrets['secret']) ? ['key' => 'default'] : null;
    }

    public function invoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, array $device, ?int $branchId): FiscalDocument
    {
        return $this->jofotara->issue($store, $sale, (string) $device['key'], 'simplified', sourceRef: $sourceRef, branchId: $branchId);
    }

    public function creditNote(Store $store, FiscalReceiptContext $refund, string $sourceRef, string $reason, FiscalDocument $original, array $device, ?int $branchId): FiscalDocument
    {
        return $this->jofotara->issue($store, $refund, (string) $device['key'], 'credit_note', (string) $original->number, $sourceRef, $branchId);
    }
}
