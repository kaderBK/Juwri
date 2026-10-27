<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Issuing;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Eta\EtaDocumentService;

/**
 * P4-01: Egypt ETA e-Receipt — a registered POS device (serial, branch code; the sale branch's device first) and the
 * store's OWN ETA credentials (taxpayer RIN). A refund is a return receipt referencing the sale receipt's uuid.
 */
final class EtaIssuer implements CountryIssuer
{
    public function __construct(private EtaDocumentService $eta) {}

    public function device(Store $store, ?int $branchId): ?array
    {
        $secrets = Credential::where('store_id', $store->id)->where('feature', 'eta')->where('active', true)->first()?->secrets;
        if (empty($secrets['rin'])) {
            return null;
        }
        $device = Devices::pick(FiscalDevice::where('store_id', $store->id)->where('country', 'EG')
            ->where('status', 'registered')->orderBy('id')->get(), $branchId);

        return $device ? ['key' => (string) $device->device_key, 'rin' => (string) $secrets['rin'],
            'branchCode' => (string) (($device->meta ?? [])['branchCode'] ?? '0')] : null;
    }

    public function invoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, array $device, ?int $branchId): FiscalDocument
    {
        return $this->eta->issue($store, $sale, (string) $device['key'], self::receiptDevice($device), false, null, $sourceRef, $branchId);
    }

    public function creditNote(Store $store, FiscalReceiptContext $refund, string $sourceRef, string $reason, FiscalDocument $original, array $device, ?int $branchId): FiscalDocument
    {
        return $this->eta->issue($store, $refund, (string) $device['key'], self::receiptDevice($device), true, (string) $original->uuid, $sourceRef, $branchId);
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    private static function receiptDevice(array $device): array
    {
        return ['rin' => $device['rin'], 'branchCode' => $device['branchCode']];
    }
}
