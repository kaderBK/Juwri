<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Storefront\StorefrontExtras;
use Keepsuit\Liquid\Drop;

/**
 * Theme Studio B0.6: the `hours` global — the store's opening hours and live
 * open/closed state, from the same helpers as the Blade chip (store_open_now,
 * storefront_hours_today, OpeningHoursService). Times are HH:MM in the store's
 * timezone; `week` is Monday-first rows {day, open, close, closed};
 * `today_key` / `now_time` are "now" in the store's timezone.
 */
class HoursDrop extends Drop
{
    /** @param  array<string,mixed>  $data  built by App\Storefront\StorefrontExtras */
    public function __construct(protected array $data = []) {}

    /** Open right now (fails open: no hours set = open; the manual switch '0' = closed). */
    public function is_open(): bool
    {
        return (bool) ($this->data['is_open'] ?? false);
    }

    /** The owner switched the store to closed. */
    public function manual_closed(): bool
    {
        return (bool) ($this->data['manual_closed'] ?? false);
    }

    /** 'open' | 'closed' | 'unknown' (no hours to judge by). */
    public function state(): string
    {
        return (string) ($this->data['state'] ?? 'unknown');
    }

    /** Today's hours, e.g. "09:00–23:00" (direction-isolated), or null. */
    public function today(): ?string
    {
        return isset($this->data['today']) && is_scalar($this->data['today']) ? (string) $this->data['today'] : null;
    }

    /**
     * Today's weekday in the STORE's timezone: 'mon' … 'sun' (matches
     * `week[].day`). Falls back to the app timezone when the render site did
     * not compute it (e.g. demo data).
     */
    public function today_key(): string
    {
        $key = $this->data['today_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : StorefrontExtras::storeClock(null)['today_key'];
    }

    /** The time right now in the store's timezone, HH:MM (24 h). */
    public function now_time(): string
    {
        $time = $this->data['now_time'] ?? null;

        return is_string($time) && $time !== '' ? $time : StorefrontExtras::storeClock(null)['now_time'];
    }

    /** HH:MM the store closes today, when open. */
    public function closes_at(): ?string
    {
        return isset($this->data['closes_at']) && is_scalar($this->data['closes_at']) ? (string) $this->data['closes_at'] : null;
    }

    /** HH:MM the store next opens, when closed. */
    public function opens_at(): ?string
    {
        return isset($this->data['opens_at']) && is_scalar($this->data['opens_at']) ? (string) $this->data['opens_at'] : null;
    }

    /** The store has a weekly schedule. */
    public function has_hours(): bool
    {
        return (bool) ($this->data['has_hours'] ?? false);
    }

    /**
     * Monday-first rows {day, open, close, closed}; empty without a schedule.
     *
     * @return list<array{day:string,closed:bool,open:?string,close:?string}>
     */
    public function week(): array
    {
        return is_array($this->data['week'] ?? null) ? $this->data['week'] : [];
    }
}
