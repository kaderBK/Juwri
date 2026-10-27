<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services;

use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Modules\Storefront\Models\StoreDomain;

/**
 * #85: verifies a custom domain is pointed at the platform. Extracted so the
 * DNS lookup can be faked in tests (bind an instance in the container).
 *
 * Passes when a TXT record's value is EXACTLY the verification token — bare, or as "{@see TXT_PREFIX}<token>" —
 * on the domain itself or on the "{@see CHALLENGE_LABEL}.<domain>" challenge name (a name that already carries a
 * CNAME cannot also hold a TXT, so the challenge name is the usual place for it). BL-036 R2: a TXT that merely
 * CONTAINS the token no longer matches. With hosts.domain_verification = cname_or_txt, a CNAME that targets the
 * platform host exactly also passes (J28).
 */
class DomainVerifier
{
    /** DNS label a TXT challenge may live under: <label>.<domain>. */
    public const CHALLENGE_LABEL = '_juwri-verify';

    /** Prefix the TXT value may carry: "<prefix><token>". */
    public const TXT_PREFIX = 'juwri-verify=';

    /**
     * @param  bool  $imported  the claim is a store_domains row imported from another platform: that platform's
     *                          challenge labels / value prefixes (hosts.legacy_*) are accepted too
     */
    public function verify(string $domain, string $token, bool $imported = false): bool
    {
        $domain = StoreDomain::normalizeHost($domain);
        $token = trim($token);
        if ($domain === '' || $token === '') {
            return false;
        }

        // TXT carries this claimant's own token: proves ownership AND who claims the host.
        if ($this->hasExactToken($domain, $token, $imported)) {
            return true;
        }

        // P1-20 (J28): a CNAME alone only proves the DNS points at the platform, not which store owns the
        // host — a dangling CNAME left by a previous store would let any store claim it. Accepted only when
        // hosts.domain_verification = cname_or_txt, and only for an exact platform host (the old substring
        // test also matched e.g. "juwri.store.attacker.net").
        if (config('hosts.domain_verification', 'txt') !== 'cname_or_txt') {
            return false;
        }
        $targets = array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            HostMap::storefrontHost(),
        ]);
        foreach ($this->records($domain, DNS_CNAME) as $rec) {
            $target = strtolower(rtrim((string) ($rec['target'] ?? ''), '.'));
            if ($target !== '' && in_array($target, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * P6-241: does $domain still point at the platform? True on this store's TXT token, a CNAME to a platform host,
     * or an A/AAAA record shared with the storefront host. Null when the platform host itself does not resolve (the
     * resolver is down) — the caller must not count that as a failure.
     */
    public function stillPointed(string $domain, string $token): ?bool
    {
        $domain = strtolower(trim($domain));
        $platform = HostMap::storefrontHost();
        $ours = $this->addresses($platform);
        if ($ours === []) {
            return null;
        }
        if ($token !== '' && array_any($this->records($domain, DNS_TXT), fn ($rec) => ! empty($rec['txt']) && str_contains((string) $rec['txt'], $token))) {
            return true;
        }
        $targets = array_filter([strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)), $platform]);
        foreach ($this->records($domain, DNS_CNAME) as $rec) {
            if (in_array(strtolower(rtrim((string) ($rec['target'] ?? ''), '.')), $targets, true)) {
                return true;
            }
        }

        return array_intersect($this->addresses($domain), $ours) !== [];
    }

    /**
     * BL-036 R2: a TXT value equal to the token (bare or prefixed) on the domain or its challenge name. For an imported
     * row, the source platform's labels and prefixes (config hosts.legacy_verify_labels / legacy_txt_prefixes) count too.
     */
    private function hasExactToken(string $domain, string $token, bool $imported = false): bool
    {
        $prefixes = [self::TXT_PREFIX];
        $labels = [self::CHALLENGE_LABEL];
        if ($imported) {
            $prefixes = [...$prefixes, ...self::configList('hosts.legacy_txt_prefixes')];
            $labels = [...$labels, ...self::configList('hosts.legacy_verify_labels')];
        }
        $expected = [$token, ...array_map(fn (string $p): string => $p.$token, $prefixes)];
        $names = [$domain, ...array_map(fn (string $l): string => rtrim($l, '.').'.'.$domain, $labels)];
        foreach (array_unique($names) as $name) {
            foreach ($this->records($name, DNS_TXT) as $rec) {
                // A long TXT is split into strings ("entries"); its value is their concatenation.
                $value = isset($rec['entries']) && is_array($rec['entries'])
                    ? implode('', array_map('strval', $rec['entries']))
                    : (string) ($rec['txt'] ?? '');
                if (in_array(trim($value, " \t\n\r\0\x0B\""), $expected, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> non-empty entries of a list config (an array, or a comma string) */
    private static function configList(string $key): array
    {
        $value = config($key, []);
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($v): string => trim((string) $v), $items), fn (string $v): bool => $v !== ''));
    }

    /** @return list<string> the A / AAAA addresses of $host */
    protected function addresses(string $host): array
    {
        $out = [];
        foreach ($this->records($host, DNS_A) as $rec) {
            $out[] = (string) ($rec['ip'] ?? '');
        }
        foreach ($this->records($host, DNS_AAAA) as $rec) {
            $out[] = strtolower((string) ($rec['ipv6'] ?? ''));
        }

        return array_values(array_filter($out));
    }

    /** @return list<array<string, mixed>> */
    protected function records(string $domain, int $type): array
    {
        return @dns_get_record($domain, $type) ?: [];
    }
}
