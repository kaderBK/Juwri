<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN Z-P4: turns a sale into a chained, stamped ZATCA fiscal document and
 * queues it for reporting/clearance. J25-8: built ONLY from the FiscalReceiptContext
 * the selling module filled (Ordering: OrderFiscalContext) — never reads an order;
 * takes its totals as given (never recomputes),
 * builds UBL, computes the invoice hash + PIH chain, and — once the device is in
 * production — cryptographically stamps the simplified invoice and stores the
 * 9-tag TLV so the receipt QR auto-upgrades from 5 to 9 tags.
 */
class ZatcaDocumentService
{
    public function __construct(private ZatcaCrypto $crypto) {}

    /**
     * @param  string|null  $sourceRef  P4-01: what the document is for (order:<id> / refund:<id>) — one per store
     * @param  string|null  $referencedNumber  credit note: the number of the invoice it credits
     * @param  array<string,string>|null  $buyer  P6-176: the buyer of a standard (B2B) document (ZatcaParties::buyer)
     *
     * @throws ZatcaPartiesMissing a standard document without full seller / buyer details (nothing is issued)
     */
    public function issue(Store $store, FiscalReceiptContext $ctx, string $deviceKey, string $type = 'simplified',
        ?string $sourceRef = null, ?string $referencedNumber = null, ?string $reason = null, bool $simplifiedNote = true,
        ?int $branchId = null, ?array $buyer = null): FiscalDocument
    {
        // P6-176: a standard (B2B) document — a standard invoice or a note of one — needs both full parties. Checked
        // BEFORE the chain hands out a number, so a refused document leaves no gap and no invalid XML.
        $standard = $type === 'standard' || (in_array($type, ['credit_note', 'debit_note'], true) && ! $simplifiedNote);
        if ($standard) {
            ZatcaParties::assertStandard($ctx->sellerName, $ctx->vatNumber, $ctx->sellerAddress, $buyer);
        }

        $device = FiscalDevice::where('store_id', $store->id)->where('device_key', $deviceKey)->first();
        $isProduction = $device && $device->isProduction();
        // RB-11: a production device must be able to sign. Never emit an unsigned document under a production device
        // (before: it silently fell back to the unsigned 5-tag form and ZATCA rejected it later).
        if ($isProduction && (! FiscalCertificate::resolve($store->id, 'private_key', $deviceKey) || ! FiscalCertificate::resolve($store->id, 'pcsid', $deviceKey))) {
            throw new \RuntimeException("ZATCA device {$deviceKey} is in production but has no active key and production certificate; run fiscal:zatca-check.");
        }

        // P6-99: per-line invoice (items, fees, discount allowances, TaxSubtotal per category). Tips/donations (O) are
        // not a supply and ZATCA forbids mixing O with other categories, so they stay off a taxed invoice.
        $fiscal = FiscalInvoiceMath::compute($ctx, 2, dropOutOfScope: true);
        $payable = FiscalInvoiceMath::major($fiscal['payable'], 2);
        $vat = FiscalInvoiceMath::major($fiscal['tax_total'], 2);

        // Draft on the chain (allocates ICV/PIH/number).
        $doc = FiscalChain::issue($store->id, $deviceKey, [
            'country' => 'SA', 'type' => $type, 'order_id' => $ctx->orderId,
            'total' => $payable, 'tax_total' => $vat, 'currency' => 'SAR',
        ] + array_filter(['source_ref' => $sourceRef, 'branch_id' => $branchId], fn ($v) => $v !== null));
        if (! $doc->wasRecentlyCreated) {
            return $doc; // P4-01: this source was already issued (a replayed or racing hook) — never a second document
        }

        $inv = new UblInvoice(
            type: $type, number: $doc->number, uuid: $doc->uuid, issuedAt: $ctx->issuedAt,
            currency: 'SAR', sellerName: $ctx->sellerName, sellerVat: (string) $ctx->vatNumber,
            sellerCr: $ctx->crNumber, icv: (int) $doc->counter, previousHash: $doc->previous_hash,
            lines: [],
            total: $ctx->total, taxTotal: $vat,
            referencedNumber: $referencedNumber, simplifiedNote: $simplifiedNote, reason: $reason,
            fiscal: $fiscal,
            sellerAddress: $ctx->sellerAddress, buyer: $standard ? $buyer : null,
        );

        $xml = new UblBuilder()->build($inv);

        $core = [
            'seller' => $ctx->sellerName, 'vat' => (string) $ctx->vatNumber,
            'timestamp' => KsaTime::qrTimestamp($ctx->issuedAt), // = IssueDate + IssueTime (AST)
            'total' => FiscalInvoiceMath::fmt($fiscal['tax_inclusive'], 2), // QR tag 4 = TaxInclusiveAmount
            'vat_amount' => FiscalInvoiceMath::fmt($fiscal['tax_total'], 2),
        ];

        $tlv = TlvQr::fiveTag($core);
        $hash = InvoiceHash::base64($xml);

        if ($isProduction) {
            $pcsid = FiscalCertificate::resolve($store->id, 'private_key', $deviceKey);
            if ($pcsid) {
                $pub = $pcsid->meta['public'] ?? '';
                $cert = FiscalCertificate::resolve($store->id, 'pcsid', $deviceKey);
                // P6-87: full XAdES stamp; simplified documents (and notes of them) get the 9-tag QR embedded in the XML
                // and stored for the receipt, standard ones are cleared and ZATCA adds the QR.
                $simplified = $inv->typeSubCode() === '0200000';
                $stamp = new XadesStamp($this->crypto)->stamp($xml, $pcsid->pem, $pub, $cert ? ZatcaCertificate::body($cert->pem) : null,
                    $simplified ? $core : null, $ctx->issuedAt);
                $xml = $stamp['xml'];
                $hash = $stamp['hash'];
                $tlv = $stamp['tlv'] ?? $tlv;
            }
        }

        FiscalChain::seal($doc, $hash);
        $doc->update([
            'payload' => $xml,
            'authority_response' => ['tlv' => $tlv, 'tags' => count(TlvQr::decode($tlv))], // P6-87: 9 only when the QR really has 9 tags
        ] + ($standard ? ['buyer' => $buyer] : []));

        $doc->transitionTo('queued', ['queued_at' => now()]);
        SubmitFiscalDocumentJob::dispatch($doc->id);

        return $doc->fresh();
    }
}
