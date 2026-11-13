<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\StaffMember;
use Juwri\Modules\Auth\Services\TwoFactorService;

/**
 * HPMS E12(b): TOTP enrolment + verification for staff EMAIL logins. Reuses the
 * platform's TwoFactorService (Google2FA) idiom; the secret + recovery codes live
 * encrypted on restaurant_staff. PIN quick-switch never touches this; the owner
 * can reset a staff member's 2FA.
 */
class StaffTwoFactorService
{
    /**
     * Begin enrolment: mint an UNCONFIRMED secret and return the provisioning data
     * (secret + otpauth URI + inline QR). Confirmation activates it.
     *
     * @return array{secret:string,otpauth:string,qr:string}
     */
    public static function enrollStart(StaffMember $staff): array
    {
        $svc = new TwoFactorService;
        $secret = $svc->generateSecret();

        $staff->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        $issuer = (string) (config('settings.site_title') ?: 'juwri');
        $account = $staff->email ?: ('staff-'.$staff->id);

        return [
            'secret' => $secret,
            'otpauth' => $svc->otpauthUrl($issuer, $account, $secret),
            'qr' => $svc->qrCodeSvg($issuer, $account, $secret),
        ];
    }

    /**
     * Confirm enrolment with a code from the authenticator. On success stamps
     * two_factor_confirmed_at and generates recovery codes. Returns the recovery
     * codes on success, or null on a bad code.
     *
     * @return array<int,string>|null
     */
    public static function confirm(StaffMember $staff, string $code): ?array
    {
        $svc = new TwoFactorService;
        if (empty($staff->two_factor_secret) || ! $svc->verifyOnce('staff:'.$staff->getKey(), (string) $staff->two_factor_secret, $code)) {
            return null;
        }

        $codes = $svc->generateRecoveryCodes();
        $staff->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        return $codes;
    }

    /** Verify a login challenge: a valid TOTP OR a single-use recovery code. */
    public static function verify(StaffMember $staff, string $code): bool
    {
        if (! $staff->twoFactorEnabled()) {
            return true; // not enrolled — nothing to challenge
        }

        $svc = new TwoFactorService;
        if ($svc->verifyOnce('staff:'.$staff->getKey(), (string) $staff->two_factor_secret, $code)) {
            return true;
        }

        // Recovery code (single-use, consumed atomically — P6-268).
        return TwoFactorService::consumeRecoveryCode($staff, $code);
    }

    /** Owner reset: strip all 2FA state from a staff member. */
    public static function reset(StaffMember $staff): void
    {
        $staff->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
