<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Juwri\Core\Models\User;

/**
 * Abuse Guard P6 (6.3): per-merchant abuse hold. A held merchant's dashboard writes
 * and AI calls are frozen; their public QR menu stays live (read-only) — the hold
 * NEVER touches public menu routes. Toggled by super-admin from the users page.
 */
class AbuseHold
{
    public static function active(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        return (bool) User::whereKey($userId)->value('abuse_hold');
    }

    public static function set(int $userId, bool $on): void
    {
        User::whereKey($userId)->update(['abuse_hold' => $on]);
    }
}
