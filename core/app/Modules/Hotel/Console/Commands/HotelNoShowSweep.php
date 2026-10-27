<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;

/**
 * HPMS A10 — no-show sweep. Marks CONFIRMED bookings whose guest never arrived a
 * no-show once the per-store grace window has elapsed:
 *   now > check_in 00:00 + `hotel_noshow_grace_hours` (option, default 18)
 * Marking a no-show frees the room's night rows (BookingService::markNoShow), so
 * the inventory reopens, and dispatches an owner notification (type `hotel_pms`,
 * title []()-escaped).
 *
 * FEE (documented, v1): `hotel_noshow_fee` > 0 posts NO folio charge — a booking
 * has no folio before check-in (the stay/folio open at check-in). The fee is a
 * notification-only marker here; posting it to a card-on-file / deposit is a
 * NOTES follow-up (see LEDGER-A ## NOTES).
 *
 * Idempotent: once a booking is no_show it no longer matches the CONFIRMED query,
 * so a re-run touches nothing.
 */
class HotelNoShowSweep extends Command
{
    protected $signature = 'hotel:no-show-sweep';

    protected $description = 'Mark confirmed bookings past their arrival + grace window as no-shows and free the rooms.';

    public function handle(): int
    {
        $now = Carbon::now();
        $swept = 0;
        $graceCache = [];
        $feeCache = [];
        $link = Route::has('hotel.pms.bookings') ? route('hotel.pms.bookings') : null;

        HotelBooking::where('status', HotelBooking::STATUS_CONFIRMED)
            ->whereDate('check_in', '<=', $now->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($now, &$swept, &$graceCache, &$feeCache, $link) {
                foreach ($bookings as $booking) {
                    $rid = (int) $booking->store_id;

                    if (! array_key_exists($rid, $graceCache)) {
                        $g = store_options($rid, 'hotel_noshow_grace_hours');
                        $graceCache[$rid] = ($g === false || $g === null || $g === '') ? 18 : max(0, (int) $g);
                    }
                    $deadline = Carbon::parse($booking->check_in)->startOfDay()->addHours($graceCache[$rid]);
                    if ($now->lte($deadline)) {
                        continue; // still within the grace window
                    }

                    BookingService::markNoShow($booking); // status no_show + free nights
                    $swept++;

                    if (! array_key_exists($rid, $feeCache)) {
                        $f = store_options($rid, 'hotel_noshow_fee');
                        $feeCache[$rid] = ($f === false || $f === null || $f === '') ? 0.0 : (float) $f;
                    }
                    $feeNote = $feeCache[$rid] > 0
                        ? ' — '.___('fee due').' '.rtrim(rtrim(number_format($feeCache[$rid], 2, '.', ''), '0'), '.')
                        : '';

                    $guest = $this->escape((string) $booking->guest_name);
                    $title = $this->escape(___('No-show').' '.$booking->code).' '.$guest.$this->escape($feeNote);
                    CreateNotificationJob::dispatch($title, 'hotel_pms', $link);
                }
            });

        $this->info("hotel:no-show-sweep marked {$swept} no-show(s).");

        return self::SUCCESS;
    }

    /** Neutralise []() so a guest name can't inject a markdown link into the title. */
    private function escape(string $s): string
    {
        return str_replace(['[', ']', '(', ')'], ['{', '}', '{', '}'], $s);
    }
}
