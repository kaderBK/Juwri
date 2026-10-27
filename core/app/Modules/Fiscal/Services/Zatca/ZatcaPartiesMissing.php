<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * P6-176: a standard (B2B) ZATCA document was stopped because seller or buyer details are missing or malformed.
 * Thrown BEFORE a chain number is taken, so no gap or invalid document is left behind.
 */
final class ZatcaPartiesMissing extends \DomainException
{
    /** @param  list<string>  $gaps  field names (seller.street, buyer.vat, …) */
    public function __construct(public readonly array $gaps)
    {
        parent::__construct(self::describe($gaps));
    }

    /** @param  list<string>  $gaps */
    public static function describe(array $gaps): string
    {
        $labels = [
            'name' => ___('Name'), 'vat' => ___('VAT number (15 digits, starts and ends with 3)'),
            'street' => ___('Street'), 'building_number' => ___('Building number (4 digits)'),
            'additional_number' => ___('Additional number (4 digits)'), 'district' => ___('District'),
            'city' => ___('City'), 'postal_code' => ___('Postal code (5 digits)'), 'country' => ___('Country'),
        ];
        $seller = $buyer = [];
        foreach ($gaps as $g) {
            if ($g === 'buyer') {
                $buyer[] = ___('All buyer details');

                continue;
            }
            [$side, $field] = explode('.', $g, 2) + [1 => ''];
            $label = $labels[$field] ?? $field;
            if ($side === 'seller') {
                $seller[] = $label;
            } else {
                $buyer[] = $label;
            }
        }
        $parts = [];
        if ($seller !== []) {
            $parts[] = ___('Store tax details (Settings → Tax):').' '.implode(', ', $seller);
        }
        if ($buyer !== []) {
            $parts[] = ___('Buyer details').': '.implode(', ', $buyer);
        }

        return ___('The tax invoice was not issued. Missing or invalid:').' '.implode('. ', $parts).'.';
    }
}
