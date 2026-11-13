<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Egypt — ETA e-Receipt (B2C). Report-after-print within a 24h window; chains
 * per POS device via previousUUID. Printed receipt carries a QR linking to the
 * ETA anonymous public lookup. VAT 14% default.
 */
class EgProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'EG';
    }

    public function countryName(): string
    {
        return 'Egypt';
    }

    public function defaultVatRate(): float
    {
        return 14.0;
    }

    public function currency(): string
    {
        return 'EGP';
    }

    public function driverMode(): string
    {
        return self::MODE_REPORT;
    }

    public function hasLiveDriver(): bool
    {
        return true;
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown', 'qr'];
    }

    public function qrIsUrl(): bool
    {
        return true;
    }

    public function qr(FiscalReceiptContext $ctx): ?string
    {
        // The ETA lookup URL is produced by the driver once the receipt is submitted.
        return $ctx->extra('lookup_url');
    }
}
