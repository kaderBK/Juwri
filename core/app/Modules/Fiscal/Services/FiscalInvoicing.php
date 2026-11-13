<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Core\Features\PlatformFeatures;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Contracts\FiscalInvoices;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Issuing\CountryIssuer;
use Juwri\Modules\Fiscal\Services\Issuing\EtaIssuer;
use Juwri\Modules\Fiscal\Services\Issuing\JofotaraIssuer;
use Juwri\Modules\Fiscal\Services\Issuing\ZatcaIssuer;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaParties;

/**
 * P4-01: issues the e-invoice of a paid sale and the credit note of a refund. Before, nothing called the document
 * services — an onboarded store reported no invoice at all, and a refund left no credit note.
 *
 * One {@see CountryIssuer} per country with an authority: SA (ZATCA), JO (JoFotara), EG (ETA). It acts only when the
 * store e-invoices: its fiscal_country, the `fiscalization` and `fiscal_<cc>` switches, the country's adapter enabled
 * (config fiscal.adapters — the same adapter then submits the document), and the issuer's own readiness (an
 * onboarded / registered device, the store's own credentials). Idempotent on the source reference (one document per
 * store + reference, unique key on fiscal_documents).
 */
class FiscalInvoicing implements FiscalInvoices
{
    /** @var array<string, class-string<CountryIssuer>> */
    public const ISSUERS = ['SA' => ZatcaIssuer::class, 'JO' => JofotaraIssuer::class, 'EG' => EtaIssuer::class];

    public function issuesFor(int $storeId): bool
    {
        return isset(self::ISSUERS[strtoupper((string) store_options($storeId, 'fiscal_country'))]);
    }

    public function invoice(Store $store, FiscalReceiptContext $sale, string $sourceRef, ?int $branchId = null): ?FiscalDocument
    {
        [$issuer, $device] = $this->issuer($store, $branchId);
        if (! $issuer) {
            return null;
        }
        if ($existing = $this->existing($store, $sourceRef)) {
            return $existing;
        }
        // P6-176: a sale that got its standard (B2B) tax invoice before the paid hook ran keeps that one
        if ($sale->orderId !== null && ($standard = $this->saleDocument($store, $sale->orderId)) && $standard->type === 'standard') {
            return $standard;
        }

        return $issuer->invoice($store, $sale, $sourceRef, $device, $branchId);
    }

    public function standardInvoice(Store $store, FiscalReceiptContext $sale, array $buyer, ?int $branchId = null): ?FiscalDocument
    {
        [$issuer, $device] = $this->issuer($store, $branchId);
        if (! $issuer) {
            return null;
        }
        if (! $issuer instanceof ZatcaIssuer) {
            throw new \DomainException(___('Tax invoices for business buyers are available for stores in Saudi Arabia only.'));
        }
        if ($sale->orderId === null) {
            throw new \DomainException(___('Save the order before issuing a tax invoice.'));
        }
        $sourceRef = 'order:'.$sale->orderId.':standard';
        if ($existing = $this->existing($store, $sourceRef)) {
            return $existing;
        }
        $buyer = ZatcaParties::buyer($buyer);
        // checked here too (the document service checks again), so a simplified invoice is never credited for nothing
        ZatcaParties::assertStandard($sale->sellerName, $sale->vatNumber, $sale->sellerAddress, $buyer);

        $original = $this->saleDocument($store, $sale->orderId);
        if ($original && $original->type === 'standard') {
            return $original;
        }
        if ($original) {
            // the sale's simplified (B2C) invoice is cancelled by a full credit note, then the B2B invoice replaces it;
            // never when part of the sale was already credited (the note would not match what is left)
            $replaceRef = 'order:'.$sale->orderId.':b2b-credit'; // our own note (a retry after a failed B2B issue) does not count
            $credited = FiscalDocument::where('store_id', $store->id)->where('order_id', $sale->orderId)
                ->whereIn('type', ['credit_note', 'debit_note'])
                ->where(fn ($q) => $q->whereNull('source_ref')->orWhere('source_ref', '!=', $replaceRef))->exists();
            if ($credited) {
                throw new \DomainException(___('This order already has a credit note, so its simplified invoice can no longer be replaced by a tax invoice.'));
            }
            // a retry (the B2B issue failed after the note) never credits the simplified invoice twice
            if (! $this->existing($store, $replaceRef)) {
                $issuer->creditNote($store, $sale, $replaceRef, 'Replaced by a tax invoice for a business buyer',
                    $original, $device, $branchId);
            }
        }

        return $issuer->standardInvoice($store, $sale, $sourceRef, $buyer, $device, $branchId);
    }

    public function creditNote(Store $store, FiscalReceiptContext $refund, string $sourceRef, string $reason, ?int $branchId = null): ?FiscalDocument
    {
        if ($refund->orderId === null || $refund->total <= 0) {
            return null;
        }
        [$issuer, $device] = $this->issuer($store, $branchId);
        if (! $issuer) {
            return null;
        }
        if ($existing = $this->existing($store, $sourceRef)) {
            return $existing;
        }
        $original = $this->saleDocument($store, $refund->orderId);
        if (! $original) {
            return null;
        }

        return $issuer->creditNote($store, $refund, $sourceRef, $reason, $original, $device, $branchId);
    }

    public function hasInvoice(Store $store, int $orderId): bool
    {
        return $this->saleDocument($store, $orderId) !== null;
    }

    /** The sale's e-invoice: the latest non-superseded one (a corrected invoice replaces the rejected one). */
    private function saleDocument(Store $store, int $orderId): ?FiscalDocument
    {
        return FiscalDocument::where('store_id', $store->id)->where('order_id', $orderId)
            ->whereIn('type', ['simplified', 'standard'])->where('status', '!=', 'superseded')
            ->latest('id')->first();
    }

    /** @return array{0: CountryIssuer|null, 1: array<string, mixed>} */
    private function issuer(Store $store, ?int $branchId): array
    {
        $cc = strtoupper((string) store_options($store->id, 'fiscal_country'));
        if (! isset(self::ISSUERS[$cc]) || ! PlatformFeatures::active('fiscalization')
            || ! PlatformFeatures::active('fiscal_'.strtolower($cc)) || empty(config('fiscal.adapters.'.$cc.'.enabled'))) {
            return [null, []];
        }
        $issuer = app(self::ISSUERS[$cc]);
        $device = $issuer->device($store, $branchId);

        return $device === null ? [null, []] : [$issuer, $device];
    }

    private function existing(Store $store, string $sourceRef): ?FiscalDocument
    {
        return FiscalDocument::where('store_id', $store->id)->where('source_ref', $sourceRef)->first();
    }
}
