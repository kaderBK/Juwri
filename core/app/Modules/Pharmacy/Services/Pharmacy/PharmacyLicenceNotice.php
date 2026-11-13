<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Services\Pharmacy;

use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;

/**
 * P6-45 (AUP 3.1): the public licence block of a pharmacy storefront.
 *
 * The one view-model for it: an explicit allow-list of three facts, and only for a store that is published
 * ({@see PharmacyMarkets::unpublishedReason()} is null: enabled country, newest licence APPROVED and not expired).
 * Never exposed here: expiry date, issuing authority, the scanned document, reviewer data, the pharmacist's own
 * registration number, the online-sales permit, contact details. Add a field only by extending {@see self::FIELDS}.
 */
final class PharmacyLicenceNotice
{
    /** The only keys that may ever leave this class. */
    public const FIELDS = ['licence_number', 'pharmacist', 'cr_number'];

    public function __construct(private readonly PharmacyMarkets $markets) {}

    /** @return array{licence_number: string, pharmacist?: string, cr_number?: string}|null null = show no block */
    public function forStore(Store $store): ?array
    {
        if (! PharmacyMarkets::isPharmacy($store) || $this->markets->unpublishedReason($store) !== null) {
            return null;
        }
        $licence = PharmacyLicence::latestFor((int) $store->id);
        if ($licence === null || $licence->status !== PharmacyLicence::APPROVED) {
            return null;
        }
        $number = trim((string) $licence->licence_number);
        if ($number === '') {
            return null;
        }

        $out = ['licence_number' => $number];
        $pharmacist = trim((string) $licence->responsible_person_name);
        if ($pharmacist !== '') {
            $out['pharmacist'] = $pharmacist;
        }
        $cr = trim(StoreSettings::string((int) $store->id, 'fiscal_cr_number'));
        if ($cr !== '') {
            $out['cr_number'] = $cr;
        }

        return array_intersect_key($out, array_flip(self::FIELDS));
    }

    /**
     * The block for the store a storefront page belongs to, by its public id (kit themes) — null when unknown.
     *
     * @return array{licence_number: string, pharmacist?: string, cr_number?: string}|null
     */
    public function forPublicId(string $publicId): ?array
    {
        // store public ids are UUIDv7; anything else cannot match (and PostgreSQL rejects a non-UUID in a uuid comparison)
        if (! \Illuminate\Support\Str::isUuid($publicId)) {
            return null;
        }
        $store = Store::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();

        return $store === null ? null : $this->forStore($store);
    }
}
