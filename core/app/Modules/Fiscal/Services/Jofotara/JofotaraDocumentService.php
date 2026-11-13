<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN J-P2: turns a sale into a JoFotara document and clears it. J25-8: built
 * ONLY from the FiscalReceiptContext (Ordering fills it) — never reads an order. Because
 * JoFotara is clear-before-print, submission happens synchronously at payment;
 * the receipt is provisional until the ISTD QR is returned. On an outage the
 * job is queued and retried (reprint-when-cleared flow).
 */
class JofotaraDocumentService
{
    /**
     * @param  string|null  $reference  P4-01: a credit note (type credit_note) references the invoice number it credits
     * @param  string|null  $sourceRef  P4-01: what the document is for (order:<id> / refund:<id>) — one per store
     */
    public function issue(Store $store, FiscalReceiptContext $ctx, ?string $deviceKey = null, string $type = 'simplified',
        ?string $reference = null, ?string $sourceRef = null, ?int $branchId = null): FiscalDocument
    {
        $creds = Credential::for('jofotara', $store->id) ?? [];

        $doc = FiscalChain::issue($store->id, (string) ($deviceKey ?? 'default'), [
            'country' => 'JO', 'type' => $type, 'order_id' => $ctx->orderId,
            'total' => $ctx->total, 'tax_total' => FiscalInvoiceMath::major(FiscalInvoiceMath::compute($ctx, 3)['tax_total'], 3), 'currency' => 'JOD', // P6-99: the document's VAT
        ] + array_filter(['source_ref' => $sourceRef, 'branch_id' => $branchId], fn ($v) => $v !== null));
        if (! $doc->wasRecentlyCreated) {
            return $doc; // P4-01: already issued for this source
        }

        $built = new JofotaraBuilder()->build($ctx, [
            'type' => $type === 'credit_note' ? JofotaraBuilder::CREDIT_NOTE : JofotaraBuilder::INVOICE_CASH,
            'reference' => $reference,
            'number' => $doc->number,
            'uuid' => $doc->uuid,
            'activity_number' => $creds['activity_number'] ?? '',
        ]);

        FiscalChain::seal($doc, base64_encode(hash('sha256', $built['ubl'], true)));
        $doc->update([
            'payload' => $built['ubl'],
            'authority_response' => ['envelope' => $built['json']],
        ]);
        $doc->transitionTo('queued', ['queued_at' => now()]);

        SubmitFiscalDocumentJob::dispatch($doc->id);

        return $doc->fresh();
    }
}
