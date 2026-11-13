<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

/**
 * google-partner Lane D (D0 decision): map our terminal-safe `waitlist_entries.status`
 * enum to Google's WaitlistEntryState, WITHOUT an enum migration (MySQL enum trap).
 *
 * Our `left` is a single terminal state used for both guest-cancel and no-show;
 * we emit CANCELED for it uniformly (we have no UI signal distinguishing the two).
 * NO_SHOW is therefore never emitted — recorded as an owner follow-up.
 */
final class WaitlistStateMap
{
    /** our status => Google WaitlistEntryState */
    private const TO_GOOGLE = [
        'waiting'  => 'WAITING',
        'notified' => 'SERVICE_READY',
        'seated'   => 'SEATED',
        'left'     => 'CANCELED',
    ];

    public static function toGoogle(string $status): string
    {
        return self::TO_GOOGLE[$status] ?? 'WAITING';
    }
}
