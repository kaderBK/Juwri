<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Jobs;

use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Modules\Reservations\Services\Rwg\RwgRealtimeClient;

/**
 * GOOGLE-PARTNER Lane C — queued Reserve-with-Google real-time update push.
 *
 * Dispatched from the Reservation MODEL observer on a status change of a
 * Google-sourced booking (rwg_booking_id set). Kept off the web request; fail-soft
 * and DARK — RwgRealtimeClient no-ops when the integration is unconfigured, so a
 * missing-creds environment runs zero HTTP even though the job is dispatched.
 */
class PushRwgBookingUpdateJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $reservationId) {}

    public function handle(RwgRealtimeClient $client): void
    {
        $reservation = Reservation::find($this->reservationId);
        if (! $reservation || empty($reservation->rwg_booking_id)) {
            return; // gone or not a Google booking
        }

        $client->pushBookingStatus($reservation);
    }
}
