<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\Api\V1;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;

/**
 * Marketplace T4-3: OTP login for the driver app (fleet + store drivers).
 *
 * Fleet drivers registered via FleetRegistry never see a raw token (only the
 * SHA-256 hash is stored), so the app signs them in by phone: request an OTP
 * (existing SmsOtpService — hashed code, rate-limited, single use), verify it,
 * then ROTATE the driver token and hand the fresh raw token to the app. This
 * reuses the existing DriverTokenAuth guard unchanged (spec rev/delivery D4:
 * "login exchanges credentials → token; don't rebuild auth").
 */
class DriverAuthController extends Controller
{
    /** POST /api/v1/driver/login/request — send an OTP to a driver's phone. */
    public function requestOtp(Request $request, SmsOtp $otp)
    {
        $data = $request->validate(['phone' => 'required|string|max:32']);

        // Anti-enumeration: only send when a matching active driver exists,
        // but always answer with the same generic message.
        if ($this->findDriver($data['phone'])) {
            $otp->request($data['phone'], OtpScope::driver());
        }

        return response()->json(['ok' => true, 'message' => 'If that phone belongs to a driver, a code was sent.']);
    }

    /** POST /api/v1/driver/login/verify — exchange phone + OTP for a fresh driver token. */
    public function verifyOtp(Request $request, SmsOtp $otp)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'code'  => 'required|string|max:8',
        ]);

        $driver = $this->findDriver($data['phone']);

        if (!$driver || !$otp->verify($data['phone'], $data['code'], OtpScope::driver())) {
            return response()->json(['error' => 'Invalid code.'], 422);
        }

        /* FIX-6 F6-6: don't hand out a token the guarded endpoints will refuse.
           Checked AFTER the OTP so the answer can never be used to enumerate
           drivers — you must already hold a valid code to see this 403. Fleet
           drivers have no merchant, so only the kill veto applies to them
           (mirrors EnsureFeatureEnabled::platformOwned). */
        if (!self::driverAppAllowed($driver)) {
            return response()->json([
                'error'   => 'This feature is not included in your current plan.',
                'feature' => 'driver_app',
            ], 403);
        }

        // Rotate: the raw token exists only in this response (stored hashed).
        $raw = Str::random(48);
        $driver->update(['token' => hash('sha256', $raw)]);

        return response()->json([
            'token'  => $raw,
            'driver' => $driver->toApiArray() + [
                'is_fleet'     => (bool) $driver->is_fleet,
                'shift_status' => $driver->shift_status,
            ],
        ]);
    }

    /**
     * The merchant's `driver_app` kill + entitlement. A platform FLEET driver
     * has no merchant and is outside the SKU entirely (same rule as
     * EnsureFeatureEnabled::platformOwned) — never refuse them here.
     */
    private static function driverAppAllowed(Driver $driver): bool
    {
        if (!$driver->owner_id) {
            return true; // platform fleet
        }

        return \App\Services\FeatureAccess::allows($driver->owner, 'driver_app');
    }

    /** Match an active driver by raw or normalized phone. */
    private function findDriver(string $phone): ?Driver
    {
        $candidates = array_unique(array_filter([
            trim($phone),
            app(Sms::class)->normalizePhone($phone),
        ]));

        if ($candidates === []) {
            return null;
        }

        return Driver::where('active', true)->whereIn('phone', $candidates)->first();
    }
}
