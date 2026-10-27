<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Juwri\Core\Settings\UserSettings;

/**
 * P1-07 compatibility shim: the historical `UserOption::getUserOption / updateUserOption` API (and the
 * `user_options()` helper) over the typed per-user storage. The `user_options` table and this model's
 * Eloquent side are gone (slice 5e): preferences live in `users.preferences`, the saved login defaults in
 * `users.default_store_id` / `default_branch_id` and invoice details in `accounts.billing_profile`
 * (see Juwri\Core\Settings\UserSettings). New code uses UserSettings directly.
 */
class UserOption
{
    /**
     * One account setting. A stored value wins, even when empty; otherwise the default, and false when
     * the default is empty too.
     */
    public static function getUserOption($userId, $key, $default = null)
    {
        $value = UserSettings::legacy((int) $userId, (string) $key);
        if ($value !== null) {
            return $value;
        }

        return empty($default) ? false : $default;
    }

    /** Insert or overwrite one account setting; returns the save result. */
    public static function updateUserOption($userId, $key, $value)
    {
        return UserSettings::set((int) $userId, (string) $key, $value);
    }
}
