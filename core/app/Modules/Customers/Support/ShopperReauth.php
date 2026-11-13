<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Support;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;

/**
 * P6-91: the proof a shopper gives before downloading or deleting their data — a signed-in session (or app token)
 * alone is never enough. A customer with a password re-enters it; one without a password (phone code / social
 * sign-in) enters a new code sent to their phone. A web customer with neither (social sign-in, no phone) may use a
 * sign-in less than 10 minutes old ({@see CustomerSignIn}).
 */
final class ShopperReauth
{
    /** Null when proved, else the message to show. */
    public static function check(Request $request, Customer $customer, bool $web): ?string
    {
        if (! empty($customer->password)) {
            $password = (string) $request->input('password', '');

            return $password !== '' && Hash::check($password, (string) $customer->password)
                ? null : ___('Enter your password to continue.');
        }
        if (! empty($customer->phone)) {
            $code = trim((string) $request->input('code', ''));

            return $code !== '' && app(SmsOtp::class)->verify((string) $customer->phone, $code, OtpScope::store((int) $customer->store_id))
                ? null : ___('Enter the code we sent to your phone.');
        }
        if ($web && $request->hasSession() && CustomerSignIn::isFresh($request, $customer)) {
            return null;
        }

        return ___('For your security, sign out and sign in again, then retry within 10 minutes.');
    }

    /**
     * Sends a phone code to a customer without a password; [sent, message].
     *
     * @return array{0: bool, 1: string}
     */
    public static function sendCode(Customer $customer): array
    {
        if (! empty($customer->password) || empty($customer->phone)) {
            return [false, ___('Enter your password to continue.')];
        }

        return app(SmsOtp::class)->request((string) $customer->phone, OtpScope::store((int) $customer->store_id));
    }
}
