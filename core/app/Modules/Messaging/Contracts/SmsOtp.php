<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Services\SmsOtpService;

/** P1-08 L1 public surface: phone one-time codes. Implemented by {@see SmsOtpService}. */
interface SmsOtp
{
    /**
     * Generate + send a code for this scope (rate limit, resend cooldown and attempt cap included, all per scope).
     * The SMS is sent from the scope's store account ({@see OtpScope::$billingStoreId}; null = platform).
     *
     * @return array{0:bool,1:string,2?:int} [ok, message, seconds to wait when the request was rate limited]
     */
    public function request(string $phone, OtpScope $scope): array;

    /** Verify a submitted code against a code issued for the SAME scope; consumes it on success. */
    public function verify(string $phone, string $code, OtpScope $scope): bool;
}
