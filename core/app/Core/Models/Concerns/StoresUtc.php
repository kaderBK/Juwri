<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

use Illuminate\Support\Facades\Date;

/**
 * P3-11: a model whose datetime columns hold UTC wall-clock times, whatever the application timezone is.
 *
 * Laravel reads a stored `Y-m-d H:i:s` as a time in the application's default timezone and writes a Carbon as its own
 * wall-clock. The platform timezone setting (SettingsServiceProvider) makes that default something other than UTC, so
 * a model that stores instants as UTC (`dateTimeTz` columns of the Bookings and Laundry tables) would read them back
 * shifted by the offset. This trait reads the stored value as UTC and writes every value as UTC; the attribute is a
 * Carbon for the same instant, shown in the application timezone like any other.
 *
 * Query bindings are not converted by a model: bind `now('UTC')` (or any Carbon in UTC) in a `where` / `update`.
 */
trait StoresUtc
{
    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $value) === 1) {
            return Date::parse(str_replace('T', ' ', substr($value, 0, 19)), 'UTC')->setTimezone((string) config('app.timezone', 'UTC'));
        }

        return parent::asDateTime($value);
    }

    public function fromDateTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = $this->asDateTime($value);

        return $at->copy()->utc()->format($this->getDateFormat());
    }
}
