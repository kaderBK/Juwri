<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * P6-117: ZATCA VAT exemption reason codes (XML Implementation Standard code list, see ZATCA-SPEC-CHECK.md (d)).
 * Every Z/E/O tax category carries one (BR-KSA-69 / BR-Z-10 / BR-E-10 / BR-O-10), and the code must belong to that
 * category. Food is standard-rated (S, 15%) and never takes a code.
 */
final class Vatex
{
    /** code => [category, reason] */
    public const CODES = [
        'VATEX-SA-29' => ['E', 'Financial services mentioned in Article 29 of the VAT Regulations'],
        'VATEX-SA-29-7' => ['E', 'Life insurance services mentioned in Article 29 of the VAT Regulations'],
        'VATEX-SA-30' => ['E', 'Real estate transactions mentioned in Article 30 of the VAT Regulations'],
        'VATEX-SA-32' => ['Z', 'Export of goods'],
        'VATEX-SA-33' => ['Z', 'Export of services'],
        'VATEX-SA-34-1' => ['Z', 'The international transport of Goods'],
        'VATEX-SA-34-2' => ['Z', 'International transport of passengers'],
        'VATEX-SA-34-3' => ['Z', 'Services directly connected and incidental to a Supply of international passenger transport'],
        'VATEX-SA-34-4' => ['Z', 'Supply of a qualifying means of transport'],
        'VATEX-SA-34-5' => ['Z', 'Any services relating to Goods or passenger transportation, as defined in article twenty five of these Regulations'],
        'VATEX-SA-35' => ['Z', 'Medicines and medical equipment'],
        'VATEX-SA-36' => ['Z', 'Qualifying metals'],
        'VATEX-SA-EDU' => ['Z', 'Private education to citizen'],
        'VATEX-SA-HEA' => ['Z', 'Private healthcare to citizen'],
        'VATEX-SA-MLTRY' => ['Z', 'Supply of qualified military goods'],
        'VATEX-SA-OOS' => ['O', 'Not subject to VAT'],
    ];

    /** Default when a Z/E/O line comes without a code (matches OrderFiscalContext::engineLines). */
    public const DEFAULTS = ['Z' => 'VATEX-SA-35', 'E' => 'VATEX-SA-29', 'O' => 'VATEX-SA-OOS'];

    /** P6-175: the store setting that holds its own VATEX code for a category (Z, E); O always takes VATEX-SA-OOS. */
    public const STORE_KEYS = ['Z' => 'tax_zero_vatex', 'E' => 'tax_exempt_vatex'];

    /**
     * The codes a store may pick for a category (Z or E).
     *
     * @return array<string,string> code => reason
     */
    public static function choices(string $category): array
    {
        $out = [];
        foreach (self::CODES as $code => [$cat, $reason]) {
            if ($cat === $category) {
                $out[$code] = $reason;
            }
        }

        return $out;
    }

    /**
     * The [code, reason] for a category; null for S. Throws on an unknown code or one from another category.
     *
     * @return array{0:string,1:string}|null
     */
    public static function resolve(string $category, ?string $code, ?string $reason): ?array
    {
        if ($category === 'S') {
            return null;
        }
        $code = ($code ?? '') !== '' ? (string) $code : (self::DEFAULTS[$category] ?? '');
        if (! isset(self::CODES[$code]) || self::CODES[$code][0] !== $category) {
            throw new \RuntimeException("ZATCA: exemption code '{$code}' is not valid for VAT category {$category}.");
        }
        // OOS takes a free-text reason; the others the code list text unless one is given
        $reason = trim((string) $reason) !== '' ? (string) $reason : self::CODES[$code][1];

        return [$code, $reason];
    }
}
