<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Modules\LegacyCompat\Support\LegacyHosts;

/**
 * Plan §3 (R5, Q9): a source store domain row → one `store_domains` row of the SAME store (account and store come from
 * the store being imported, never from the source row). Rules:
 *
 *  - host lower-cased, IDN as punycode, trailing dot / port dropped; it must be a valid host name;
 *  - never verified here: verified_at NULL, ssl_status pending, not primary, whatever the source says. The domain
 *    serves nothing until it passes this install's DNS proof (StoreDomains::verify); the source verification token is
 *    kept when it fits, so a TXT record the owner already published can still prove it. Every written row is listed
 *    in the report's `domains_to_verify`. created_at = import time, so the pending grace window starts at cut-over;
 *  - type: `subdomain` for exactly one label under a configured legacy host or this install's storefront host
 *    (reserved / `www` labels refused), else `custom`;
 *  - refused (row skipped, reported): this install's own hosts or anything under them (other than a storefront
 *    subdomain), a legacy host itself, deeper names under a legacy host, a host or its `www.` / bare twin already on
 *    this install or claimed by another store in this run, an active reseller domain of this install, or a reseller
 *    host of the source.
 */
final class StoreDomainRules
{
    /** store_domains.type values (J28). */
    public const CUSTOM = 'custom';

    public const SUBDOMAIN = 'subdomain';

    /** A source verification token kept as-is: 16–64 token characters (a short / shared value is regenerated). */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9._-]{16,64}$/D';

    /**
     * Did the source mark this domain row verified (`verified` flag or a `verified_at` date)?
     *
     * @param  array<string, mixed>  $row
     */
    public static function sourceVerified(array $row): bool
    {
        if (array_key_exists('verified', $row) && $row['verified'] !== null) {
            return filter_var($row['verified'], FILTER_VALIDATE_BOOLEAN);
        }
        $at = $row['verified_at'] ?? null;

        return $at !== null && $at !== '' && ! (is_string($at) && str_starts_with($at, '0000-00-00'));
    }

    /**
     * @param  array<string, mixed>  $out  the mapped target row (host = the raw source value)
     * @param  array<string, mixed>  $row  the source row
     * @return array<string, mixed>|string the target row, or why it is skipped
     */
    public static function apply(array $out, array $row, ImportContext $ctx): array|string
    {
        $storeId = (int) $ctx->storeId;
        $host = LegacyHosts::normalize((string) ($out['host'] ?? ''));
        if ($host === '') {
            return 'host empty';
        }
        [$type, $problem] = self::classify($host, $ctx, $storeId, self::sourceVerified($row));
        if ($problem !== null) {
            $ctx->report->conflict('store_domains: host refused ('.$problem.')', 'store#'.$storeId.' '.$host);

            return 'host refused: '.$problem;
        }
        $ctx->claimedHosts[self::bare($host)] = $storeId;
        $requested = $ctx->resellerRequestedHosts[self::bare($host)] ?? null;
        if ($requested !== null) {
            $ctx->report->conflict('store_domains: host requested by a reseller, kept by the store the source verified it for', 'requested by reseller#'.$requested.', kept by store#'.$storeId.' '.$host);
        }

        $token = trim((string) ($out['verification_token'] ?? ''));
        $now = now()->utc()->format('Y-m-d H:i:s');
        $out['host'] = $host;
        $out['type'] = $type;
        $out['is_primary'] = false;
        $out['verified_at'] = null;
        $out['ssl_status'] = 'pending';
        // The verifier needs the TXT value to BE the token (bare or prefixed; the source platform's labels and prefixes
        // count for imported rows, hosts.legacy_*). A weak or shared source token could still equal a record the
        // claimant never published: keep it only when the SOURCE proved this row and it is strong.
        $out['verification_token'] = (self::sourceVerified($row) && preg_match(self::TOKEN_PATTERN, $token) === 1)
            ? $token : 'site-verify-'.Str::lower(Str::random(32));
        $out['created_at'] = $now;
        $out['updated_at'] = $now;
        $ctx->report->domainToVerify($storeId, $host, $type);

        return $out;
    }

    /**
     * @return array{0: string, 1: string|null} [type, refusal reason or null]
     */
    public static function classify(string $host, ImportContext $ctx, int $storeId, bool $sourceVerified = false): array
    {
        if (preg_match(LegacyHosts::HOST_PATTERN, $host) !== 1) {
            return [self::CUSTOM, 'not a valid host name'];
        }
        $type = self::CUSTOM;
        // This install's own hosts first, whatever shape they have (an app host one label under the storefront host).
        $bare = self::bare($host);
        $own = HostMap::platformHosts();
        if (in_array($host, $own, true) || in_array($bare, $own, true)) {
            return [$type, 'a host of this install'];
        }

        foreach (LegacyHosts::platformHosts() as $legacy) {
            if ($host === $legacy || $host === 'www.'.$legacy) {
                return [$type, 'the old platform host itself'];
            }
            if (str_ends_with($host, '.'.$legacy)) {
                $label = substr($host, 0, -strlen('.'.$legacy));
                if (str_contains($label, '.') || $label === 'www' || preg_match(LegacyHosts::LABEL_PATTERN, $label) !== 1) {
                    return [$type, 'not a single store label under the old platform host'];
                }
                if (HostMap::isReservedLabel($label)) {
                    return [$type, 'reserved subdomain label'];
                }
                $type = self::SUBDOMAIN;
            }
        }

        if ($type === self::CUSTOM) {
            $label = HostMap::storefrontSubdomainLabel($host);
            if ($label !== null && HostMap::storefrontHost() !== HostMap::appHost()) {
                if ($label === 'www' || HostMap::isReservedLabel($label)) {
                    return [$type, 'reserved subdomain label'];
                }
                $type = self::SUBDOMAIN;
            } else {
                foreach ([...HostMap::platformHosts(), ...HostMap::storefrontAliases()] as $platform) {
                    if ($platform !== '' && ($host === $platform || str_ends_with($host, '.'.$platform))) {
                        return [$type, 'a host of this install'];
                    }
                }
            }
        }

        // Several source stores on one host: only the store the source verified it for may have it (an old
        // squatter's pending row must not block the verified owner); no single such store = nobody gets it.
        $winner = $ctx->domainWinners[$bare] ?? null;
        if ($winner !== null && $winner !== $storeId) {
            return [$type, $winner === 0 ? 'host on several source stores, none (or more than one) verified' : 'host on several source stores, the source-verified store#'.$winner.' wins'];
        }
        if (isset($ctx->claimedHosts[$bare])) {
            return [$type, $ctx->claimedHosts[$bare] === $storeId ? 'duplicate of another domain of the same store' : 'also a domain of store#'.$ctx->claimedHosts[$bare]];
        }
        if (isset($ctx->resellerHosts[$bare])) {
            return [$type, 'a reseller host of the source (reseller#'.$ctx->resellerHosts[$bare].')'];
        }
        if (isset($ctx->resellerRequestedHosts[$bare])) {
            // A reseller's unapproved request never takes a host the source verified for a store; else it is kept for it.
            if (! $sourceVerified) {
                return [$type, 'requested by reseller#'.$ctx->resellerRequestedHosts[$bare].' at the source (not verified for this store)'];
            }
        }
        if ($ctx->target->table('store_domains')->whereIn('host', array_values(array_unique([$host, $bare, 'www.'.$bare])))->exists()) {
            return [$type, 'already on this install'];
        }
        $resellers = app(ResellerContext::class);
        if ($resellers->isActiveCustomDomain($host) || $resellers->isActiveCustomDomain($bare)) {
            return [$type, 'an active reseller domain of this install'];
        }

        return [$type, null];
    }

    public static function bare(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
