<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * WhatsApp number helpers — normalisation, validation and click-to-chat links.
 *
 * WI-M15 §1d: extracted verbatim from Helper.php (a Concerns\-style split for a
 * function file) to keep that god-file under its shrinking line ceiling. These
 * are global helpers, autoloaded via composer "files" exactly like Helper.php
 * and GeoHelper.php — every call site is unchanged.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\LinkProvider;

/**
 * WA-S3: the digits wa.me will actually be given for a number as typed.
 *
 * Merchants paste "+44 7700 900201" and "(0770) 090-0201" — the storefront has
 * always stripped the punctuation on read, so we strip it here too rather than
 * rejecting a number that would in fact have worked.
 */
function whatsapp_normalize_number(?string $raw): string
{
    return preg_replace('/\D+/', '', whatsapp_latin_digits($raw));
}

/**
 * WA-E3: rewrite Arabic-Indic and Persian numerals as ASCII digits.
 *
 * `preg_replace('/\D+/', '', …)` deletes ٠-٩ and ۰-۹ outright, so a merchant who
 * typed their number in Arabic numerals resolved to an EMPTY string — the digits
 * were not "kept as-is", they vanished. Two live stores (129 and 962) were in
 * exactly that state, and only their legacy option, which happened to hold ASCII
 * digits, kept them working. E3 deleted that option and they went dark.
 *
 * Most of this platform's merchants are Arabic-speaking, so this is a normal way
 * to type a number here, not an edge case.
 */
function whatsapp_latin_digits(?string $raw): string
{
    return strtr((string) $raw, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

/**
 * WA-S3: why this WhatsApp number will not work, or null if it will.
 *
 * The spec asked for "digits only, no leading zero, no +/spaces/dashes". The real
 * data says that rule would reject most of what is already stored: of 1,722
 * branch numbers, 912 contain a `+` and 222 contain a space, dash or bracket —
 * all of which the storefront already strips and dials correctly. So punctuation
 * is normalised, not refused, and only genuinely undiallable input is rejected:
 *
 *   - letters, which no amount of stripping saves
 *   - a leading 0, the national trunk prefix — wa.me needs the country code, and
 *     634 stored numbers have this, i.e. this is the bug the spec was aiming at
 *   - a length outside E.164's 8–15 digits (43 stored are too short, 8 too long)
 *
 * @return string|null a message for the merchant, or null when the number is fine
 */
function whatsapp_number_problem(?string $raw): ?string
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        return null; // Not having a number is allowed; the storefront falls back.
    }

    if (preg_match('/[a-zA-Z]/', $raw)) {
        return ___('A WhatsApp number can only contain digits, and optionally + ( ) - or spaces.');
    }

    /* Arabic-Indic numerals are digits a merchant here may well type; they are
       normalised, not rejected. */
    $digits = whatsapp_normalize_number($raw);

    if ($digits === '') {
        return ___('A WhatsApp number can only contain digits, and optionally + ( ) - or spaces.');
    }

    if (str_starts_with($digits, '0')) {
        return ___('Start with the country code, not 0 — WhatsApp cannot dial a local number. For example 447700900201, not 07700900201.');
    }

    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return ___('A WhatsApp number needs between 8 and 15 digits, including the country code.');
    }

    return null;
}

/**
 * WA-D4: record that something still read a legacy `whatsapp_number*` option.
 *
 * This is the evidence for Lane E. Before E3 deletes those options we want to be
 * able to say "nothing has read one in N days" rather than hoping. Logged once
 * per store per key per day so a busy storefront cannot flood the log.
 *
 * Deliberately best-effort: a logging failure must never break an order.
 */
function whatsapp_log_legacy_read(int $storeId, string $key): void
{
    try {
        $cacheKey = 'wa_legacy_read:'.$storeId.':'.$key.':'.now()->format('Y-m-d');

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, 1, now()->addDay());

        Log::channel(config('logging.default'))->info('[wa-legacy-read] a legacy WhatsApp option was read', [
            'store_id' => $storeId,
            'option' => $key,
        ]);

        // Counter the System Info panel reads, so an operator can see it at a glance.
        $counter = PlatformSettings::int('whatsapp_legacy_read_count');
        PlatformSettings::set('whatsapp_legacy_read_count', (string) ($counter + 1));
        PlatformSettings::set('whatsapp_legacy_read_last_at', now()->toDateTimeString());
    } catch (Throwable $e) {
        // Never let telemetry break an order.
    }
}

/** WA-D3: has the owner already dismissed the "place your extra numbers" notice? */
function whatsapp_extras_notice_dismissed(int $storeId): bool
{
    $flag = store_options($storeId, 'whatsapp_extras_notice_dismissed');

    return $flag !== false && filter_var($flag, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Build a wa.me / api.whatsapp.com click-to-chat deep link. Single source of
 * truth for WhatsApp link building (menu themes + QuickOrder flow) so the
 * format stays consistent across the app. See App\Services\WhatsApp.
 *
 * @param  string|int  $number  destination WhatsApp number
 * @param  string  $message  optional pre-filled message
 * @return string
 */
function whatsapp_link($number, $message = '')
{
    return LinkProvider::buildUrl((string) $number, (string) $message);
}
