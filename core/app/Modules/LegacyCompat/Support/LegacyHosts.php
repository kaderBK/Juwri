<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Modules\LegacyCompat\Import\RowLoader;

/**
 * J52b / plan §3 (Q13 open): the old platform's own hosts on a migrated install (config legacy_compat.hosts, default
 * empty; never a host in code). While the module is on:
 *
 *  - each configured host (and its `www.` twin) is an extra storefront host of the J28 host map: path-form store
 *    pages, no dashboard, its own host-only session cookie, trusted and given a TLS certificate;
 *  - `{label}.<legacy host>` resolves to a store ONLY through a store_domains row the importer wrote for that store
 *    (recorded in legacy_import_rows) with exactly that host. Any other row — e.g. one an owner of this install
 *    requested — never resolves here, so a legacy host can never be pointed at another account's store.
 *
 * Module off (or no host configured): every method answers "none".
 */
final class LegacyHosts
{
    /** Same host syntax rule as the store-domain form (lower-case LDH labels, a TLD). */
    public const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** A store subdomain label (one DNS label). */
    public const LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /** Cache key prefix of the "taken elsewhere" check. */
    private const TAKEN_CACHE = 'legacy_compat:hosts_taken:';

    /** Name of the host sources registered with the J28 host map. */
    public const SOURCE = 'legacy_compat';

    /**
     * The configured legacy hosts: normalised, syntactically valid, never one of this install's own platform hosts
     * (or under one), `www.` twins folded. Empty while the module is off.
     *
     * @return list<string>
     */
    public static function platformHosts(): array
    {
        if (! LegacyCompatRoutes::enabled()) {
            return [];
        }
        $own = HostMap::platformHosts();
        $hosts = [];
        foreach ((array) config('legacy_compat.hosts', []) as $host) {
            $host = self::normalize((string) $host);
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            if ($host === '' || preg_match(self::HOST_PATTERN, $host) !== 1) {
                continue;
            }
            foreach ($own as $platform) {
                if ($platform !== '' && ($host === $platform || str_ends_with($host, '.'.$platform) || str_ends_with($platform, '.'.$host))) {
                    continue 2; // never shadow or swallow this install's own hosts
                }
            }
            $hosts[] = $host;
        }
        $hosts = array_values(array_unique($hosts));
        if ($hosts === []) {
            return [];
        }
        $taken = self::takenElsewhere($hosts);

        return array_values(array_diff($hosts, $taken));
    }

    /**
     * Configured legacy hosts that are already a store's verified custom domain or an active reseller domain here:
     * refused (that owner keeps the host) and logged. Cached briefly: this runs on every host-map lookup.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private static function takenElsewhere(array $hosts): array
    {
        try {
            /** @var list<string> $taken */
            $taken = Cache::remember(self::TAKEN_CACHE.implode(',', $hosts), 60, static function () use ($hosts): array {
                $taken = [];
                $twins = [];
                foreach ($hosts as $host) {
                    $twins[$host] = $host;
                    $twins['www.'.$host] = $host;
                }
                if (Schema::hasTable('store_domains')) {
                    foreach (DB::table('store_domains')->whereIn('host', array_keys($twins))->where('type', 'custom')->whereNotNull('verified_at')->pluck('host') as $h) {
                        $taken[] = $twins[(string) $h];
                    }
                }
                $resellers = app(ResellerContext::class);
                foreach ($hosts as $host) {
                    if ($resellers->isActiveCustomDomain($host) || $resellers->isActiveCustomDomain('www.'.$host)) {
                        $taken[] = $host;
                    }
                }
                $taken = array_values(array_unique($taken));
                foreach ($taken as $host) {
                    Log::warning('legacy_compat.hosts: host refused, it is a verified store or reseller domain on this install', ['host' => $host]);
                }

                return $taken;
            });

            return $taken;
        } catch (\Throwable) {
            return []; // no database yet (install, migrate)
        }
    }

    /**
     * Same normal form as store_domains.host (J28): lower-case, trimmed, no trailing dot, no port, IDN labels as
     * punycode when intl is available.
     */
    public static function normalize(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = explode(':', $host, 2)[0];
        }
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    /** Is $host a configured legacy host or its `www.` twin? */
    public static function isPlatformHost(string $host): bool
    {
        $host = self::normalize($host);
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return $bare !== '' && in_array($bare, self::platformHosts(), true);
    }

    /**
     * [legacy host, label] when $host is exactly one label under a configured legacy host, else null. `www` is the
     * host's own twin, never a store label.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function subdomainOf(string $host): ?array
    {
        $host = self::normalize($host);
        foreach (self::platformHosts() as $legacy) {
            if (! str_ends_with($host, '.'.$legacy)) {
                continue;
            }
            $label = substr($host, 0, -strlen('.'.$legacy));
            if ($label !== 'www' && preg_match(self::LABEL_PATTERN, $label) === 1) {
                return [$legacy, $label];
            }

            return null;
        }

        return null;
    }

    /**
     * The store_domains row id answering on $host when $host is a store subdomain of a legacy host AND the row was
     * written by the importer (its store and account come from the imported store, never from a request). Else null.
     */
    public static function importedStoreDomainId(string $host): ?int
    {
        if (self::subdomainOf($host) === null) {
            return null;
        }
        $host = self::normalize($host);
        if (! Schema::hasTable(RowLoader::SIDE_TABLE)) {
            return null;
        }
        $id = DB::table('store_domains')->where('host', $host)->value('id');
        if ($id === null) {
            return null;
        }
        $imported = DB::table(RowLoader::SIDE_TABLE)
            ->where('target_table', 'store_domains')->where('target_id', (string) $id)->exists();

        return $imported ? (int) $id : null;
    }

    /**
     * Pending grace (hours) of an imported, still unverified store domain: config
     * legacy_compat.import.domain_grace_hours (default 90 days), so another store cannot take the host over before
     * the owner has switched DNS after cut-over. Null for any other row, or while the module is off.
     */
    public static function importedGraceHours(int $id): ?int
    {
        if (! LegacyCompatRoutes::enabled() || ! Schema::hasTable(RowLoader::SIDE_TABLE)) {
            return null;
        }
        $hours = (int) config('legacy_compat.import.domain_grace_hours', 2160);
        if ($hours <= 0) {
            return null;
        }
        $imported = DB::table(RowLoader::SIDE_TABLE)->where('target_table', 'store_domains')->where('target_id', (string) $id)->exists();

        return $imported ? $hours : null;
    }

    /** Remove this module's sources from the J28 host map (every provider boot, before a possible activation). */
    public static function unregister(): void
    {
        HostMap::forgetModuleSources(self::SOURCE);
    }

    /** Wire the hosts into the J28 host map (module activation). The sources re-check the switch on every call. */
    public static function register(): void
    {
        HostMap::addStorefrontAliases(self::SOURCE, static fn (): array => self::platformHosts());
        HostMap::addStoreHostResolver(self::SOURCE, static fn (string $host): ?int => self::importedStoreDomainId($host));
        HostMap::addPendingGrace(self::SOURCE, static fn (int $id): ?int => self::importedGraceHours($id));
        HostMap::addImportedDomain(self::SOURCE, static fn (int $id): bool => self::isImportedDomain($id));
    }

    /** BL-036 R2: was store_domains row $id written by the import (its row in the import side table)? */
    public static function isImportedDomain(int $id): bool
    {
        if (! LegacyCompatRoutes::enabled() || ! Schema::hasTable(RowLoader::SIDE_TABLE)) {
            return false;
        }

        return DB::table(RowLoader::SIDE_TABLE)->where('target_table', 'store_domains')->where('target_id', (string) $id)->exists();
    }
}
