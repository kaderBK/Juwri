<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

declare(strict_types=1);

namespace Juwri\Modules\Auth\Data;

use Juwri\Core\Models\User;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;

/**
 * R7b takeover guard shared by the forgot-password flow and the reseller
 * customer-control screen: decides whether a password reset link may go to a
 * user's current address after a reseller changed it.
 */
final class ResellerEmailGuard
{
    /**
     * True when no reset link may go to $user's current address: a reseller changed
     * the sign-in email less than RESELLER_RESET_PAUSE_SECONDS ago, or a reseller
     * set the address and it is still unverified.
     */
    public static function resetWithheld(User $user): bool
    {
        $changedAt = (int) (UserSettings::legacy((int) $user->id, EmailChangeUndos::RESELLER_EMAIL_CHANGED_OPTION) ?? 0);
        if ($changedAt <= 0) {
            return false;
        }
        if (now()->getTimestamp() < $changedAt + EmailChangeUndos::RESELLER_RESET_PAUSE_SECONDS) {
            return true;
        }
        if ($user->email_verified_at !== null) {
            return false;
        }
        $setHash = (string) (UserSettings::legacy((int) $user->id, EmailChangeUndos::RESELLER_EMAIL_SET_OPTION) ?? '');

        return $setHash !== '' && hash_equals($setHash, hash('sha256', mb_strtolower(trim((string) $user->email))));
    }
}
