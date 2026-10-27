<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

use Juwri\Core\Models\Store;

/**
 * google-partner Lane D (D5): waitlist contribution to the Reserve-with-Google
 * Services feed.
 *
 * Emits the `waitlist_rules`-bearing Service entry (per Actions Center waitlist
 * Services-feed schema) for a merchant that opted its RwG waitlist in. Lane C
 * owns the feed FRAMEWORK (`RwgFeedBuilder`, metadata wrapper, SFTP push); this
 * builder is registered into it via C's config-array registry — it never edits a
 * Lane-C file. Standalone + snapshot-tested here so it lands even while C2 is
 * pending; F0 registers it.
 */
class WaitlistServiceFeedBuilder
{
    /** One waitlist service per merchant. */
    public const SERVICE_ID = 'waitlist';

    private const DEFAULT_MAX_PARTY = 12;

    /**
     * The Service feed entry for this store's waitlist, or null when the store
     * has not opted its RwG waitlist in (nothing to advertise → no Google offer).
     *
     * @return array<string,mixed>|null
     */
    public function buildService(Store $store, string $merchantId): ?array
    {
        if (! store_options($store->id, 'rwg_waitlist_enabled')) {
            return null;
        }

        $maxParty = (int) store_options($store->id, 'waitlist_max_party', self::DEFAULT_MAX_PARTY);
        if ($maxParty < 1) {
            $maxParty = self::DEFAULT_MAX_PARTY;
        }

        return [
            'merchant_id' => $merchantId,
            'service_id' => self::SERVICE_ID,
            'localized_service_name' => [
                'value' => 'Waitlist',
            ],
            // We do not capture free-form requests (no column), so Google must not
            // offer the box; min is always 1.
            'waitlist_rules' => [
                'min_party_size' => 1,
                'max_party_size' => $maxParty,
                'supports_additional_request' => false,
            ],
            'prepayment_type' => 'NOT_SUPPORTED',
        ];
    }
}
