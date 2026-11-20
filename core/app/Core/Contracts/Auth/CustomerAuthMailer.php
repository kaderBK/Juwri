<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Auth;

use Juwri\Core\Models\User;

/**
 * Extension point: an edition may send the password-reset / e-mail-verification mail of a user itself (a reseller's
 * customer gets the reseller's brand and host). Each method returns true when it sent the mail — the platform mail
 * is then skipped — and false when the user is not its concern (the platform mail goes out unchanged).
 */
interface CustomerAuthMailer
{
    public function passwordReset(User $user, string $token): bool;

    public function verifyEmail(User $user): bool;
}
