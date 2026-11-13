<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Directory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;

/**
 * DIRECTORY T5-2: one weekly-hours model for both hour sources in the module —
 * `posts.timing` (a free-text field, e.g. "Daily, 9 AM - 11 PM" — there is no
 * structured per-day schedule anywhere on Post) and `directory_listings.
 * hours_json` (structured, same {day => {open, close, closed}} shape this
 * class produces, mirroring `Branch::hours`/`Branch::DAYS` for consistency
 * with the one other structured-hours source already in the app).
 *
 * REALITY vs TASKS.md delta: the spec asks this service to "parse posts.timing
 * … into a unified weekly model". `posts.timing` is unstructured prose (see
 * `resources/views/owner/stores/edit.blade.php:345` — a single
 * free-text input, placeholder "Daily, 9 AM - 11 PM"), not a per-day machine
 * format, so only a narrow set of common patterns can be read reliably (a
 * single daily range, "24 hours"/"24/7", or "closed"). Anything else — and
 * every genuinely per-day schedule typed in that field — degrades to
 * `unknown` rather than guessing, per the house rule (never a false "open").
 * A claimed store's ACTUAL structured hours live on `branches.hours` (already
 * consumed by `Branch::isOpenNow()` and `ShellController`'s home "open" map),
 * so `weeklyModelForPost()` prefers an active branch's hours and only falls
 * back to parsing the timing text when no branch has hours configured.
 */
class OpeningHoursService
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * The weekly model for a claimed store: an active branch's structured
     * hours first (the reliable source), else a best-effort read of the
     * free-text `timing` field, else null (unknown — no crash, no guess).
     */
    public static function weeklyModelForPost(Store $store): ?array
    {
        // Every store gets an auto-created "Main" branch on Post::created()
        // (see Post::boot(), #76) with hours left null, so picking merely the
        // first active branch would silently prefer that empty one over a
        // real branch the owner configured — filter on hours being SET, the
        // same predicate the not-preloaded query branch below already used.
        $branch = $store->relationLoaded('branches')
            ? $store->branches->first(fn ($b) => $b->active && ! empty($b->hours))
            : $store->branches()->where('active', true)->whereNotNull('hours')->first();

        if ($branch && ! empty($branch->hours)) {
            $fromBranch = self::fromHoursJson($branch->hours);
            if ($fromBranch !== null) {
                return $fromBranch;
            }
        }

        return self::fromTimingText($store->timing ?? null);
    }

    /** The weekly model for an imported/unclaimed listing (a Marketplace directory listing: its hours_json). */
    public static function weeklyModelForListing(Model $listing): ?array
    {
        return self::fromHoursJson($listing->hours_json ?? null);
    }

    /**
     * Parse a structured {day => {open,close,closed}} payload (the shape both
     * `directory_listings.hours_json` and `branches.hours` use). A day that is
     * missing, or whose open/close strings are not valid HH:MM, is treated as
     * `closed` for that single day rather than invalidating the whole week —
     * but a payload that is not even an array is fully unknown.
     */
    public static function fromHoursJson($raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $week = [];
        foreach (self::DAYS as $day) {
            $d = $raw[$day] ?? null;
            if (! is_array($d) || ! empty($d['closed'])) {
                $week[$day] = ['closed' => true];

                continue;
            }
            $open = self::validTime($d['open'] ?? null);
            $close = self::validTime($d['close'] ?? null);
            $week[$day] = ($open && $close)
                ? ['open' => $open, 'close' => $close, 'closed' => false]
                : ['closed' => true];
        }

        return $week;
    }

    /**
     * Best-effort read of a free-text hours line. Recognises only:
     *   - "24 hours" / "24/7" / "open 24 hours"      → open every day
     *   - "closed" (with no digits alongside it)      → closed every day
     *   - a single time range, either side with AM/PM  → that range every day
     *     ("Daily, 9 AM - 11 PM", "9:00-23:00", "09:00 – 22:30")
     * A range where exactly one side carries AM/PM (ambiguous — "9-11pm"
     * could mean 9am or 9pm) degrades to unknown rather than guessing.
     */
    public static function fromTimingText(?string $timing): ?array
    {
        $timing = trim((string) $timing);
        if ($timing === '') {
            return null;
        }

        if (preg_match('/\b24\s*\/?\s*7\b|24\s*-?\s*hours?/i', $timing)) {
            return array_fill_keys(self::DAYS, ['open' => '00:00', 'close' => '23:59', 'closed' => false]);
        }

        if (preg_match('/\bclosed\b/i', $timing) && ! preg_match('/\d/', $timing)) {
            return array_fill_keys(self::DAYS, ['closed' => true]);
        }

        if (! preg_match(
            '/(\d{1,2})(?::(\d{2}))?\s*([AaPp]\.?[Mm]\.?)?\s*(?:-|–|—|to|until)\s*(\d{1,2})(?::(\d{2}))?\s*([AaPp]\.?[Mm]\.?)?/',
            $timing,
            $m
        )) {
            return null;
        }

        // Optional trailing capture groups (minutes/meridiem on either side)
        // are simply absent from $m — not empty-string — when nothing after
        // them matched either (e.g. "09:00 - 23:00" has no group past 5).
        $openMer = ($m[3] ?? '') !== '' ? $m[3] : null;
        $closeMer = ($m[6] ?? '') !== '' ? $m[6] : null;
        // Exactly one side stamped with AM/PM is ambiguous — bail rather than guess.
        if (($openMer === null) !== ($closeMer === null)) {
            return null;
        }

        $open = self::normalizeClock($m[1], ($m[2] ?? '') ?: '0', $openMer);
        $close = self::normalizeClock($m[4], ($m[5] ?? '') ?: '0', $closeMer);
        if ($open === null || $close === null) {
            return null;
        }

        return array_fill_keys(self::DAYS, ['open' => $open, 'close' => $close, 'closed' => false]);
    }

    /** "9", "09", with an optional AM/PM meridiem → 24h "HH:MM", or null if out of range. */
    private static function normalizeClock(string $hour, string $minute, ?string $meridiem): ?string
    {
        $h = (int) $hour;
        $m = (int) $minute;
        if ($m < 0 || $m > 59) {
            return null;
        }

        if ($meridiem !== null) {
            if ($h < 1 || $h > 12) {
                return null;
            }
            $mer = strtolower(str_replace('.', '', $meridiem));
            $h = $mer === 'am' ? ($h === 12 ? 0 : $h) : ($h === 12 ? 12 : $h + 12);
        } elseif ($h < 0 || $h > 23) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /** "09:00"-shaped strings only; anything else invalidates that one day. */
    private static function validTime($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Evaluate a weekly model against a city's timezone at a given instant
     * (defaults to now). Handles overnight spans (a day's close < its open,
     * e.g. 22:00–02:00) by also checking whether YESTERDAY's span bleeds into
     * today's current time. Never throws: an invalid timezone or a null model
     * both degrade to `state => unknown`.
     *
     * @return array{state:string,closes_at?:string,opens_at?:string}
     */
    public static function isOpenAt(?array $week, ?string $timezone, ?Carbon $at = null): array
    {
        if ($week === null) {
            return ['state' => 'unknown'];
        }

        try {
            $now = $at ? $at->copy()->setTimezone($timezone ?: 'UTC') : Carbon::now($timezone ?: 'UTC');
        } catch (\Throwable $e) {
            return ['state' => 'unknown'];
        }

        $idx = $now->dayOfWeekIso - 1; // 0 = Monday
        $day = self::DAYS[$idx];
        $yesterday = self::DAYS[($idx + 6) % 7];
        $cur = $now->format('H:i');

        $today = $week[$day] ?? null;
        $prev = $week[$yesterday] ?? null;

        // Yesterday's overnight span (close < open) can still be "open" into
        // the very start of today, regardless of today's own state.
        if ($prev && empty($prev['closed']) && isset($prev['open'], $prev['close']) && $prev['close'] < $prev['open']) {
            if ($cur <= $prev['close']) {
                return ['state' => 'open', 'closes_at' => $prev['close']];
            }
        }

        if ($today === null) {
            return ['state' => 'unknown'];
        }
        if (! empty($today['closed'])) {
            return ['state' => 'closed'] + self::nextOpenHint($week, $idx);
        }
        if (! isset($today['open'], $today['close'])) {
            return ['state' => 'unknown'];
        }

        $overnight = $today['close'] < $today['open'];
        $isOpen = $overnight
            ? ($cur >= $today['open'] || $cur <= $today['close'])
            : ($cur >= $today['open'] && $cur <= $today['close']);

        if ($isOpen) {
            return ['state' => 'open', 'closes_at' => $today['close']];
        }

        return $cur < $today['open']
            ? ['state' => 'closed', 'opens_at' => $today['open']]
            : ['state' => 'closed'] + self::nextOpenHint($week, $idx);
    }

    /** Walk forward up to 7 days to find the next day that actually opens. */
    private static function nextOpenHint(array $week, int $fromIdx): array
    {
        for ($i = 1; $i <= 7; $i++) {
            $d = self::DAYS[($fromIdx + $i) % 7];
            $entry = $week[$d] ?? null;
            if ($entry && empty($entry['closed']) && isset($entry['open'])) {
                return ['opens_at' => $entry['open']];
            }
        }

        return [];
    }

    /**
     * Badge copy for a card/store page ("Open · closes 23:00" / "Closed ·
     * opens 09:00"), or null when the state is unknown — an unresolved badge
     * is worse than none, and it never renders as a false "open".
     */
    public static function badge(array $status): ?array
    {
        if ($status['state'] === 'open') {
            return ['positive' => true, 'closesAt' => $status['closes_at'] ?? null];
        }
        if ($status['state'] === 'closed') {
            return ['positive' => false, 'opensAt' => $status['opens_at'] ?? null];
        }

        return null;
    }
}
