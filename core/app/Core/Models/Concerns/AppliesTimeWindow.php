<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

use Carbon\Carbon;

/**
 * PARITY-RUN T5: the ONE day+time window implementation.
 *
 * Three models now carry the same window vocabulary — `menu` (shipped
 * 2026_06_26_310000), `menu_categories` (T5) and `price_schedules` (T5) — and
 * a second copy of "does 01:30 fall inside 22:00→02:00?" is exactly the kind of
 * near-duplicate that drifts. Menu::isAvailableNow() keeps its published
 * signature and behaviour byte for byte; it now delegates here.
 *
 * The rules, unchanged from the shipped implementation:
 *   - day codes are 3-letter lowercase (`mon`…`sun`); empty = every day;
 *   - the time window is enforced only when BOTH ends are set;
 *   - `from > to` is an OVERNIGHT window (22:00→02:00 covers 23:30 and 01:30);
 *   - the clock is the STORE's, never the server's — callers pass the tz.
 *
 * ⚠️ The 2026_06_26_310000 migration comment claims `available_days` holds
 * numeric weekday indexes ("0=Sun,1=Mon"). It does not, and never did: the
 * dashboard form posts `mon`…`sun` checkbox values and the server has always
 * compared 3-letter codes. Nine storefront themes believed the comment and
 * parsed the column with `.map(Number)` — see the T5 report.
 */
trait AppliesTimeWindow
{
    /**
     * @param  string|null  $days   CSV of mon…sun (null/empty = every day)
     * @param  string|null  $from   'HH:MM' or 'HH:MM:SS'
     * @param  string|null  $to     'HH:MM' or 'HH:MM:SS'
     * @param  Carbon|null  $at     store-local instant (defaults to now in $tz)
     */
    public static function windowCovers(?string $days, ?string $from, ?string $to, ?Carbon $at = null, ?string $tz = null): bool
    {
        // P6-49: no day list and no complete time range = always open. Most items have no window at all, and the Carbon
        // copy + three formats below cost real time when a 2,000-item store checks every item (and its category) per request.
        if (empty($days) && ! ($from && $to)) {
            return true;
        }

        $now = $at ? $at->copy() : Carbon::now($tz ?: config('app.timezone', 'UTC'));

        // Compare on HH:MM so a 'HH:MM:SS' column and an 'HH:MM' form value are
        // the same string shape — a lexicographic compare is only valid when both
        // operands are zero-padded to the same width.
        $cur  = $now->format('H:i');
        $from = $from ? substr((string) $from, 0, 5) : null;
        $to   = $to ? substr((string) $to, 0, 5) : null;

        // An overnight window (from > to) belongs to the day it OPENED. When the
        // current instant is in the post-midnight tail (cur <= to), the day-of-week
        // restriction must be checked against YESTERDAY — otherwise a "Fri
        // 22:00→02:00" rule stops matching at 00:00 Sat and 00:00–02:00 diners are
        // wrongly charged the full (non-window) price.
        $dayToCheck = strtolower($now->format('D'));
        if ($from && $to && $from > $to && $cur <= $to) {
            $dayToCheck = strtolower($now->copy()->subDay()->format('D'));
        }

        if (! empty($days)) {
            $wanted = array_filter(array_map('trim', explode(',', strtolower((string) $days))));
            if (! empty($wanted) && ! in_array($dayToCheck, $wanted, true)) {
                return false;
            }
        }

        if ($from && $to) {
            if ($from <= $to) {
                if ($cur < $from || $cur > $to) {
                    return false;
                }
            } elseif ($cur < $from && $cur > $to) {
                // Overnight: available late today OR early tomorrow.
                return false;
            }
        }

        return true;
    }

    /**
     * Does a CSV order-type scope admit `$orderType`? Empty scope = every type,
     * and a null order type (a menu browsed before the diner has chosen one)
     * never narrows anything — otherwise a dine-in-only dish would vanish from
     * the menu before the guest had a chance to say they were dining in.
     */
    public static function scopeCoversOrderType(?string $scope, ?string $orderType): bool
    {
        if (empty($scope) || $orderType === null || $orderType === '') {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower($scope))));

        return empty($allowed) || in_array(strtolower($orderType), $allowed, true);
    }

    /**
     * Instance helper: is THIS row's own window open now? Reads the
     * `available_days` / `available_from` / `available_to` /
     * `available_order_types` columns shared by Menu and MenuCategory.
     */
    public function windowOpenNow(?Carbon $at = null, ?string $tz = null, ?string $orderType = null): bool
    {
        if (! static::scopeCoversOrderType($this->available_order_types ?? null, $orderType)) {
            return false;
        }

        return static::windowCovers(
            $this->available_days ?? null,
            $this->available_from ?? null,
            $this->available_to ?? null,
            $at,
            $tz
        );
    }
}
