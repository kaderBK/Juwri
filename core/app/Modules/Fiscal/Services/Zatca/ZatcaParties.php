<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * P6-176: the seller and buyer parties of a ZATCA document, and the checks a STANDARD (B2B, 0100000) tax invoice must
 * pass before it is built. Nothing here invents data: a missing or malformed field stops the document with a clear
 * message ({@see ZatcaPartiesMissing}), never an invalid XML.
 *
 * Rules (ZATCA XML Implementation Standard / data dictionary; see ZATCA-SPEC-CHECK.md (e)):
 *  - Seller address (BR-KSA-09): street (BT-35), building number (KSA-17), postal code (BT-38), city (BT-37),
 *    district (KSA-3), country SA (BT-40). Additional number (KSA-23, cbc:PlotIdentification) is optional.
 *    Building number = 4 digits (BR-KSA-37), postal code = 5 digits (BR-KSA-66), additional number = 4 digits.
 *  - Seller VAT (BT-31, BR-KSA-39/40): 15 digits, first and last digit 3.
 *  - Buyer on a standard invoice: name (BT-44, BR-KSA-42), VAT (BT-48, same format, BR-KSA-44) and, for a buyer in
 *    Saudi Arabia, street, building number, postal code (5 digits, BR-KSA-67), city, district and country
 *    (BR-KSA-10 / BR-KSA-63).
 *  - Simplified (B2C) invoices need no buyer.
 */
final class ZatcaParties
{
    /** Address keys, in UBL cac:PostalAddress order. */
    public const ADDRESS = ['street', 'building_number', 'additional_number', 'district', 'city', 'postal_code', 'country'];

    /** Store settings that hold the seller's national address (KSA only). */
    public const SELLER_KEYS = [
        'street' => 'fiscal_street',
        'building_number' => 'fiscal_building_number',
        'additional_number' => 'fiscal_additional_number',
        'district' => 'fiscal_district',
        'city' => 'fiscal_city',
        'postal_code' => 'fiscal_postal_code',
    ];

    private const REQUIRED_ADDRESS = ['street', 'building_number', 'district', 'city', 'postal_code', 'country'];

    public static function validVat(?string $vat): bool
    {
        return (bool) preg_match('/^3\d{13}3$/', (string) $vat);
    }

    public static function validBuildingNumber(?string $v): bool
    {
        return (bool) preg_match('/^\d{4}$/', (string) $v);
    }

    public static function validPostalCode(?string $v): bool
    {
        return (bool) preg_match('/^\d{5}$/', (string) $v);
    }

    /**
     * The seller address from the store settings (trimmed; country SA). Empty values stay empty — never filled in.
     *
     * @param  object|array<string,mixed>  $options  store_options of the store
     * @return array<string,string>
     */
    public static function sellerAddress(object|array $options): array
    {
        $options = (array) $options;
        $out = [];
        foreach (self::SELLER_KEYS as $field => $key) {
            $out[$field] = trim((string) ($options[$key] ?? ''));
        }
        $out['country'] = 'SA';

        return $out;
    }

    /**
     * A buyer as entered by the merchant, normalised (trimmed strings, country upper-case, SA when empty).
     *
     * @param  array<string,mixed>  $in
     * @return array<string,string>
     */
    public static function buyer(array $in): array
    {
        $out = ['name' => trim((string) ($in['name'] ?? '')), 'vat' => preg_replace('/\s+/', '', (string) ($in['vat'] ?? '')) ?? ''];
        foreach (self::ADDRESS as $f) {
            $out[$f] = trim((string) ($in[$f] ?? ''));
        }
        $out['country'] = strtoupper($out['country']) ?: 'SA';
        // P6-186: optional region (cbc:CountrySubentity) — SDK 203's BR-KSA-10 wants it on a standard buyer
        if (trim((string) ($in['region'] ?? '')) !== '') {
            $out['region'] = trim((string) $in['region']);
        }

        return $out;
    }

    /**
     * What stops a standard (B2B) document: missing or malformed seller / buyer fields, as field names
     * (`seller.street`, `buyer.vat`, …). Empty list = ready.
     *
     * @param  array<string,mixed>  $sellerAddress
     * @param  array<string,mixed>|null  $buyer
     * @return list<string>
     */
    public static function standardGaps(string $sellerName, ?string $sellerVat, array $sellerAddress, ?array $buyer): array
    {
        $gaps = [];
        if (trim($sellerName) === '') {
            $gaps[] = 'seller.name';
        }
        if (! self::validVat($sellerVat)) {
            $gaps[] = 'seller.vat';
        }
        foreach (self::addressGaps($sellerAddress, true) as $f) {
            $gaps[] = 'seller.'.$f;
        }
        if (strtoupper((string) ($sellerAddress['country'] ?? '')) !== 'SA') {
            $gaps[] = 'seller.country';
        }
        if ($buyer === null) {
            $gaps[] = 'buyer';

            return array_values(array_unique($gaps));
        }
        if (trim((string) ($buyer['name'] ?? '')) === '') {
            $gaps[] = 'buyer.name';
        }
        if (! self::validVat($buyer['vat'] ?? null)) {
            $gaps[] = 'buyer.vat';
        }
        $saudi = strtoupper((string) ($buyer['country'] ?? '')) === 'SA';
        foreach (self::addressGaps($buyer, $saudi) as $f) {
            $gaps[] = 'buyer.'.$f;
        }
        // P6-186 (SDK BR-KSA-63): a Saudi buyer's national address includes the additional number (KSA-19)
        if ($saudi && trim((string) ($buyer['additional_number'] ?? '')) === '') {
            $gaps[] = 'buyer.additional_number';
        }

        return array_values(array_unique($gaps));
    }

    /**
     * @param  array<string,mixed>  $sellerAddress
     * @param  array<string,mixed>|null  $buyer
     *
     * @throws ZatcaPartiesMissing when the standard document cannot be issued
     */
    public static function assertStandard(string $sellerName, ?string $sellerVat, array $sellerAddress, ?array $buyer): void
    {
        $gaps = self::standardGaps($sellerName, $sellerVat, $sellerAddress, $buyer);
        if ($gaps !== []) {
            throw new ZatcaPartiesMissing($gaps);
        }
    }

    /**
     * @param  array<string,mixed>  $a
     * @return list<string>
     */
    private static function addressGaps(array $a, bool $saudi): array
    {
        $gaps = [];
        // a foreign buyer: street, city and country (BR-KSA-10); a Saudi address: the full national address
        $required = $saudi ? self::REQUIRED_ADDRESS : ['street', 'city', 'country'];
        foreach ($required as $f) {
            if (trim((string) ($a[$f] ?? '')) === '') {
                $gaps[] = $f;
            }
        }
        if ($saudi) {
            if (($a['building_number'] ?? '') !== '' && ! self::validBuildingNumber((string) $a['building_number'])) {
                $gaps[] = 'building_number';
            }
            if (($a['postal_code'] ?? '') !== '' && ! self::validPostalCode((string) $a['postal_code'])) {
                $gaps[] = 'postal_code';
            }
            if (($a['additional_number'] ?? '') !== '' && ! self::validBuildingNumber((string) $a['additional_number'])) {
                $gaps[] = 'additional_number';
            }
        }

        return $gaps;
    }
}
