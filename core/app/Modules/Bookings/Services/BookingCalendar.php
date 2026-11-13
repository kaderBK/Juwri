<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Models\Booking;

/** P3-10: "Add to calendar" — an RFC 5545 .ics event and a Google Calendar link, both with the manage link. */
final class BookingCalendar
{
    public static function ics(Booking $booking, Store $store): string
    {
        $manage = ManageLink::url($booking, $store);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//juwri//bookings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$booking->public_id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'juwri'),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$booking->starts_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$booking->ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::escape(self::title($booking, $store)),
            'LOCATION:'.self::escape(self::location($booking, $store)),
            'DESCRIPTION:'.self::escape(___('Booking :number', ['number' => $booking->number])."\n".___('Change or cancel').': '.$manage),
            'URL:'.self::escape($manage),
            'STATUS:'.($booking->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    public static function googleUrl(Booking $booking, Store $store): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => self::title($booking, $store),
            'dates' => $booking->starts_at->copy()->utc()->format('Ymd\THis\Z').'/'.$booking->ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'details' => ___('Change or cancel').': '.ManageLink::url($booking, $store),
            'location' => self::location($booking, $store),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function title(Booking $booking, Store $store): string
    {
        return $booking->lines->pluck('name')->implode(', ').' · '.$store->title;
    }

    private static function location(Booking $booking, Store $store): string
    {
        return (string) ($booking->location === 'at_home' ? $booking->address : $store->address);
    }

    /** TEXT value escaping (RFC 5545 §3.3.11). */
    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', ''], $text);
    }

    /** Lines longer than 75 octets are folded (CRLF + one space), never inside a UTF-8 character. */
    private static function fold(string $line): string
    {
        $out = '';
        $width = 0;
        foreach (mb_str_split($line) as $char) {
            $len = strlen($char);
            if ($width + $len > 75) {
                $out .= "\r\n ";
                $width = 1;
            }
            $out .= $char;
            $width += $len;
        }

        return $out;
    }
}
