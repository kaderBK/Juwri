<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Issuing;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDocumentService;

/** P4-01: KSA ZATCA — an onboarded device (compliance or production), the sale branch's device first. */
final class ZatcaIssuer implements CountryIssuer
{
    public function __construct(private ZatcaDocumentService $zatca) {}

    public function device(Store $store, ?int $branchId): ?array
    {
        $device = Devices::pick(FiscalDevice::where('store_id', $store->id)->where('country', 'SA')
            ->whereIn('status', ['compliance', 'production'])->orderBy('id')->get(), $branchId);

        return $device ? ['key' => (string) $device->device_key] : null;
    }

    public function invoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, array $device, ?int $branchId): FiscalDocument
    {
        return $this->zatca->issue($store, $sale, (string) $device['key'], 'simplified', sourceRef: $sourceRef, branchId: $branchId);
    }

    public function creditNote(Store $store, FiscalReceiptContext $refund, string $sourceRef, string $reason, FiscalDocument $original, array $device, ?int $branchId): FiscalDocument
    {
        // P6-176: the note of a standard (B2B) invoice is standard too and names the same buyer
        return $this->zatca->issue($store, $refund, (string) $device['key'], 'credit_note',
            sourceRef: $sourceRef, referencedNumber: (string) $original->number, reason: $reason,
            simplifiedNote: $original->type === 'simplified', branchId: $branchId,
            buyer: $original->type === 'simplified' ? null : (is_array($original->buyer) ? $original->buyer : null));
    }

    /**
     * P6-176: the standard (B2B) tax invoice of a sale, for the buyer the merchant entered (cleared by ZATCA).
     *
     * @param  array<string,string>  $buyer  ZatcaParties::buyer()
     * @param  array<string,mixed>  $device
     */
    public function standardInvoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, array $buyer, array $device, ?int $branchId): FiscalDocument
    {
        return $this->zatca->issue($store, $sale, (string) $device['key'], 'standard', sourceRef: $sourceRef, branchId: $branchId, buyer: $buyer);
    }
}
