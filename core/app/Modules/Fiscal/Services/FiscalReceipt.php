<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use App\Services\FeatureRegistry;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Data\FiscalSale;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaParties;

/**
 * TAX-RUN F0 / J25-8: builds the {@see FiscalReceiptContext} of a sale so receipt views and the country document
 * services render the correct fiscal block + QR. Reads the store's fiscal identity from store_options and takes the
 * totals from the {@see FiscalSale} the selling module computed — it NEVER reads an order and never recomputes tax
 * (Ordering: its OrderFiscalContext service).
 *
 * A live adapter (ZATCA/ETA/JoFotara) can supply `$extra` (tlv, istd_qr, lookup_url, hash, signature, uuid) once a
 * device is onboarded; without it the profile falls back to its self-generated / no-QR behaviour.
 */
class FiscalReceipt implements FiscalReceipts
{
    /**
     * @param  array<string,mixed>  $extra  adapter-supplied QR/signature bits
     */
    public function context(Store $store, FiscalSale $sale, array $extra = []): FiscalReceiptContext
    {
        return self::forSale($store, $sale, $extra);
    }

    /**
     * @param  array<string,mixed>  $extra  adapter-supplied QR/signature bits
     */
    public static function forSale(Store $store, FiscalSale $sale, array $extra = []): FiscalReceiptContext
    {
        $opts = store_options($store->id);
        $profile = FiscalRegistry::for($store);

        $legalName = (string) ($opts->fiscal_legal_name ?? '') ?: (string) $store->title;
        $currency = $profile?->currency()
            ?? (string) ($opts->currency_code ?? 'USD');

        // If a fiscal document was already issued for this order, reuse its
        // authority-returned QR bits (ZATCA 9-tag TLV, JoFotara ISTD QR, ETA
        // lookup URL) so the receipt renders the compliant, post-submission QR.
        if (empty($extra) && $sale->orderId !== null) {
            $extra = self::documentExtra($store->id, $sale->orderId);
        }

        return new FiscalReceiptContext(
            sellerName: $legalName,
            vatNumber: (string) ($opts->fiscal_vat_number ?? '') ?: null,
            crNumber: (string) ($opts->fiscal_cr_number ?? '') ?: null,
            total: $sale->total,
            taxAmount: $sale->taxAmount,
            issuedAt: $sale->issuedAt,
            currency: $currency,
            extra: $extra,
            orderId: $sale->orderId,
            lines: $sale->lines,
            discount: $sale->discount,
            discountIncludesTax: $sale->discountIncludesTax,
            // P6-176: the KSA national address for the ZATCA seller party (other countries: none)
            sellerAddress: strtoupper((string) ($opts->fiscal_country ?? '')) === 'SA' ? ZatcaParties::sellerAddress($opts) : [],
        );
    }

    /**
     * Pull adapter QR artefacts from the latest fiscal document for an order, if
     * one exists (authority_response.tlv / istd_qr / lookup_url).
     */
    private static function documentExtra(int $storeId, int $orderId): array
    {
        $doc = FiscalDocument::where('store_id', $storeId)
            ->where('order_id', $orderId)
            // P4-01: the receipt shows the SALE's QR — never a later credit note's, nor a superseded invoice's
            ->whereIn('type', ['simplified', 'standard'])->where('status', '!=', 'superseded')
            ->latest('id')
            ->first();

        $resp = $doc->authority_response ?? [];
        $extra = [];
        foreach (['tlv', 'istd_qr', 'lookup_url', 'seller_uuid'] as $k) {
            if (! empty($resp[$k])) {
                $extra[$k] = $resp[$k];
            }
        }

        // P4-01: the e-invoice number printed on the receipt (ZATCA simplified invoice: the sequential number)
        if ($doc && (string) $doc->number !== '') {
            $extra['number'] = (string) $doc->number;
        }

        return $extra;
    }

    public function receiptHeader(Store $store, FiscalReceiptContext $context): array
    {
        $profile = FeatureRegistry::isEnabled('fiscalization') ? FiscalRegistry::for($store) : null;
        if ($profile && ! FeatureRegistry::isEnabled('fiscal_'.strtolower($profile->country()))) {
            $profile = null;
        }
        if ($profile === null) {
            return ['qr' => null, 'title' => null, 'showVat' => false, 'showCr' => false];
        }
        $fields = $profile->requiredReceiptFields();
        $simplified = in_array('simplified_title', $fields, true) && $profile->isSimplified($context->total);

        return [
            'qr' => in_array('qr', $fields, true) ? $profile->qr($context) : null,
            'title' => in_array('simplified_title', $fields, true) || in_array('vat_number', $fields, true) ? $profile->invoiceTitle($simplified) : null,
            'showVat' => in_array('vat_number', $fields, true),
            'showCr' => in_array('cr_number', $fields, true),
        ];
    }
}
