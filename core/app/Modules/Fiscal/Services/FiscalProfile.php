<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;

/**
 * TAX-RUN F0: per-country fiscalization profile. A profile is a PURE
 * classification/formatting descriptor — it declares the country's VAT default,
 * simplified-invoice rules, required receipt fields, rounding, driver mode, and
 * (optionally) a QR generator. It NEVER computes order tax (Order::computeTax()
 * is the one source) and never talks to an authority (that is the driver's job).
 *
 * Driver modes:
 *   - clear_before_print  : the document must be validated by the authority
 *                           BEFORE the customer copy is final (JoFotara, ZATCA
 *                           standard/B2B).
 *   - report_after_print  : issue immediately, submit within an SLA window
 *                           (ZATCA simplified 24h, ETA 24h).
 *   - none                : profile-only country (correct receipt today, no API).
 */
abstract class FiscalProfile
{
    public const MODE_CLEAR = 'clear_before_print';

    public const MODE_REPORT = 'report_after_print';

    public const MODE_NONE = 'none';

    /** ISO 3166-1 alpha-2 country code (uppercase). */
    abstract public function country(): string;

    /** Human country name (English). */
    abstract public function countryName(): string;

    /** Default VAT/GST percentage for the country (owner config still wins). */
    abstract public function defaultVatRate(): float;

    /** ISO-4217 currency code the country's receipts are denominated in. */
    abstract public function currency(): string;

    /** Driver mode constant. */
    public function driverMode(): string
    {
        return self::MODE_NONE;
    }

    /** True when a live authority driver exists for this country. */
    public function hasLiveDriver(): bool
    {
        return false;
    }

    /**
     * Threshold (in the country's currency) at or below which a SIMPLIFIED tax
     * invoice may be issued instead of a full one. null = no simplified concept.
     */
    public function simplifiedThreshold(): ?float
    {
        return null;
    }

    /**
     * Receipt fields the authority mandates. Used by the receipt block to decide
     * what to render. Superset keys: seller_name, vat_number, cr_number,
     * tax_breakdown, simplified_title, qr, buyer_details.
     *
     * @return array<int,string>
     */
    public function requiredReceiptFields(): array
    {
        return [];
    }

    /** True when the receipt must carry the "simplified tax invoice" title. */
    public function requiresSimplifiedTitle(): bool
    {
        return in_array('simplified_title', $this->requiredReceiptFields(), true);
    }

    /**
     * Whether an order of this total qualifies for a SIMPLIFIED invoice. Below or
     * at the threshold → simplified; above → a full tax invoice (buyer details).
     * No threshold → always simplified-eligible.
     */
    public function isSimplified(float $total): bool
    {
        $threshold = $this->simplifiedThreshold();

        return $threshold === null || $total <= $threshold;
    }

    /** Localized invoice title (falls back to a generic receipt title). */
    public function invoiceTitle(bool $simplified): string
    {
        if ($simplified && $this->requiresSimplifiedTitle()) {
            return ___('Simplified Tax Invoice');
        }

        return in_array('vat_number', $this->requiredReceiptFields(), true)
            ? ___('Tax Invoice')
            : ___('Receipt');
    }

    /**
     * Number of decimal places to round monetary document totals to. Applied at
     * the FORMAT layer only, with a reconciliation assert upstream.
     */
    public function roundingScale(): int
    {
        return 2;
    }

    public function round(float $value): float
    {
        return round($value, $this->roundingScale());
    }

    /** Tolerance (absolute) allowed between a rounded document total and the order total. */
    public function roundingTolerance(): float
    {
        return 0.02;
    }

    /**
     * Validate a VAT/TRN number's shape for this country. Loose by default; the
     * live-driver countries override with the real format.
     */
    public function validateVatNumber(?string $vat): bool
    {
        if ($vat === null || $vat === '') {
            return true; // optional unless a caller enforces presence
        }

        return (bool) preg_match('/^[A-Za-z0-9\- ]{5,20}$/', $vat);
    }

    /**
     * QR content for the receipt, or null when the country mandates no QR. The
     * base profile returns null; countries that self-generate a QR (ZATCA 5-tag
     * pre-onboarding, Oman seller QR) override this.
     */
    public function qr(FiscalReceiptContext $ctx): ?string
    {
        return null;
    }

    /**
     * Whether the QR should be rendered as a raw string payload (SVG built from
     * it) — true for URL/TLV payloads. Kept for future per-country nuance.
     */
    public function qrIsUrl(): bool
    {
        return false;
    }
}
