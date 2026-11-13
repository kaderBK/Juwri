<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Pos\Models\PrinterProfile;

/**
 * P6-298: the one place a print-bridge address is judged.
 *
 * The bridge normally listens on the till's loopback or LAN and is reached by the
 * cashier's BROWSER, so loopback and private LAN addresses are valid for the
 * browser transport. What is never valid: a non-http(s) scheme, userinfo, a path,
 * query or fragment, or anything that is not a plain host (CSP injection), and
 * link-local / metadata / multicast / unspecified addresses.
 *
 * Server-side delivery (printing.server_bridge, single-host installs) is stricter:
 * the server may call only the bridge the operator configured (config
 * printing.bridge_url / the loopback default), or a public https host pinned by
 * SsrfGuard. Any other owner-typed private/internal address is refused.
 */
final class BridgeUrl
{
    /** Canonical origin "scheme://host[:port]" or null when the value is not acceptable. */
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 190 || preg_match('/[\s;,\'"\\\\*<>`{}|^]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/')) {
            return null;
        }

        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            if (! self::ipAllowed($bare)) {
                return null;
            }
            $host = str_contains($bare, ':') ? '['.$bare.']' : $bare;
        } elseif (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1
            || preg_match('/^[0-9.]+$/', $host) === 1) { // decimal/octal IP spellings (2852039166, 0251.0376.0251.0376)
            return null;
        }

        $port = '';
        if (isset($parts['port'])) {
            $p = (int) $parts['port'];
            if ($p < 1) { // parse_url already rejects > 65535
                return null;
            }
            $port = ':'.$p;
        }

        return $scheme.'://'.$host.$port;
    }

    /** Loopback, RFC1918/ULA LAN and public addresses; never link-local, metadata, multicast, unspecified, reserved. */
    private static function ipAllowed(string $ip): bool
    {
        if (SsrfGuard::isPublicIp($ip)) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach (['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }

            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        return $bin === inet_pton('::1') || (ord($bin[0]) & 0xFE) === 0xFC; // ::1, fc00::/7 ULA
    }

    /**
     * Guzzle options for SERVER-side delivery to $base, or null when the server must not
     * call it. [] = the operator-configured bridge (trusted, no pin needed).
     *
     * @return array<string, mixed>|null
     */
    public static function serverOptions(string $base): ?array
    {
        $origin = self::normalize($base);
        if ($origin === null) {
            return null;
        }
        $trusted = array_filter([
            self::normalize((string) config('printing.bridge_url')),
            self::normalize(PrinterProfile::DEFAULT_BRIDGE_URL),
        ]);
        if (in_array($origin, $trusted, true)) {
            return [];
        }

        return SsrfGuard::pinnedOptions($origin.'/', false);
    }
}
