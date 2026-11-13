<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Events;

use Juwri\Core\Models\User;

/**
 * P6-75: an owner/admin's sign-in credentials changed — password reset, password
 * change, two-factor disabled or reset. Modules that hold bearer tokens for the
 * user listen and revoke them (Integrations: owner-app tokens), so a stolen
 * long-lived device token does not survive the very step a victim takes to
 * lock an attacker out. Dispatched synchronously, after the change is saved.
 *
 * $keepAppTokenId: the one owner-app token to spare (the current device, when
 * the user ticked "keep this device signed in" on a settings password change).
 */
class CredentialsChanged
{
    public const PASSWORD_RESET = 'password_reset';

    public const PASSWORD_CHANGED = 'password_changed';

    public const TWO_FACTOR_DISABLED = 'two_factor_disabled';

    public const TWO_FACTOR_RESET = 'two_factor_reset';

    /** BL-036 A1a: the old address undid a sign-in email change (account locked until a reset). */
    public const EMAIL_CHANGE_UNDONE = 'email_change_undone';

    public function __construct(
        public User $user,
        public string $reason,
        public ?int $keepAppTokenId = null,
    ) {}
}
