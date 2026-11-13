<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Core\Models\Store;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;

/**
 * P1-23 (AUP 3.1): a pharmacy store is published only in an enabled country with an approved, unexpired licence.
 * Tests about what a PUBLISHED pharmacy shows or sells open its market with this helper.
 */
final class PharmacyMarket
{
    /** Enable $country and give the store (or every pharmacy store) an approved licence valid for a year. */
    public static function open(?Store $store = null, string $country = 'SA'): void
    {
        config(['pharmacy.enabled_countries' => array_values(array_unique(array_merge(
            (array) config('pharmacy.enabled_countries', []), [$country]
        )))]);

        $stores = $store !== null ? [$store] : Store::query()->where('store_type', 'pharmacy')->get()->all();
        foreach ($stores as $s) {
            self::licence($s, PharmacyLicence::APPROVED, $country);
        }
    }

    public static function licence(Store $store, string $status, string $country = 'SA', ?string $expiresOn = null): PharmacyLicence
    {
        $licence = new PharmacyLicence([
            'store_id' => $store->id,
            'country' => $country,
            'licence_number' => 'LIC-'.$store->id,
            'issuing_authority' => 'Test authority',
            'expires_on' => $expiresOn ?? now()->addYear()->toDateString(),
            'document_path' => 'pharmacy-licences/'.$store->id.'/test.pdf',
        ]);
        $licence->forceFill(['status' => $status])->save();

        return $licence;
    }
}
