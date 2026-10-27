<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;

/**
 * One-time-password over SMS for customer login (#0 + #104).
 *
 *  - 6-digit code, hashed in cache (never stored in clear), 10-minute expiry.
 *  - Request rate-limited to 3 per scope + phone per 10 minutes.
 *  - Max 5 verify attempts before the code is invalidated.
 *  - Everything is keyed by {@see OtpScope} + phone: a code sent for store A never verifies at store B, the
 *    marketplace or the driver app, and requests at A never throttle B. On top, a per-phone ceiling across all
 *    scopes (10 per 10 minutes) stops SMS bombing one phone by rotating stores.
 *  - Codes cached before scoping (key `sms_otp:{phone}`) are simply never read again and expire with their TTL.
 */
class SmsOtpService implements SmsOtp
{
    private const TTL = 600; // 10 minutes

    private const MAX_ATTEMPTS = 5;

    private const RL_MAX = 3;

    private const RL_DECAY = 600;

    private const RL_PHONE_MAX = 10; // all scopes together, per RL_DECAY

    public function __construct(protected SmsService $sms) {}

    /**
     * Generate + send an OTP. Returns [ok, message]. On rate-limit, ok=false.
     *
     * @return array{0:bool,1:string,2?:int}
     */
    public function request(string $phone, OtpScope $scope): array
    {
        $phone = $this->sms->normalizePhone($phone);
        if ($phone === '') {
            return [false, ___('Enter a valid phone number.')];
        }

        $rlKey = 'otp-req:'.$scope->key.':'.$phone;
        $phoneKey = 'otp-req-phone:'.$phone;
        foreach ([$rlKey => self::RL_MAX, $phoneKey => self::RL_PHONE_MAX] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $secs = RateLimiter::availableIn($key);

                // P3-06b: the wait in seconds as a third element, for an API answer's Retry-After.
                return [false, ___('Too many requests. Try again in :secs s.', ['secs' => $secs]), max(1, (int) $secs)];
            }
        }
        RateLimiter::hit($rlKey, self::RL_DECAY);
        RateLimiter::hit($phoneKey, self::RL_DECAY);

        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($scope, $phone), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], self::TTL);

        $ok = $this->sms->send($phone, ___('Your verification code is :code. It expires in 10 minutes.', ['code' => $code]), $scope->billingStoreId, 'otp', false);

        return $ok
            ? [true, ___('We sent you a verification code.')]
            : [false, ___('Could not send the code. Please try again.')];
    }

    /**
     * Verify a submitted code. Consumes it on success.
     */
    public function verify(string $phone, string $code, OtpScope $scope): bool
    {
        $phone = $this->sms->normalizePhone($phone);
        if ($phone === '') {
            return false;
        }
        $key = $this->key($scope, $phone);
        $data = Cache::get($key);

        if (! $data || ! isset($data['hash'])) {
            return false;
        }

        if (($data['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return false;
        }

        if (Hash::check($code, $data['hash'])) {
            Cache::forget($key); // single use

            return true;
        }

        $data['attempts'] = ($data['attempts'] ?? 0) + 1;
        Cache::put($key, $data, self::TTL);

        return false;
    }

    /** Cache key of the code for this scope + (normalized) phone. */
    public function key(OtpScope $scope, string $phone): string
    {
        return 'sms_otp:'.$scope->key.':'.$phone;
    }
}
