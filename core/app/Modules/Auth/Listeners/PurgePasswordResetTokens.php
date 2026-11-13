<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Listeners;

use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Events\CredentialsChanged;

/**
 * P6-267: a reset link already sitting in a compromised mailbox must not outlive
 * the step the victim takes to lock the attacker out. Every outstanding reset
 * token for the user is deleted on any credential change (password change or
 * reset, 2FA disabled/reset) and, via the User saved hook, whenever the password
 * or email column changes by any path (settings, admin reset, import).
 */
class PurgePasswordResetTokens
{
    public function handle(CredentialsChanged $event): void
    {
        User::purgePasswordResetTokens([(string) $event->user->email, (string) $event->user->getOriginal('email')]);
    }
}
