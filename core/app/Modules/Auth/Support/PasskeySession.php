<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Support;

use Juwri\Core\Models\User;
use Illuminate\Http\Request;

/**
 * P1-19: "this session was opened with a passkey". A passkey sign-in requires user verification
 * (biometric or device PIN) on a key bound to this site, so it counts as phishing-resistant multi-factor
 * sign-in: the TOTP challenge is skipped, and the force-2FA gates (superadmin, reseller portal) accept it.
 * The marker is tied to the user id, so it never carries over to another account in the same session.
 */
final class PasskeySession
{
    private const KEY = 'auth.passkey_user_id';

    public static function mark(Request $request, User $user): void
    {
        $request->session()->put(self::KEY, (int) $user->id);
    }

    public static function active(mixed $user): bool
    {
        if (! $user instanceof User || ! app()->bound('session')) {
            return false;
        }

        return (int) session(self::KEY, 0) === (int) $user->id;
    }

    /** Two-factor enrolled, or this session was opened with a passkey. */
    public static function hasStrongSignIn(mixed $user): bool
    {
        return $user instanceof User && ($user->hasTwoFactorEnabled() || self::active($user));
    }
}
