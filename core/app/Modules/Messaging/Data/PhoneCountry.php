<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Data;

/**
 * P1-08b follow-up (J27): rough dialing-code → ISO country for message rate lookup (seeded markets only; moved here
 * from Billing, which now reads it from here).
 */
final class PhoneCountry
{
    public const DIAL_CODES = [
        '1' => 'US', '44' => 'GB', '971' => 'AE', '55' => 'BR',
        '91' => 'IN', '49' => 'DE', '52' => 'MX',
        // Credit packs: juwri's own markets (PRICING.md: SAR / AED / EGP) and the rest of the GCC were missing, so a
        // Saudi or Egyptian number was priced (and credited) at the `default` rate instead of its country's.
        '966' => 'SA', '965' => 'KW', '973' => 'BH', '974' => 'QA', '968' => 'OM', '20' => 'EG',
    ];

    /** ISO 3166 alpha-2 of a phone number, or null when the prefix is not a seeded market. */
    public static function of(string $phone): ?string
    {
        $digits = ltrim((string) preg_replace('/\D+/', '', $phone), '0');
        foreach ([3, 2, 1] as $len) {
            $prefix = substr($digits, 0, $len);
            if (isset(self::DIAL_CODES[$prefix])) {
                return self::DIAL_CODES[$prefix];
            }
        }

        return null;
    }
}
