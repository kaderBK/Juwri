<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Contracts;

use App\Models\WaitlistEntry;

/**
 * google-partner Lane D (D4): outbound real-time update client for waitlist
 * state changes (partner → Google Actions Center).
 *
 * D owns this interface + a Fake so D4 lands while Lane C's C6 real client
 * (service-account JWT push, FcmV1Client pattern) is still pending. F0 binds the
 * real client into the container; the factory falls back to the Fake meanwhile,
 * so nothing hits the network while dark.
 */
interface RwgWaitlistRtuClient
{
    /**
     * Push the entry's new state to Google. $googleState is a WaitlistEntryState
     * value (WAITING | SERVICE_READY | SEATED | CANCELED), already mapped from our
     * status by WaitlistStateMap. Implementations MUST be fail-soft.
     */
    public function push(WaitlistEntry $entry, string $googleState): void;
}
