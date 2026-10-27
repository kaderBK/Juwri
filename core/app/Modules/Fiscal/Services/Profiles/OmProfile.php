<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Oman (OTA Fawtara) — VAT 5%. Peppol 5-corner via accredited SPs (phased
 * 2026-2027). B2C in scope later with a SELLER-generated QR (seller name, VAT
 * no, timestamp, totals, seller UUID). R-P1 wires the seller-QR + PINT-OM/TDD
 * export seam; SP transport is a deferred-ledger item.
 */
class OmProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'OM';
    }

    public function countryName(): string
    {
        return 'Oman';
    }

    public function defaultVatRate(): float
    {
        return 5.0;
    }

    public function currency(): string
    {
        return 'OMR';
    }

    public function roundingScale(): int
    {
        return 3; // OMR is a 3-decimal currency
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown', 'qr'];
    }

    /**
     * Oman seller QR: a compact pipe-delimited payload (name|VAT|timestamp|
     * total|vat|uuid). Rendered from receipt context — no authority round-trip.
     */
    public function qr(FiscalReceiptContext $ctx): ?string
    {
        $uuid = (string) ($ctx->extra('seller_uuid') ?? '');

        return implode('|', [
            $ctx->sellerName,
            (string) ($ctx->vatNumber ?? ''),
            $ctx->issuedAt->format('c'),
            number_format($ctx->total, 3, '.', ''),
            number_format($ctx->taxAmount, 3, '.', ''),
            $uuid,
        ]);
    }
}
