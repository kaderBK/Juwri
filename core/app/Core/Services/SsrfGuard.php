<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

/**
 * GAP-4: SSRF guard for outbound owner-supplied URLs (webhooks). A URL is safe
 * only when it is https AND every IP its host resolves to is a public address —
 * blocking loopback, private, link-local and reserved ranges (incl. the cloud
 * metadata endpoint 169.254.169.254). Fail-closed: unresolvable host = unsafe.
 */
class SsrfGuard
{
    /**
     * Test seam: when set, replaces DNS lookups for non-literal hosts
     * (fn(string $host): list<string> of IPs). Never set in production code.
     *
     * @var (\Closure(string): array<int, string>)|null
     */
    public static ?\Closure $resolver = null;

    /**
     * P6-68: true when $host (a hostname or IP literal, no scheme) resolves and
     * EVERY address is public. For non-HTTP targets (SMTP) that cannot use the
     * pinned-URL helpers. Fail-closed on resolution failure.
     */
    public static function isPublicHost(string $host): bool
    {
        return self::publicHostIp($host) !== null;
    }

    /**
     * P6-68b: like isPublicHost(), but returns the vetted address to CONNECT to
     * (the first resolved IP) so a non-HTTP client (SMTP) can pin it and close
     * the DNS-rebinding window between check and connect. Null when unsafe.
     */
    public static function publicHostIp(string $host): ?string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]."));
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || (! $isIp && preg_match('/^[a-z0-9.-]+$/', $host) !== 1)) {
            return null;
        }
        $ips = self::resolve($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return (string) $ips[0];
    }

    /**
     * Guzzle `allow_redirects` config that re-validates EVERY redirect hop with
     * isSafePublicUrl, so a validated public host cannot 30x the request onward to an
     * internal address (the classic redirect bypass of a host-only guard). Use as:
     *   Http::withOptions(['allow_redirects' => SsrfGuard::redirectGuard()])->get($url)
     * Pair it with an isSafePublicUrl() check on the INITIAL url.
     *
     * @return array{max: int, strict: bool, referer: bool, protocols: list<string>, on_redirect: \Closure}
     */
    public static function redirectGuard(int $max = 3): array
    {
        return [
            'max' => $max,
            'strict' => true,
            'referer' => false,
            'protocols' => ['https'], // consistent with the https-only isSafePublicUrl
            'on_redirect' => function ($request, $response, $uri) {
                if (! self::isSafePublicUrl((string) $uri)) {
                    throw new \RuntimeException('SSRF: redirect to a non-public host blocked');
                }
            },
        ];
    }

    public static function isSafePublicUrl(string $url): bool
    {
        return self::vet($url) !== null;
    }

    /**
     * F6: the host is resolved ONCE here and the request is pinned to that
     * vetted address, so a hostname with a 0-second TTL cannot answer the check
     * with a public IP and the real request with 169.254.169.254 (DNS
     * rebinding). Returns null when the URL is not a safe public https URL.
     *
     * @return array{host: string, port: int, ip: string}|null
     */
    public static function pin(string $url): ?array
    {
        return self::vet($url);
    }

    /**
     * Guzzle options for an owner-supplied URL: the vetted IP pinned via
     * CURLOPT_RESOLVE, plus the redirect guard (or no redirects at all).
     * Null when the URL is unsafe — callers must not send the request.
     *
     * @return array<string, mixed>|null
     */
    public static function pinnedOptions(string $url, bool $followRedirects = true): ?array
    {
        $pin = self::vet($url);
        if ($pin === null) {
            return null;
        }

        return [
            'curl' => [CURLOPT_RESOLVE => [self::resolveEntry($pin)]],
            'allow_redirects' => $followRedirects ? self::redirectGuard() : false,
        ];
    }

    /**
     * "host:port:ip" for CURLOPT_RESOLVE (IPv6 in brackets).
     *
     * @param  array{host: string, port: int, ip: string}  $pin
     */
    public static function resolveEntry(array $pin): string
    {
        $ip = str_contains($pin['ip'], ':') ? '['.$pin['ip'].']' : $pin['ip'];

        return $pin['host'].':'.$pin['port'].':'.$ip;
    }

    /** @return array{host: string, port: int, ip: string}|null */
    private static function vet(string $url): ?array
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $lower = strtolower(rtrim($host, '.'));
        if ($host === '' || $lower === 'localhost' || str_ends_with($lower, '.localhost')) {
            return null;
        }

        $ips = self::resolve($host);
        if (empty($ips)) {
            return null; // fail-closed: cannot verify → treat as unsafe
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => (int) ($parts['port'] ?? 443), 'ip' => (string) $ips[0]];
    }

    /**
     * The host's IPv4 + IPv6 addresses (an IP literal is itself); [] when it does not resolve.
     *
     * @return array<int, string>
     */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }
        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (! empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * IPv4 ranges that are never a public destination (P6-219: explicit list, not
     * only FILTER_FLAG_NO_PRIV/RES_RANGE, which miss CGNAT 100.64/10, benchmark
     * 198.18/15 and others).
     */
    private const DENY_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** IPv6 ranges inside 2000::/3 that are still not public destinations. */
    private const DENY_V6 = [
        '2001::/23',      // IANA special purpose (Teredo 2001::/32, ORCHID, benchmarking...)
        '2001:db8::/32',  // documentation
        '2002::/16',      // 6to4 (embeds an IPv4; checked below, never trusted as-is)
        '3fff::/20',      // documentation (RFC 9637)
    ];

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::DENY_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }

            return filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return false;
        }

        // Addresses that carry an IPv4 inside: judge the embedded IPv4. A public
        // embedded address is still refused for translation prefixes (NAT64,
        // 6to4) because the gateway sits on the operator's network.
        $embedded = null;
        if (substr($bin, 0, 12) === str_repeat("\0", 10)."\xff\xff"   // ::ffff:0:0/96 mapped
            || substr($bin, 0, 12) === str_repeat("\0", 12)) {            // ::/96 compatible (incl. ::, ::1)
            $embedded = substr($bin, 12, 4);
        }
        if ($embedded !== null) {
            $v4 = (string) inet_ntop($embedded);

            return substr($bin, 0, 12) !== str_repeat("\0", 12) && self::isPublicIp($v4);
        }
        if (self::inCidr($ip, '64:ff9b::/96') || self::inCidr($ip, '64:ff9b:1::/48')) {
            return false; // NAT64
        }

        // Only global unicast 2000::/3 is public; that excludes ::/8, 100::/64,
        // fc00::/7, fe80::/10, fec0::/10, ff00::/8 and every other prefix.
        if ((ord($bin[0]) & 0xE0) !== 0x20) {
            return false;
        }
        foreach (self::DENY_V6 as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
