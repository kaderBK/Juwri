<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;

/**
 * TAX-RUN E-P2: turns a sale (J25-8: its FiscalReceiptContext, filled by Ordering —
 * never an order) into a chained, signed ETA e-Receipt and queues
 * it (report-after-print, 24h SLA). previousUUID chains per POS device via the
 * FiscalChain; the receipt is built with the chain's prior uuid so the ETA chain
 * matches our document chain.
 */
class EtaDocumentService
{
    public function __construct(
        private ReceiptBuilder $builder = new ReceiptBuilder,
        private EtaSigner $signer = new EtaSigner,
    ) {}

    /** @param  array<string, mixed>  $device */
    public function issue(Store $store, FiscalReceiptContext $ctx, string $deviceSerial, array $device, bool $isReturn = false, ?string $referenceOldUUID = null,
        ?string $sourceRef = null, ?int $branchId = null): FiscalDocument
    {

        // Draft on the chain to obtain the previous document's uuid for chaining.
        $doc = FiscalChain::issue($store->id, $deviceSerial, [
            'country' => 'EG', 'type' => $isReturn ? 'credit_note' : 'simplified', 'order_id' => $ctx->orderId,
            'total' => $ctx->total, 'tax_total' => ReceiptBuilder::vat($ctx), 'currency' => 'EGP', // P6-99: the document's VAT
        ] + array_filter(['source_ref' => $sourceRef, 'branch_id' => $branchId], fn ($v) => $v !== null));
        if (! $doc->wasRecentlyCreated) {
            return $doc; // P4-01: already issued for this source
        }

        $prevUuid = FiscalDocument::where('store_id', $store->id)
            ->where('device_key', $deviceSerial)
            ->where('id', '<', $doc->id)
            ->whereNotNull('uuid')
            ->latest('id')
            ->value('uuid') ?? '';

        $built = $this->builder->build($ctx, array_merge($device, [
            'deviceSerial' => $deviceSerial,
            'receiptNumber' => $doc->number,
            'previousUUID' => $prevUuid,
        ]), $isReturn, $referenceOldUUID);

        // Overwrite the chain-generated uuid with the ETA canonical uuid so both
        // chains agree.
        $signature = $this->signer->sign($store->id, $deviceSerial, $built['canonical']);
        $receipt = $built['receipt'];
        if ($signature) {
            $receipt['signatures'] = [['signatureType' => 'I', 'value' => $signature]];
        }

        $doc->update([
            'uuid' => $built['uuid'],
            'previous_hash' => $prevUuid ?: null,
            'payload' => json_encode($receipt),
            'authority_response' => ['receipt' => $receipt],
        ]);
        FiscalChain::seal($doc, $built['uuid']);
        $doc->transitionTo('queued', ['queued_at' => now()]);

        SubmitFiscalDocumentJob::dispatch($doc->id);

        return $doc->fresh();
    }
}
