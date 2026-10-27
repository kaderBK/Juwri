<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

use Juwri\Modules\Reservations\Services\Rwg\ReservationAvailabilityService;
use Carbon\Carbon;

/**
 * GOOGLE-PARTNER Lane C — Reserve-with-Google Availability feed.
 *
 * One Availability entry per bookable slot across the horizon, emitting spots_open /
 * spots_total ONLY (the E2E requirement). Uses the shared ReservationAvailability
 * service — the SAME truth the storefront and booking server use — so a slot Google
 * offers is a slot the store will honor.
 *
 * Two guards keep the feed honest (from C0):
 *   - Unlimited-covers stores (covers_per_slot 0) are SKIPPED: spots_total/spots_open
 *     have no finite value, so we do not advertise them. To appear on Google a store
 *     sets a positive covers-per-slot (dashboard hint).
 *   - Branch-less / hour-less merchants are SKIPPED: never advertise 24/7 (the
 *     always-open windowFor fallback is for the human storefront path only).
 */
class AvailabilityFeedBuilder implements RwgFeedContributor
{
    public function __construct(
        private RwgMerchantSource $source,
        private ReservationAvailabilityService $availability,
    ) {
    }

    public function filename(): string
    {
        return 'availability_feed.json';
    }

    public function build(): array
    {
        $slots = [];
        foreach ($this->source->merchants() as $m) {
            $store = $m['store'];
            $branch = $m['branch'];
            $rid = (int) $store->id;

            // Finite capacity required, and real branch hours (no 24/7 advertising).
            if ($this->availability->coversPerSlot($rid) <= 0) {
                continue;
            }
            if (! $this->availability->branchHasHours($branch)) {
                continue;
            }

            $interval = $this->availability->slotInterval($rid);
            $durationSec = $interval * 60;
            $horizon = $this->availability->horizonDays($rid);
            $tz = $this->availability->timezone($rid, $branch);
            $today = Carbon::now($tz)->startOfDay();

            for ($d = 0; $d <= $horizon; $d++) {
                $date = $today->copy()->addDays($d)->format('Y-m-d');
                foreach ($this->availability->slotsFor($rid, $branch, $date) as $slot) {
                    $slots[] = [
                        'merchant_id'  => $m['merchant_id'],
                        'service_id'   => $m['service_id'],
                        'start_sec'    => $slot['at']->getTimestamp(),
                        'duration_sec' => $durationSec,
                        'spots_total'  => (int) $slot['spots_total'],
                        'spots_open'   => (int) $slot['spots_open'],
                    ];
                }
            }
        }

        return [
            'metadata'             => RwgFeedBuilder::metadata(),
            'service_availability' => [
                ['availability' => $slots],
            ],
        ];
    }
}
