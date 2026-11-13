<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * The client "network" used as a rate-limit bucket: an IPv4 address as is, an IPv6 address cut to its /64 (one
 * subscriber usually owns a whole /64, so per-address buckets are free to rotate). Request::ip() only trusts
 * X-Forwarded-For from proxies in config trustedproxy.proxies (none by default).
 */
final class ClientNetwork
{
    public static function bucket(?string $ip = null): string
    {
        $ip ??= (string) request()->ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            // IPv4-mapped IPv6 (::ffff:a.b.c.d, ::ffff:0:0/96) is an IPv4 client: bucket it by that address, or every
            // IPv4 client behind a dual-stack listener would share one /64 bucket.
            if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
                $v4 = inet_ntop(substr($packed, 12));

                return $v4 !== false ? $v4 : $ip;
            }
            if ($packed !== false) {
                return 'v6:'.bin2hex(substr($packed, 0, 8)).'::/64';
            }
        }

        return $ip;
    }
}
