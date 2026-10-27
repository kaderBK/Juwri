<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Support;

use App\Models\Customer;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * P1-19 (customer passkeys, review fix): when the storefront customer last PROVED who they are in this session.
 *
 * Marked on every interactive customer sign-in (password, phone code, social account, passkey, claim link — the
 * customer guard's Login event), never on a remember-me cookie recall (that cookie is not a fresh proof). The mark
 * survives the session id renewal after sign-in and is dropped with the session on sign-out.
 *
 * Customers without a password (phone-code / social) may add a passkey only with a fresh proof: a sign-in less than
 * {@see self::FRESH_SECONDS} old, or a new phone code ({@see self::markProved()}) — so a stolen session cannot turn
 * itself into persistent access.
 */
final class CustomerSignIn
{
    public const SIGNED_IN_AT = 'customer_auth.signed_in_at';

    public const PROVED_AT = 'customer_auth.proved_at';

    public const FRESH_SECONDS = 600;

    public static function onLogin(Login $event): void
    {
        if ($event->guard !== 'customer' || $event->remember || ! $event->user instanceof Customer) {
            return;
        }
        $request = request();
        if ($request->hasSession()) {
            self::put($request, self::SIGNED_IN_AT, $event->user);
        }
    }

    /** A new proof inside the session (a phone code entered on the account page). */
    public static function markProved(Request $request, Customer $customer): void
    {
        self::put($request, self::PROVED_AT, $customer);
    }

    public static function isFresh(Request $request, Customer $customer, int $seconds = self::FRESH_SECONDS): bool
    {
        foreach ([self::SIGNED_IN_AT, self::PROVED_AT] as $key) {
            $mark = $request->session()->get($key);
            if (is_array($mark)
                && (int) ($mark['customer'] ?? 0) === (int) $customer->id
                && now()->getTimestamp() - (int) ($mark['at'] ?? 0) < $seconds) {
                return true;
            }
        }

        return false;
    }

    private static function put(Request $request, string $key, Customer $customer): void
    {
        $request->session()->put($key, ['customer' => (int) $customer->id, 'at' => now()->getTimestamp()]);
    }
}
